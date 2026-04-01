<?php

namespace App\Services\Forecast;

use App\Models\OpenPurchaseOrder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class ProductionScheduleService
{
    public function __construct(
        private BomExplosionService $bomExplosion,
    ) {}

    /**
     * Check freshness of the latest stock snapshot.
     *
     * @return array{latest_at: CarbonImmutable|null, age_hours: float|null, is_stale: bool}
     */
    public function stockFreshness(int $staleThresholdHours = 48): array
    {
        $row = DB::selectOne('SELECT MAX(recorded_at) as latest FROM product_stock_snapshots');
        $latestAt = $row?->latest ? CarbonImmutable::parse($row->latest) : null;

        if (! $latestAt) {
            return ['latest_at' => null, 'age_hours' => null, 'is_stale' => true];
        }

        $ageHours = $latestAt->diffInMinutes(now()) / 60;

        return [
            'latest_at' => $latestAt,
            'age_hours' => round($ageHours, 1),
            'is_stale' => $ageHours > $staleThresholdHours,
        ];
    }

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
     * Net component demand against current stock and open purchase orders.
     *
     * @param  array<int, array{product_id: int, sku: string, name: string, total_quantity: float, procurement_lt: int|null}>  $componentDemand
     * @return array<int, array{product_id: int, sku: string, name: string, gross_need: float, stock_available: float, open_po_qty: float, net_need: float}>
     */
    public function netComponentDemand(array $componentDemand): array
    {
        $currentStock = $this->getCurrentStockByProduct();
        $openPoQty = $this->getOpenPoQtyByProduct();

        $result = [];

        foreach ($componentDemand as $component) {
            $pid = $component['product_id'];
            $stock = $currentStock[$pid] ?? 0;
            $openPo = $openPoQty[$pid] ?? 0;
            $grossNeed = $component['total_quantity'];
            $netNeed = max(0, $grossNeed - $stock - $openPo);

            $result[] = [
                'product_id' => $pid,
                'sku' => $component['sku'],
                'name' => $component['name'],
                'gross_need' => $grossNeed,
                'stock_available' => $stock,
                'open_po_qty' => $openPo,
                'net_need' => $netNeed,
                'procurement_lt' => $component['procurement_lt'],
            ];
        }

        return $result;
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
        $netted = $this->netComponentDemand(array_values($aggregated));

        $currentStock = $this->getCurrentStockByProduct();
        $openPoQty = $this->getOpenPoQtyByProduct();
        $openPoDetails = $this->getOpenPoDetailsByProduct();

        // Generate purchase events for net needs
        foreach ($netted as $comp) {
            if ($comp['net_need'] <= 0) {
                continue;
            }

            $lt = (int) ($comp['procurement_lt'] ?? 0);
            $orderDate = date('Y-m-d', strtotime("{$needDate} -{$lt} days"));
            $supplier = $this->getSupplierForProduct($comp['product_id']);

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
                'note' => $this->buildNettingNote($comp),
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

        // Generate production events for intermediate products (backwards from needDate)
        foreach ($intermediates as $intermediate) {
            $assemblyDays = (int) ceil($intermediate['assembly_days']);
            $productionStart = date('Y-m-d', strtotime("{$needDate} -{$assemblyDays} days"));

            $events[] = [
                'date' => $productionStart,
                'event_type' => 'production_start',
                'product_id' => $intermediate['product_id'],
                'sku' => $intermediate['sku'],
                'name' => $intermediate['name'],
                'quantity' => ceil($intermediate['quantity']),
                'gross_quantity' => ceil($intermediate['quantity']),
                'net_quantity' => ceil($intermediate['quantity']),
                'supplier' => null,
                'note' => "Assembly {$intermediate['assembly_days']}d → ready by {$needDate}",
            ];

            $events[] = [
                'date' => $needDate,
                'event_type' => 'production_done',
                'product_id' => $intermediate['product_id'],
                'sku' => $intermediate['sku'],
                'name' => $intermediate['name'],
                'quantity' => ceil($intermediate['quantity']),
                'gross_quantity' => ceil($intermediate['quantity']),
                'net_quantity' => ceil($intermediate['quantity']),
                'supplier' => null,
                'note' => 'Assembly complete',
            ];
        }
    }

    private function buildNettingNote(array $comp): string
    {
        $parts = ["Need {$comp['gross_need']}"];

        if ($comp['stock_available'] > 0) {
            $parts[] = "stock {$comp['stock_available']}";
        }
        if ($comp['open_po_qty'] > 0) {
            $parts[] = "open PO {$comp['open_po_qty']}";
        }

        $parts[] = "→ order {$comp['net_need']}";

        return implode(', ', $parts);
    }

    /**
     * @return array<int, float>
     */
    private function getCurrentStockByProduct(): array
    {
        static $cache = null;

        if ($cache !== null) {
            return $cache;
        }

        $rows = DB::select('
            SELECT pss.product_id, pss.qty_free
            FROM product_stock_snapshots pss
            INNER JOIN (
                SELECT product_id, MAX(recorded_at) as max_date
                FROM product_stock_snapshots
                GROUP BY product_id
            ) latest ON latest.product_id = pss.product_id AND latest.max_date = pss.recorded_at
        ');

        $cache = [];
        foreach ($rows as $row) {
            $cache[(int) $row->product_id] = (float) $row->qty_free;
        }

        return $cache;
    }

    /**
     * @return array<int, float>
     */
    private function getOpenPoQtyByProduct(): array
    {
        static $cache = null;

        if ($cache !== null) {
            return $cache;
        }

        $rows = OpenPurchaseOrder::query()
            ->whereNotNull('product_id')
            ->selectRaw('product_id, SUM(quantity_open) as total_open')
            ->groupBy('product_id')
            ->get();

        $cache = [];
        foreach ($rows as $row) {
            $cache[(int) $row->product_id] = (float) $row->total_open;
        }

        return $cache;
    }

    /**
     * @return array<int, array<int, OpenPurchaseOrder>>
     */
    private function getOpenPoDetailsByProduct(): array
    {
        static $cache = null;

        if ($cache !== null) {
            return $cache;
        }

        $cache = OpenPurchaseOrder::query()
            ->whereNotNull('product_id')
            ->orderBy('date_planned')
            ->get()
            ->groupBy('product_id')
            ->map(fn ($pos) => $pos->all())
            ->all();

        return $cache;
    }

    private function getSupplierForProduct(int $productId): string
    {
        $latestPo = OpenPurchaseOrder::where('product_id', $productId)
            ->orderByDesc('date_order')
            ->first();

        return $latestPo?->supplier_name ?? 'Unknown';
    }
}
