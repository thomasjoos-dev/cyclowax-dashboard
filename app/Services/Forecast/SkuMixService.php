<?php

namespace App\Services\Forecast;

use App\Enums\ProductCategory;
use Illuminate\Support\Facades\DB;

class SkuMixService
{
    /**
     * Calculate the historical sales mix per SKU within a product category.
     *
     * Returns an array of [product_id => share] where share is a decimal (0.0–1.0)
     * based on units sold in the lookback period.
     *
     * @return array<int, float>
     */
    public function mixForCategory(ProductCategory $category, int $lookbackMonths = 12): array
    {
        $since = now()->subMonths($lookbackMonths)->toDateString();

        $rows = DB::select('
            SELECT
                p.id as product_id,
                SUM(sli.quantity) as total_units
            FROM shopify_line_items sli
            JOIN shopify_orders so ON so.id = sli.order_id
            JOIN products p ON p.id = sli.product_id
            WHERE p.product_category = ?
                AND so.ordered_at >= ?
                AND so.financial_status NOT IN (?, ?)
                AND p.is_active = 1
            GROUP BY p.id
            HAVING total_units > 0
            ORDER BY total_units DESC
        ', [$category->value, $since, 'voided', 'refunded']);

        $totalUnits = array_sum(array_column($rows, 'total_units'));

        if ($totalUnits === 0) {
            return [];
        }

        $mix = [];
        foreach ($rows as $row) {
            $mix[(int) $row->product_id] = round((float) $row->total_units / $totalUnits, 4);
        }

        return $mix;
    }

    /**
     * Distribute a forecasted unit quantity across SKUs using the historical mix.
     *
     * Returns [product_id => quantity] with quantities rounded to integers.
     * Remainder units are assigned to the most popular SKU.
     *
     * @return array<int, int>
     */
    public function distribute(ProductCategory $category, int $totalUnits, int $lookbackMonths = 12): array
    {
        $mix = $this->mixForCategory($category, $lookbackMonths);

        if (empty($mix)) {
            return [];
        }

        $distributed = [];
        $allocated = 0;

        foreach ($mix as $productId => $share) {
            $qty = (int) floor($totalUnits * $share);
            $distributed[$productId] = $qty;
            $allocated += $qty;
        }

        // Assign remainder to the top SKU
        $remainder = $totalUnits - $allocated;
        if ($remainder > 0) {
            $topProductId = array_key_first($distributed);
            $distributed[$topProductId] += $remainder;
        }

        return array_filter($distributed, fn (int $qty) => $qty > 0);
    }

    /**
     * Get the mix with product details for display.
     *
     * @return array<int, array{product_id: int, sku: string, name: string, share: float, units_12m: int}>
     */
    public function mixWithDetails(ProductCategory $category, int $lookbackMonths = 12): array
    {
        $since = now()->subMonths($lookbackMonths)->toDateString();

        $rows = DB::select('
            SELECT
                p.id as product_id,
                p.sku,
                p.name,
                SUM(sli.quantity) as total_units
            FROM shopify_line_items sli
            JOIN shopify_orders so ON so.id = sli.order_id
            JOIN products p ON p.id = sli.product_id
            WHERE p.product_category = ?
                AND so.ordered_at >= ?
                AND so.financial_status NOT IN (?, ?)
                AND p.is_active = 1
            GROUP BY p.id, p.sku, p.name
            HAVING total_units > 0
            ORDER BY total_units DESC
        ', [$category->value, $since, 'voided', 'refunded']);

        $totalUnits = array_sum(array_column($rows, 'total_units'));

        if ($totalUnits === 0) {
            return [];
        }

        return array_map(fn ($row) => [
            'product_id' => (int) $row->product_id,
            'sku' => $row->sku,
            'name' => $row->name,
            'share' => round((float) $row->total_units / $totalUnits, 4),
            'units_12m' => (int) $row->total_units,
        ], $rows);
    }
}
