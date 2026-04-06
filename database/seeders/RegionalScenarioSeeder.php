<?php

namespace Database\Seeders;

use App\Enums\ForecastRegion;
use App\Enums\ProductCategory;
use App\Models\Scenario;
use App\Models\ScenarioAssumption;
use App\Models\ScenarioProductMix;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seed regional scenario assumptions and product mixes for all active scenarios.
 *
 * Calculates per-region growth rates from Q1 2025 vs Q1 2026,
 * repeat AOV from historical data, and product mix shares from 12m sales.
 * Uses updateOrCreate for idempotency — safe to run multiple times.
 */
class RegionalScenarioSeeder extends Seeder
{
    public function run(): void
    {
        $scenarios = Scenario::query()->active()->get();

        if ($scenarios->isEmpty()) {
            $this->command?->warn('No active scenarios found. Run ScenarioSeeder first.');

            return;
        }

        $regionMetrics = $this->calculateRegionalMetrics();
        $regionMixes = $this->calculateRegionalMixes();

        foreach ($scenarios as $scenario) {
            $scenario->loadMissing('assumptions');
            $globalAssumptions = $scenario->assumptions->whereNull('region')->keyBy('quarter');

            foreach (ForecastRegion::cases() as $region) {
                $metrics = $regionMetrics[$region->value] ?? null;

                if (! $metrics || $metrics['q1_2026_revenue'] <= 0) {
                    continue; // Skip regions with no Q1 2026 data
                }

                $this->seedRegionalAssumptions($scenario, $region, $globalAssumptions, $metrics);
                $this->seedRegionalMixes($scenario, $region, $regionMixes[$region->value] ?? []);
            }

            $this->command?->info("Seeded regional data for scenario: {$scenario->label}");
        }
    }

    /**
     * Calculate growth rate and repeat AOV per region.
     *
     * @return array<string, array{acq_growth_rate: float, repeat_aov: float, q1_2025_revenue: float, q1_2026_revenue: float}>
     */
    private function calculateRegionalMetrics(): array
    {
        $metrics = [];

        foreach (ForecastRegion::cases() as $region) {
            $countries = $region->countries();
            $q1_2025 = $this->queryQ1Revenue('2025', $countries);
            $q1_2026 = $this->queryQ1Revenue('2026', $countries);
            $repeatAov = $this->queryRepeatAov($countries);

            $growthRate = ($q1_2025 > 0)
                ? round($q1_2026 / $q1_2025, 4)
                : ($q1_2026 > 0 ? 2.0 : 0.0); // New market with sales → assume 2x, no sales → skip

            $metrics[$region->value] = [
                'acq_growth_rate' => $growthRate,
                'repeat_aov' => $repeatAov,
                'q1_2025_revenue' => $q1_2025,
                'q1_2026_revenue' => $q1_2026,
            ];
        }

        return $metrics;
    }

    /**
     * Calculate product mix shares per region from last 12 months.
     *
     * @return array<string, array<string, array{acq_share: float, repeat_share: float, avg_unit_price: float}>>
     */
    private function calculateRegionalMixes(): array
    {
        $mixes = [];

        foreach (ForecastRegion::cases() as $region) {
            $countries = $region->countries();
            $mixes[$region->value] = $this->queryMixShares($countries);
        }

        return $mixes;
    }

    private function seedRegionalAssumptions(
        Scenario $scenario,
        ForecastRegion $region,
        $globalAssumptions,
        array $metrics,
    ): void {
        foreach (['Q2', 'Q3', 'Q4'] as $quarter) {
            $global = $globalAssumptions[$quarter] ?? null;

            if (! $global) {
                continue;
            }

            // Regional acq_rate = global acq_rate × (regional growth / global growth)
            // This preserves the scenario's relative optimism/pessimism while applying regional dynamics
            $globalGrowth = (float) $global->acq_rate;
            $regionalAcqRate = round($metrics['acq_growth_rate'] * $globalGrowth, 4);

            ScenarioAssumption::updateOrCreate(
                [
                    'scenario_id' => $scenario->id,
                    'quarter' => $quarter,
                    'region' => $region->value,
                ],
                [
                    'acq_rate' => $regionalAcqRate,
                    'repeat_rate' => (float) $global->repeat_rate,
                    'repeat_aov' => $metrics['repeat_aov'] > 0 ? $metrics['repeat_aov'] : (float) $global->repeat_aov,
                ],
            );
        }
    }

    private function seedRegionalMixes(
        Scenario $scenario,
        ForecastRegion $region,
        array $mixes,
    ): void {
        foreach ($mixes as $categoryValue => $mix) {
            ScenarioProductMix::updateOrCreate(
                [
                    'scenario_id' => $scenario->id,
                    'product_category' => $categoryValue,
                    'region' => $region->value,
                ],
                [
                    'acq_share' => $mix['acq_share'],
                    'repeat_share' => $mix['repeat_share'],
                    'avg_unit_price' => $mix['avg_unit_price'],
                ],
            );
        }
    }

    private function queryQ1Revenue(string $year, array $countries): float
    {
        $query = DB::table('shopify_orders')
            ->where('ordered_at', '>=', "{$year}-01-01")
            ->where('ordered_at', '<', "{$year}-04-01")
            ->where('is_first_order', true)
            ->whereNotIn('financial_status', ['voided', 'refunded']);

        $this->applyCountryFilter($query, $countries);

        return (float) ($query->sum('net_revenue') ?? 0);
    }

    private function queryRepeatAov(array $countries): float
    {
        $query = DB::table('shopify_orders')
            ->where('ordered_at', '>=', now()->subMonths(12)->toDateString())
            ->where('is_first_order', false)
            ->whereNotIn('financial_status', ['voided', 'refunded']);

        $this->applyCountryFilter($query, $countries);

        $result = $query->selectRaw('AVG(net_revenue) as avg_aov')->first();

        return round((float) ($result->avg_aov ?? 0), 2);
    }

    /**
     * @return array<string, array{acq_share: float, repeat_share: float, avg_unit_price: float}>
     */
    private function queryMixShares(array $countries): array
    {
        $forecastableCategories = collect(ProductCategory::cases())
            ->filter(fn (ProductCategory $c) => $c->forecastGroup() !== null)
            ->map(fn (ProductCategory $c) => $c->value)
            ->values()
            ->all();

        $query = DB::table('shopify_line_items')
            ->join('products', 'shopify_line_items.product_id', '=', 'products.id')
            ->join('shopify_orders', 'shopify_line_items.order_id', '=', 'shopify_orders.id')
            ->where('shopify_orders.ordered_at', '>=', now()->subMonths(12)->toDateString())
            ->whereNotIn('shopify_orders.financial_status', ['voided', 'refunded'])
            ->whereIn('products.product_category', $forecastableCategories);

        $this->applyCountryFilter($query, $countries, 'shopify_orders');

        $rows = $query->selectRaw("products.product_category, CASE WHEN shopify_orders.is_first_order = 1 THEN 'acq' ELSE 'rep' END as order_type, SUM(shopify_line_items.quantity * shopify_line_items.price) as revenue")
            ->groupBy('products.product_category', 'order_type')
            ->get();

        $totalAcq = $rows->where('order_type', 'acq')->sum('revenue');
        $totalRep = $rows->where('order_type', 'rep')->sum('revenue');

        // Avg unit prices
        $avgPrices = DB::table('shopify_line_items')
            ->join('products', 'shopify_line_items.product_id', '=', 'products.id')
            ->join('shopify_orders', 'shopify_line_items.order_id', '=', 'shopify_orders.id')
            ->where('shopify_orders.ordered_at', '>=', now()->subMonths(12)->toDateString())
            ->whereNotIn('shopify_orders.financial_status', ['voided', 'refunded'])
            ->whereIn('products.product_category', $forecastableCategories);

        $this->applyCountryFilter($avgPrices, $countries, 'shopify_orders');

        $prices = $avgPrices->selectRaw('products.product_category, AVG(shopify_line_items.price) as avg_price')
            ->groupBy('products.product_category')
            ->get()
            ->pluck('avg_price', 'product_category');

        $shares = [];
        foreach ($forecastableCategories as $categoryValue) {
            $acqRev = $rows->where('product_category', $categoryValue)->where('order_type', 'acq')->first()?->revenue ?? 0;
            $repRev = $rows->where('product_category', $categoryValue)->where('order_type', 'rep')->first()?->revenue ?? 0;

            if ($acqRev <= 0 && $repRev <= 0) {
                continue;
            }

            $shares[$categoryValue] = [
                'acq_share' => $totalAcq > 0 ? round($acqRev / $totalAcq, 4) : 0,
                'repeat_share' => $totalRep > 0 ? round($repRev / $totalRep, 4) : 0,
                'avg_unit_price' => round((float) ($prices[$categoryValue] ?? 0), 2),
            ];
        }

        return $shares;
    }

    private function applyCountryFilter($query, array $countries, string $table = 'shopify_orders'): void
    {
        if ($countries === []) {
            // ROW
            $allMapped = collect(ForecastRegion::cases())
                ->filter(fn (ForecastRegion $r) => $r !== ForecastRegion::Row)
                ->flatMap(fn (ForecastRegion $r) => $r->countries())
                ->all();

            $query->where(function ($q) use ($table, $allMapped) {
                $q->whereNotIn("{$table}.shipping_country_code", $allMapped)
                    ->orWhereNull("{$table}.shipping_country_code");
            });
        } else {
            $query->whereIn("{$table}.shipping_country_code", $countries);
        }
    }
}
