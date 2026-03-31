<?php

namespace App\Services\Forecast;

use Illuminate\Support\Facades\DB;

class StockForecastService
{
    /**
     * Average daily sales (burn rate) for a product based on line item history.
     * Works without stock snapshots — uses sales velocity as proxy.
     */
    public function burnRate(int $productId, int $lookbackDays = 90): float
    {
        $since = now()->subDays($lookbackDays)->toDateString();

        $result = DB::selectOne("
            SELECT COALESCE(SUM(sli.quantity), 0) as total_sold
            FROM shopify_line_items sli
            JOIN shopify_orders so ON so.id = sli.order_id
            WHERE sli.product_id = ?
                AND so.ordered_at >= ?
                AND so.financial_status NOT IN ('voided', 'refunded')
        ", [$productId, $since]);

        $totalSold = (int) $result->total_sold;

        return $lookbackDays > 0 ? round($totalSold / $lookbackDays, 2) : 0;
    }

    /**
     * Estimated days of stock remaining based on current qty_free and burn rate.
     *
     * @return array{qty_free: float, burn_rate: float, runway_days: int|null}
     */
    public function stockRunway(int $productId, int $lookbackDays = 90): array
    {
        $burnRate = $this->burnRate($productId, $lookbackDays);

        // Get latest stock snapshot
        $stock = DB::selectOne('
            SELECT qty_free
            FROM product_stock_snapshots
            WHERE product_id = ?
            ORDER BY recorded_at DESC
            LIMIT 1
        ', [$productId]);

        $qtyFree = $stock ? (float) $stock->qty_free : 0;

        return [
            'qty_free' => $qtyFree,
            'burn_rate' => $burnRate,
            'runway_days' => $burnRate > 0 ? (int) floor($qtyFree / $burnRate) : null,
        ];
    }

    /**
     * Check if a product needs reordering based on lead time.
     *
     * @return array{needs_reorder: bool, runway_days: int|null, lead_time_days: int, buffer_days: int}
     */
    public function reorderAlert(int $productId, int $leadTimeDays, int $bufferDays = 14): array
    {
        $runway = $this->stockRunway($productId);
        $threshold = $leadTimeDays + $bufferDays;

        return [
            'needs_reorder' => $runway['runway_days'] !== null && $runway['runway_days'] <= $threshold,
            'runway_days' => $runway['runway_days'],
            'lead_time_days' => $leadTimeDays,
            'buffer_days' => $bufferDays,
        ];
    }

    /**
     * Portfolio-wide stock status overview.
     *
     * @return array<int, array{product_id: int, sku: string, name: string, qty_free: float, burn_rate: float, runway_days: int|null, status: string}>
     */
    public function portfolioStatus(int $lookbackDays = 90): array
    {
        // Get latest stock per product
        $stocks = DB::select('
            SELECT
                pss.product_id,
                p.sku,
                p.name,
                pss.qty_free,
                pss.qty_on_hand,
                pss.qty_forecasted
            FROM product_stock_snapshots pss
            JOIN products p ON p.id = pss.product_id
            INNER JOIN (
                SELECT product_id, MAX(recorded_at) as max_date
                FROM product_stock_snapshots
                GROUP BY product_id
            ) latest ON latest.product_id = pss.product_id AND latest.max_date = pss.recorded_at
            WHERE p.is_active = 1
            ORDER BY pss.qty_free ASC
        ');

        // Get burn rates for all products in one query
        $since = now()->subDays($lookbackDays)->toDateString();
        $sales = DB::select("
            SELECT
                sli.product_id,
                SUM(sli.quantity) as total_sold
            FROM shopify_line_items sli
            JOIN shopify_orders so ON so.id = sli.order_id
            WHERE so.ordered_at >= ?
                AND so.financial_status NOT IN ('voided', 'refunded')
                AND sli.product_id IS NOT NULL
            GROUP BY sli.product_id
        ", [$since]);

        $salesMap = [];
        foreach ($sales as $row) {
            $salesMap[$row->product_id] = (int) $row->total_sold;
        }

        $result = [];
        foreach ($stocks as $stock) {
            $qtyFree = (float) $stock->qty_free;
            $totalSold = $salesMap[$stock->product_id] ?? 0;
            $burnRate = $lookbackDays > 0 ? round($totalSold / $lookbackDays, 2) : 0;
            $runwayDays = $burnRate > 0 ? (int) floor($qtyFree / $burnRate) : null;

            $status = 'healthy';
            if ($qtyFree <= 0) {
                $status = 'out_of_stock';
            } elseif ($runwayDays !== null && $runwayDays <= 14) {
                $status = 'critical';
            } elseif ($runwayDays !== null && $runwayDays <= 30) {
                $status = 'low';
            }

            $result[] = [
                'product_id' => (int) $stock->product_id,
                'sku' => $stock->sku,
                'name' => $stock->name,
                'qty_free' => $qtyFree,
                'burn_rate' => $burnRate,
                'runway_days' => $runwayDays,
                'status' => $status,
            ];
        }

        return $result;
    }
}
