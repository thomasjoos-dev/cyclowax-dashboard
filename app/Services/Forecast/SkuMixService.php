<?php

namespace App\Services\Forecast;

use App\Enums\ProductCategory;
use App\Models\Scenario;
use App\Models\ScenarioProductMix;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
                AND p.is_active = true
            GROUP BY p.id
            HAVING SUM(sli.quantity) > 0
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
     * Distribute a forecasted unit quantity across SKUs.
     *
     * Uses scenario SKU overrides if available, otherwise falls back to historical mix.
     * Earmarked units (from product-targeted demand events) are reserved for specific
     * products before distributing the remainder via the mix.
     *
     * @param  array<int, int>  $earmarkedUnits  [product_id => units] from targeted events
     * @return array<int, int>
     */
    public function distribute(
        ProductCategory $category,
        int $totalUnits,
        int $lookbackMonths = 12,
        ?Scenario $scenario = null,
        array $earmarkedUnits = [],
    ): array {
        $mix = $this->resolveMix($category, $lookbackMonths, $scenario);

        if (empty($mix) && empty($earmarkedUnits)) {
            return [];
        }

        // Step 1: Reserve earmarked units, cap if exceeding total
        $totalEarmarked = array_sum($earmarkedUnits);

        if ($totalEarmarked > $totalUnits) {
            Log::warning('Earmarked units exceed total forecast', [
                'category' => $category->value,
                'total_units' => $totalUnits,
                'total_earmarked' => $totalEarmarked,
            ]);

            $scale = $totalUnits / $totalEarmarked;
            $earmarkedUnits = array_map(
                fn (int $qty) => (int) floor($qty * $scale),
                $earmarkedUnits,
            );
            $totalEarmarked = array_sum($earmarkedUnits);
        }

        $remainingUnits = $totalUnits - $totalEarmarked;

        // Step 2: Distribute remaining units via mix
        $distributed = [];
        $allocated = 0;

        if ($remainingUnits > 0 && ! empty($mix)) {
            foreach ($mix as $productId => $share) {
                $qty = (int) floor($remainingUnits * $share);
                $distributed[$productId] = $qty;
                $allocated += $qty;
            }

            // Assign remainder to the top SKU
            $remainder = $remainingUnits - $allocated;
            if ($remainder > 0) {
                $topProductId = array_key_first($distributed);
                $distributed[$topProductId] += $remainder;
            }
        }

        // Step 3: Add earmarked units to their specific products
        foreach ($earmarkedUnits as $productId => $earmarked) {
            $distributed[$productId] = ($distributed[$productId] ?? 0) + $earmarked;
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
                AND p.is_active = true
            GROUP BY p.id, p.sku, p.name
            HAVING SUM(sli.quantity) > 0
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

    /**
     * Resolve the SKU mix: scenario overrides if available, otherwise historical.
     *
     * @return array<int, float> [product_id => share (0.0–1.0)]
     */
    private function resolveMix(ProductCategory $category, int $lookbackMonths, ?Scenario $scenario): array
    {
        if ($scenario) {
            $overrides = ScenarioProductMix::query()
                ->where('scenario_id', $scenario->id)
                ->skuOverrides($category)
                ->get();

            if ($overrides->isNotEmpty()) {
                $totalShare = $overrides->sum('sku_share');

                $mix = [];
                foreach ($overrides as $override) {
                    $share = $totalShare > 0
                        ? (float) $override->sku_share / $totalShare
                        : 0;
                    $mix[$override->product_id] = round($share, 4);
                }

                return $mix;
            }
        }

        return $this->mixForCategory($category, $lookbackMonths);
    }
}
