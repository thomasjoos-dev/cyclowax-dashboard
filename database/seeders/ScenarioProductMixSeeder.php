<?php

namespace Database\Seeders;

use App\Enums\ProductCategory;
use App\Models\Scenario;
use App\Models\ScenarioProductMix;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seed product mix shares for all active scenarios based on historical sales data.
 * Calculates acquisition and repeat shares per product category from 2025 order data.
 */
class ScenarioProductMixSeeder extends Seeder
{
    public function run(): void
    {
        $shares = $this->calculateHistoricalShares();
        $avgPrices = $this->calculateAverageUnitPrices();

        $scenarios = Scenario::query()->active()->get();

        foreach ($scenarios as $scenario) {
            foreach ($shares as $categoryValue => $categoryShares) {
                ScenarioProductMix::updateOrCreate(
                    [
                        'scenario_id' => $scenario->id,
                        'product_category' => $categoryValue,
                    ],
                    [
                        'acq_share' => $categoryShares['acq_share'],
                        'repeat_share' => $categoryShares['repeat_share'],
                        'avg_unit_price' => $avgPrices[$categoryValue] ?? 0,
                    ],
                );
            }
        }
    }

    /**
     * Calculate acquisition and repeat share per forecastable product category.
     *
     * @return array<string, array{acq_share: float, repeat_share: float}>
     */
    private function calculateHistoricalShares(): array
    {
        $forecastableCategories = collect(ProductCategory::cases())
            ->filter(fn (ProductCategory $c) => $c->forecastGroup() !== null)
            ->map(fn (ProductCategory $c) => $c->value)
            ->values()
            ->all();

        $rows = DB::table('shopify_line_items')
            ->join('products', 'shopify_line_items.product_id', '=', 'products.id')
            ->join('shopify_orders', 'shopify_line_items.order_id', '=', 'shopify_orders.id')
            ->where('shopify_orders.ordered_at', '>=', '2025-01-01')
            ->whereNotIn('shopify_orders.financial_status', ['voided', 'refunded'])
            ->whereIn('products.product_category', $forecastableCategories)
            ->selectRaw("products.product_category, CASE WHEN shopify_orders.is_first_order = 1 THEN 'acq' ELSE 'rep' END as order_type, SUM(shopify_line_items.quantity * shopify_line_items.price) as revenue")
            ->groupBy('products.product_category', 'order_type')
            ->get();

        $totalAcq = $rows->where('order_type', 'acq')->sum('revenue');
        $totalRep = $rows->where('order_type', 'rep')->sum('revenue');

        $shares = [];
        foreach ($forecastableCategories as $categoryValue) {
            $acqRev = $rows->where('product_category', $categoryValue)->where('order_type', 'acq')->first()?->revenue ?? 0;
            $repRev = $rows->where('product_category', $categoryValue)->where('order_type', 'rep')->first()?->revenue ?? 0;

            $shares[$categoryValue] = [
                'acq_share' => $totalAcq > 0 ? round($acqRev / $totalAcq, 4) : 0,
                'repeat_share' => $totalRep > 0 ? round($repRev / $totalRep, 4) : 0,
            ];
        }

        return $shares;
    }

    /**
     * Calculate average unit price per forecastable product category.
     *
     * @return array<string, float>
     */
    private function calculateAverageUnitPrices(): array
    {
        $rows = DB::table('shopify_line_items')
            ->join('products', 'shopify_line_items.product_id', '=', 'products.id')
            ->join('shopify_orders', 'shopify_line_items.order_id', '=', 'shopify_orders.id')
            ->where('shopify_orders.ordered_at', '>=', '2025-01-01')
            ->whereNotIn('shopify_orders.financial_status', ['voided', 'refunded'])
            ->whereNotNull('products.product_category')
            ->selectRaw('products.product_category, AVG(shopify_line_items.price) as avg_price')
            ->groupBy('products.product_category')
            ->get();

        return $rows->pluck('avg_price', 'product_category')
            ->map(fn ($price) => round((float) $price, 2))
            ->all();
    }
}
