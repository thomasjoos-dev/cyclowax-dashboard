<?php

namespace App\Services\Forecast\Supply;

use App\Enums\ProductCategory;
use App\Enums\Warehouse;
use App\Models\Scenario;
use App\Models\SupplyProfile;
use App\Services\Forecast\Demand\DemandEventService;
use App\Services\Forecast\Demand\DemandForecastService;
use App\Services\Forecast\SkuMixService;

class PurchaseCalendarService
{
    public function __construct(
        private DemandForecastService $demandForecast,
        private SkuMixService $skuMix,
        private BomExplosionService $bomExplosion,
        private ProductionTimelineService $productionTimeline,
        private ComponentNettingService $netting,
        private DemandEventService $demandEvents,
    ) {}

    /**
     * Generate a full purchase + production calendar for a scenario year.
     * Optionally scoped to a single warehouse (aggregates demand from its regions).
     *
     * Flow: demand forecast → SKU mix → BOM explosion → cross-category aggregation → single netting pass → timeline.
     *
     * @return array{timeline: array<int, array>, summary: array, sku_mix: array, component_demand: array, netting: array, warehouse: string|null}
     */
    public function generate(Scenario $scenario, int $year, ?Warehouse $warehouse = null): array
    {
        $forecast = $this->buildForecast($scenario, $year, $warehouse);
        $supplyProfiles = SupplyProfile::all()->keyBy(fn ($p) => $p->product_category->value);
        $eventEarmarks = $this->demandEvents->skuEarmarksForYear($year);

        $allTimelines = [];
        $allSkuMixes = [];
        $allComponentDemand = [];
        $categoryDemandMap = []; // category => [product_id => component data]
        $categorySkuDistributions = []; // category => [product_id => qty]
        $categoryMonthlyUnits = []; // category => [month => units]
        $categoryYearlyUnits = []; // category => yearly total
        $categoryMonthlyEarmarks = []; // category => [month => [product_id => units]]

        $forecastableCategories = collect(ProductCategory::cases())
            ->filter(fn (ProductCategory $cat) => $cat->forecastGroup() !== null);

        // Phase 1: Collect demand per category and BOM-explode to components
        foreach ($forecastableCategories as $category) {
            $yearlyUnits = 0;
            $monthlyUnits = [];

            for ($month = 1; $month <= 12; $month++) {
                $monthData = $forecast[$month][$category->value] ?? null;
                $units = $monthData ? (int) ceil($monthData['units']) : 0;
                $yearlyUnits += $units;
                $monthlyUnits[$month] = $units;
            }

            if ($yearlyUnits <= 0) {
                continue;
            }

            // Collect monthly earmarks for this category and aggregate yearly
            $monthEarmarks = [];
            $yearlyEarmarks = [];
            for ($month = 1; $month <= 12; $month++) {
                $me = $eventEarmarks[$month][$category->value] ?? [];
                $monthEarmarks[$month] = $me;
                foreach ($me as $productId => $units) {
                    $yearlyEarmarks[$productId] = ($yearlyEarmarks[$productId] ?? 0) + $units;
                }
            }
            $categoryMonthlyEarmarks[$category->value] = $monthEarmarks;

            $skuDistribution = $this->skuMix->distribute(
                $category,
                $yearlyUnits,
                scenario: $scenario,
                earmarkedUnits: $yearlyEarmarks,
            );

            if (empty($skuDistribution)) {
                continue;
            }

            $skuDetails = $this->skuMix->mixWithDetails($category);
            $allSkuMixes[$category->value] = $skuDetails;

            $componentDemand = $this->bomExplosion->componentDemand($skuDistribution);
            $allComponentDemand[$category->value] = $componentDemand;

            $categoryDemandMap[$category->value] = $componentDemand;
            $categorySkuDistributions[$category->value] = $skuDistribution;
            $categoryMonthlyUnits[$category->value] = $monthlyUnits;
            $categoryYearlyUnits[$category->value] = $yearlyUnits;
        }

        // Phase 2: Build monthly component demand across all categories, then rolling-net
        $monthlyComponentDemand = $this->buildMonthlyComponentDemand(
            $categorySkuDistributions,
            $categoryMonthlyUnits,
            $categoryYearlyUnits,
        );
        $componentMeta = $this->buildComponentMeta($categoryDemandMap);
        $globalNetting = $this->netting->rollingNet($monthlyComponentDemand, $componentMeta, $year);

        // Build a lookup of net results by product_id for per-category reporting
        $nettingByProduct = [];
        foreach ($globalNetting as $netted) {
            $nettingByProduct[$netted['product_id']] = $netted;
        }

        // Phase 3: Split netting results back per category (pro-rata by gross need)
        $allNetting = $this->splitNettingByCategory($categoryDemandMap, $nettingByProduct);

        // Phase 4: Generate monthly timelines per category
        foreach ($categorySkuDistributions as $categoryValue => $skuDistribution) {
            $yearlyUnits = $categoryYearlyUnits[$categoryValue];
            $monthlyUnits = $categoryMonthlyUnits[$categoryValue];
            $category = ProductCategory::from($categoryValue);

            for ($month = 1; $month <= 12; $month++) {
                $units = $monthlyUnits[$month] ?? 0;

                if ($units <= 0) {
                    continue;
                }

                // Need date = last day of the month
                $needDate = date('Y-m-t', strtotime("{$year}-".str_pad($month, 2, '0', STR_PAD_LEFT).'-01'));

                // Distribute monthly demand across SKUs with earmark awareness
                $monthEarmarks = $categoryMonthlyEarmarks[$categoryValue][$month] ?? [];
                $monthSkuQty = $this->skuMix->distribute(
                    $category,
                    $units,
                    scenario: $scenario,
                    earmarkedUnits: $monthEarmarks,
                );

                $monthTimeline = $this->productionTimeline->timeline($monthSkuQty, $needDate);

                $monthLabel = date('M', strtotime("{$year}-".str_pad($month, 2, '0', STR_PAD_LEFT).'-01'));

                foreach ($monthTimeline as &$event) {
                    $event['category'] = $category->value;
                    $event['month'] = $monthLabel;
                    $event['scenario'] = $scenario->name;
                }
                unset($event);

                $allTimelines = array_merge($allTimelines, $monthTimeline);
            }
        }

        // Sort full timeline chronologically
        usort($allTimelines, function (array $a, array $b) {
            $dateCmp = $a['date'] <=> $b['date'];
            if ($dateCmp !== 0) {
                return $dateCmp;
            }

            $order = ['purchase' => 0, 'receipt' => 1, 'production_start' => 2, 'production_done' => 3];

            return ($order[$a['event_type']] ?? 5) <=> ($order[$b['event_type']] ?? 5);
        });

        $allTimelines = $this->deduplicateEvents($allTimelines);

        return [
            'timeline' => $allTimelines,
            'summary' => $this->buildSummary($allTimelines),
            'sku_mix' => $allSkuMixes,
            'component_demand' => $allComponentDemand,
            'netting' => $allNetting,
            'warehouse' => $warehouse?->value,
        ];
    }

    /**
     * Build the demand forecast, optionally scoped to a warehouse's regions.
     *
     * @return array<int, array<string, array{units: int, revenue: float}>>
     */
    private function buildForecast(Scenario $scenario, int $year, ?Warehouse $warehouse): array
    {
        if ($warehouse === null) {
            return $this->demandForecast->forecastYear($scenario, $year);
        }

        // Aggregate regional forecasts for all regions in this warehouse
        $merged = [];
        for ($m = 1; $m <= 12; $m++) {
            $merged[$m] = [];
        }

        foreach ($warehouse->regions() as $region) {
            $regionalForecast = $this->demandForecast->forecastYear($scenario, $year, $region);

            for ($m = 1; $m <= 12; $m++) {
                foreach ($regionalForecast[$m] ?? [] as $catValue => $data) {
                    if (! isset($merged[$m][$catValue])) {
                        $merged[$m][$catValue] = [
                            'units' => 0,
                            'revenue' => 0.0,
                            'seasonal_index' => $data['seasonal_index'],
                            'event_boost' => 0.0,
                            'pull_forward' => 0.0,
                        ];
                    }
                    $merged[$m][$catValue]['units'] += $data['units'];
                    $merged[$m][$catValue]['revenue'] += $data['revenue'];
                    $merged[$m][$catValue]['event_boost'] += $data['event_boost'];
                    $merged[$m][$catValue]['pull_forward'] += $data['pull_forward'];
                }
            }
        }

        return $merged;
    }

    /**
     * Build monthly component demand aggregated across all categories.
     *
     * For each component, calculates how much is needed per month by distributing
     * the monthly category demand through SKU mix and BOM explosion ratios.
     *
     * @return array<int, array<int, float>> [product_id => [month => demand]]
     */
    private function buildMonthlyComponentDemand(
        array $categorySkuDistributions,
        array $categoryMonthlyUnits,
        array $categoryYearlyUnits,
    ): array {
        $monthlyDemand = [];

        foreach ($categorySkuDistributions as $categoryValue => $skuDistribution) {
            $yearlyUnits = $categoryYearlyUnits[$categoryValue];
            $monthlyUnits = $categoryMonthlyUnits[$categoryValue];

            if ($yearlyUnits <= 0) {
                continue;
            }

            // Get component ratios from yearly BOM explosion
            $yearlyComponents = $this->bomExplosion->componentDemand($skuDistribution);

            foreach ($yearlyComponents as $comp) {
                $pid = $comp['product_id'];
                // Ratio: how much of this component per unit of category demand
                $componentPerUnit = $comp['total_quantity'] / $yearlyUnits;

                for ($m = 1; $m <= 12; $m++) {
                    $units = $monthlyUnits[$m] ?? 0;
                    $monthDemand = $units * $componentPerUnit;

                    if ($monthDemand > 0) {
                        $monthlyDemand[$pid][$m] = ($monthlyDemand[$pid][$m] ?? 0) + $monthDemand;
                    }
                }
            }
        }

        return $monthlyDemand;
    }

    /**
     * Build component metadata lookup from category demand map.
     *
     * @return array<int, array{sku: string, name: string, procurement_lt: int|null}>
     */
    private function buildComponentMeta(array $categoryDemandMap): array
    {
        $meta = [];

        foreach ($categoryDemandMap as $components) {
            foreach ($components as $comp) {
                $pid = $comp['product_id'];
                if (! isset($meta[$pid])) {
                    $meta[$pid] = [
                        'sku' => $comp['sku'],
                        'name' => $comp['name'],
                        'procurement_lt' => $comp['procurement_lt'],
                    ];
                }
            }
        }

        return $meta;
    }

    /**
     * Split global netting results back per category, pro-rata by gross need.
     *
     * Each category sees the full stock/open PO coverage, but net_need is
     * distributed proportionally to how much of the gross demand came from
     * that category.
     *
     * @param  array<string, array>  $categoryDemandMap
     * @param  array<int, array>  $nettingByProduct
     * @return array<string, array>
     */
    private function splitNettingByCategory(array $categoryDemandMap, array $nettingByProduct): array
    {
        $result = [];

        foreach ($categoryDemandMap as $category => $components) {
            $categoryNetting = [];

            foreach ($components as $comp) {
                $pid = $comp['product_id'];
                $global = $nettingByProduct[$pid] ?? null;

                if (! $global) {
                    continue;
                }

                $categoryGross = $comp['total_quantity'];
                $totalGross = $global['gross_need'];

                // Pro-rata share of this category's contribution to net need
                $ratio = $totalGross > 0 ? $categoryGross / $totalGross : 0;
                $categoryNetNeed = round($global['net_need'] * $ratio, 2);

                $categoryNetting[] = [
                    'product_id' => $pid,
                    'sku' => $comp['sku'],
                    'name' => $comp['name'],
                    'gross_need' => $categoryGross,
                    'stock_available' => $global['stock_available'],
                    'open_po_qty' => $global['open_po_total'] ?? 0,
                    'net_need' => $categoryNetNeed,
                    'procurement_lt' => $comp['procurement_lt'],
                    'first_shortfall_month' => $global['first_shortfall_month'] ?? null,
                ];
            }

            $result[$category] = $categoryNetting;
        }

        return $result;
    }

    /**
     * Merge duplicate events (same product + date + event_type).
     *
     * @param  array<int, array>  $events
     * @return array<int, array>
     */
    private function deduplicateEvents(array $events): array
    {
        $grouped = [];

        foreach ($events as $event) {
            $key = $event['date'].'|'.$event['event_type'].'|'.$event['product_id'];

            if (! isset($grouped[$key])) {
                $grouped[$key] = $event;
            } else {
                $grouped[$key]['quantity'] += $event['quantity'];
                $grouped[$key]['gross_quantity'] += $event['gross_quantity'];
                $grouped[$key]['net_quantity'] += $event['net_quantity'];
            }
        }

        return array_values($grouped);
    }

    /**
     * @return array{total_events: int, purchase_events: int, production_events: int, categories: array<string, int>}
     */
    private function buildSummary(array $timeline): array
    {
        $purchases = 0;
        $productions = 0;
        $categories = [];

        foreach ($timeline as $event) {
            if ($event['event_type'] === 'purchase') {
                $purchases++;
            }
            if ($event['event_type'] === 'production_start') {
                $productions++;
            }

            $cat = $event['category'] ?? 'unknown';
            $categories[$cat] = ($categories[$cat] ?? 0) + 1;
        }

        return [
            'total_events' => count($timeline),
            'purchase_events' => $purchases,
            'production_events' => $productions,
            'categories' => $categories,
        ];
    }
}
