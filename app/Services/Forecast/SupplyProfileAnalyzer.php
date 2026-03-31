<?php

namespace App\Services\Forecast;

use App\Enums\ProductCategory;
use App\Models\SupplyProfile;
use App\Services\Api\OdooClient;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SupplyProfileAnalyzer
{
    public function __construct(
        private OdooClient $odoo,
    ) {}

    /**
     * Analyze purchase order history from Odoo and return supply chain metrics per product category.
     *
     * @return array<string, array{procurement_lead_time_days: int|null, moq: int|null, order_frequency_days: int|null, sample_size: int, suppliers: array<string>}>
     */
    public function analyze(): array
    {
        $poLines = $this->fetchPurchaseOrderLines();
        $receiptDates = $this->fetchReceiptDates();
        $productCategoryMap = $this->buildProductCategoryMap();

        // Group PO lines by product category
        $byCategory = collect($poLines)
            ->filter(fn (array $line) => $line['product_id'] !== false)
            ->map(function (array $line) use ($productCategoryMap, $receiptDates) {
                $odooProductId = $line['product_id'][0];
                $category = $productCategoryMap[$odooProductId] ?? null;

                if (! $category) {
                    return null;
                }

                $orderDate = $line['date_order'] ? substr($line['date_order'], 0, 10) : null;
                $poReference = $line['order_name'] ?? null;
                $receiptDate = $poReference ? ($receiptDates[$poReference] ?? null) : null;

                return [
                    'category' => $category,
                    'product_qty' => (float) $line['product_qty'],
                    'qty_received' => (float) $line['qty_received'],
                    'order_date' => $orderDate,
                    'receipt_date' => $receiptDate,
                    'planned_date' => $line['date_planned'] ? substr($line['date_planned'], 0, 10) : null,
                    'supplier' => $line['partner_id'][1] ?? 'Unknown',
                ];
            })
            ->filter()
            ->groupBy('category');

        $results = [];

        foreach ($byCategory as $categoryValue => $lines) {
            $results[$categoryValue] = [
                'procurement_lead_time_days' => $this->calculateLeadTime($lines),
                'moq' => $this->calculateMoq($lines),
                'order_frequency_days' => $this->calculateOrderFrequency($lines),
                'sample_size' => $lines->count(),
                'suppliers' => $lines->pluck('supplier')->unique()->sort()->values()->all(),
            ];
        }

        return $results;
    }

    /**
     * Update SupplyProfile records with analyzed data.
     *
     * @return array<string, array{field: string, old: mixed, new: mixed}>
     */
    public function updateProfiles(array $analysis): array
    {
        $changes = [];

        foreach ($analysis as $categoryValue => $metrics) {
            $profile = SupplyProfile::where('product_category', $categoryValue)->first();

            if (! $profile) {
                // Create new profile from analysis
                if ($metrics['procurement_lead_time_days'] !== null) {
                    SupplyProfile::create([
                        'product_category' => $categoryValue,
                        'procurement_lead_time_days' => $metrics['procurement_lead_time_days'],
                        'moq' => $metrics['moq'] ?? 50,
                        'buffer_days' => 14,
                        'supplier_name' => implode(', ', array_slice($metrics['suppliers'], 0, 3)),
                    ]);
                    $changes[$categoryValue] = ['action' => 'created'];
                }

                continue;
            }

            $updates = [];

            if ($metrics['procurement_lead_time_days'] !== null) {
                $updates['procurement_lead_time_days'] = $metrics['procurement_lead_time_days'];
            }
            if ($metrics['moq'] !== null) {
                $updates['moq'] = $metrics['moq'];
            }
            if (! empty($metrics['suppliers'])) {
                $updates['supplier_name'] = implode(', ', array_slice($metrics['suppliers'], 0, 3));
            }

            if (! empty($updates)) {
                $old = $profile->only(array_keys($updates));
                $profile->update($updates);
                $changes[$categoryValue] = ['old' => $old, 'new' => $updates];
            }
        }

        return $changes;
    }

    /**
     * Calculate median lead time in days from order to receipt.
     */
    private function calculateLeadTime(Collection $lines): ?int
    {
        $leadTimes = $lines
            ->filter(fn (array $l) => $l['order_date'] && $l['receipt_date'])
            ->map(function (array $l) {
                $ordered = strtotime($l['order_date']);
                $received = strtotime($l['receipt_date']);

                return $received > $ordered ? (int) round(($received - $ordered) / 86400) : null;
            })
            ->filter()
            ->sort()
            ->values();

        if ($leadTimes->isEmpty()) {
            // Fall back to planned date if no actual receipts
            $leadTimes = $lines
                ->filter(fn (array $l) => $l['order_date'] && $l['planned_date'])
                ->map(function (array $l) {
                    $ordered = strtotime($l['order_date']);
                    $planned = strtotime($l['planned_date']);

                    return $planned > $ordered ? (int) round(($planned - $ordered) / 86400) : null;
                })
                ->filter()
                ->sort()
                ->values();
        }

        if ($leadTimes->isEmpty()) {
            return null;
        }

        // Median
        $count = $leadTimes->count();
        $middle = (int) floor($count / 2);

        return $count % 2 === 0
            ? (int) round(($leadTimes[$middle - 1] + $leadTimes[$middle]) / 2)
            : $leadTimes[$middle];
    }

    /**
     * Calculate minimum order quantity from historical orders.
     */
    private function calculateMoq(Collection $lines): ?int
    {
        $quantities = $lines
            ->filter(fn (array $l) => $l['product_qty'] > 0)
            ->pluck('product_qty')
            ->sort()
            ->values();

        if ($quantities->isEmpty()) {
            return null;
        }

        // Use the 10th percentile to avoid outliers (sample orders, corrections)
        $index = max(0, (int) floor($quantities->count() * 0.1));

        return (int) $quantities[$index];
    }

    /**
     * Calculate average days between orders for a category.
     */
    private function calculateOrderFrequency(Collection $lines): ?int
    {
        $orderDates = $lines
            ->filter(fn (array $l) => $l['order_date'] !== null)
            ->pluck('order_date')
            ->unique()
            ->sort()
            ->values();

        if ($orderDates->count() < 2) {
            return null;
        }

        $gaps = [];
        for ($i = 1; $i < $orderDates->count(); $i++) {
            $gap = (strtotime($orderDates[$i]) - strtotime($orderDates[$i - 1])) / 86400;
            if ($gap > 0) {
                $gaps[] = (int) round($gap);
            }
        }

        if (empty($gaps)) {
            return null;
        }

        return (int) round(array_sum($gaps) / count($gaps));
    }

    /**
     * Fetch all received purchase order lines from Odoo.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetchPurchaseOrderLines(): array
    {
        $allLines = [];
        $offset = 0;
        $batchSize = 200;

        do {
            $batch = $this->odoo->searchRead(
                'purchase.order.line',
                [['product_id', '!=', false]],
                ['product_id', 'product_qty', 'qty_received', 'date_order', 'date_approve', 'date_planned', 'partner_id', 'order_id'],
                $batchSize,
                $offset,
            );

            // Fetch order names for linking to stock.picking
            if (! empty($batch)) {
                $orderIds = collect($batch)->pluck('order_id')->map(fn ($o) => $o[0])->unique()->values()->all();
                $orders = $this->odoo->searchRead('purchase.order', [['id', 'in', $orderIds]], ['name'], 0);
                $orderNameMap = collect($orders)->keyBy('id')->map(fn ($o) => $o['name']);

                foreach ($batch as &$line) {
                    $line['order_name'] = $orderNameMap[$line['order_id'][0]] ?? null;
                }
                unset($line);
            }

            $allLines = array_merge($allLines, $batch);
            $offset += $batchSize;
        } while (count($batch) === $batchSize);

        return $allLines;
    }

    /**
     * Fetch actual receipt dates from stock.picking, keyed by PO reference.
     *
     * @return array<string, string> PO reference → receipt date (Y-m-d)
     */
    private function fetchReceiptDates(): array
    {
        $pickings = $this->odoo->searchRead(
            'stock.picking',
            [['picking_type_code', '=', 'incoming'], ['state', '=', 'done'], ['date_done', '!=', false]],
            ['origin', 'date_done'],
            0,
        );

        $dates = [];
        foreach ($pickings as $picking) {
            $origin = $picking['origin'] ?? null;
            if ($origin && $picking['date_done']) {
                $date = substr($picking['date_done'], 0, 10);
                // Keep the latest receipt date per PO (in case of partial deliveries)
                if (! isset($dates[$origin]) || $date > $dates[$origin]) {
                    $dates[$origin] = $date;
                }
            }
        }

        return $dates;
    }

    /**
     * Build a map from Odoo product ID → our ProductCategory value.
     *
     * @return array<int, string>
     */
    private function buildProductCategoryMap(): array
    {
        return DB::table('products')
            ->whereNotNull('odoo_product_id')
            ->whereNotNull('product_category')
            ->pluck('product_category', 'odoo_product_id')
            ->all();
    }
}
