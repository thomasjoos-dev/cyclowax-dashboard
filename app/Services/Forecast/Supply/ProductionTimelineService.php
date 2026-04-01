<?php

namespace App\Services\Forecast\Supply;

class ProductionTimelineService
{
    public function __construct(
        private BomExplosionService $bomExplosion,
        private ComponentNettingService $netting,
    ) {}

    /**
     * Generate a full purchase + production timeline for given SKU quantities.
     *
     * Combines BOM explosion, netting against stock + open POs,
     * and backwards scheduling into a single chronological timeline.
     *
     * @param  array<int, int>  $skuQuantities  [product_id => quantity needed]
     * @param  string  $needDate  When finished products must be ready (Y-m-d)
     * @return array<int, array{date: string, event_type: string, product_id: int, sku: string, name: string, quantity: float, gross_quantity: float, net_quantity: float, supplier: string|null, note: string}>
     */
    public function timeline(array $skuQuantities, string $needDate): array
    {
        $events = [];

        foreach ($skuQuantities as $productId => $quantity) {
            if ($quantity <= 0) {
                continue;
            }

            $this->buildProductTimeline($productId, (float) $quantity, $needDate, $events);
        }

        usort($events, function (array $a, array $b) {
            $dateCmp = $a['date'] <=> $b['date'];
            if ($dateCmp !== 0) {
                return $dateCmp;
            }

            // Within same date: purchases → receipts → production_start → production_done → available
            $order = ['purchase' => 0, 'receipt' => 1, 'production_start' => 2, 'production_done' => 3, 'available' => 4];

            return ($order[$a['event_type']] ?? 5) <=> ($order[$b['event_type']] ?? 5);
        });

        return $events;
    }

    /**
     * Build timeline events for a single finished product.
     *
     * @param  array<int, array{date: string, event_type: string, product_id: int, sku: string, name: string, quantity: float, gross_quantity: float, net_quantity: float, supplier: string|null, note: string}>  $events
     */
    private function buildProductTimeline(int $productId, float $quantity, string $needDate, array &$events): void
    {
        // Get all intermediate products that need production
        $intermediates = $this->bomExplosion->intermediateProducts($productId, $quantity);

        // Get leaf component demand
        $components = $this->bomExplosion->explode($productId, $quantity);

        // Aggregate components
        $aggregated = [];
        foreach ($components as $comp) {
            $pid = $comp['product_id'];
            if (! isset($aggregated[$pid])) {
                $aggregated[$pid] = $comp;
                $aggregated[$pid]['total_quantity'] = 0;
            }
            $aggregated[$pid]['total_quantity'] += $comp['quantity'];
        }

        // Net against stock and open POs
        $netted = $this->netting->net(array_values($aggregated));

        // Generate purchase events for net needs
        foreach ($netted as $comp) {
            if ($comp['net_need'] <= 0) {
                continue;
            }

            $lt = (int) ($comp['procurement_lt'] ?? 0);
            $orderDate = date('Y-m-d', strtotime("{$needDate} -{$lt} days"));
            $supplier = $this->netting->getSupplierForProduct($comp['product_id']);

            $events[] = [
                'date' => $orderDate,
                'event_type' => 'purchase',
                'product_id' => $comp['product_id'],
                'sku' => $comp['sku'],
                'name' => $comp['name'],
                'quantity' => ceil($comp['net_need']),
                'gross_quantity' => ceil($comp['gross_need']),
                'net_quantity' => ceil($comp['net_need']),
                'supplier' => $supplier,
                'note' => $this->netting->buildNettingNote($comp),
            ];

            $events[] = [
                'date' => date('Y-m-d', strtotime("{$orderDate} +{$lt} days")),
                'event_type' => 'receipt',
                'product_id' => $comp['product_id'],
                'sku' => $comp['sku'],
                'name' => $comp['name'],
                'quantity' => ceil($comp['net_need']),
                'gross_quantity' => ceil($comp['gross_need']),
                'net_quantity' => ceil($comp['net_need']),
                'supplier' => $supplier,
                'note' => "Expected delivery from {$supplier}",
            ];
        }

        // Net intermediate products against stock before generating production events
        $nettedIntermediates = $this->netting->netIntermediateDemand($intermediates);

        foreach ($nettedIntermediates as $intermediate) {
            if ($intermediate['net_quantity'] <= 0) {
                continue;
            }

            $assemblyDays = (int) ceil($intermediate['assembly_days']);
            $productionStart = date('Y-m-d', strtotime("{$needDate} -{$assemblyDays} days"));

            $stockNote = $intermediate['stock_available'] > 0
                ? "Need {$intermediate['gross_quantity']}, stock {$intermediate['stock_available']}, produce {$intermediate['net_quantity']}"
                : "Assembly {$intermediate['assembly_days']}d → ready by {$needDate}";

            $events[] = [
                'date' => $productionStart,
                'event_type' => 'production_start',
                'product_id' => $intermediate['product_id'],
                'sku' => $intermediate['sku'],
                'name' => $intermediate['name'],
                'quantity' => ceil($intermediate['net_quantity']),
                'gross_quantity' => ceil($intermediate['gross_quantity']),
                'net_quantity' => ceil($intermediate['net_quantity']),
                'supplier' => null,
                'note' => $stockNote,
            ];

            $events[] = [
                'date' => $needDate,
                'event_type' => 'production_done',
                'product_id' => $intermediate['product_id'],
                'sku' => $intermediate['sku'],
                'name' => $intermediate['name'],
                'quantity' => ceil($intermediate['net_quantity']),
                'gross_quantity' => ceil($intermediate['gross_quantity']),
                'net_quantity' => ceil($intermediate['net_quantity']),
                'supplier' => null,
                'note' => 'Assembly complete',
            ];
        }
    }
}
