<?php

namespace App\Services\Forecast;

use App\Enums\ProductCategory;
use App\Models\Scenario;
use App\Models\SupplyProfile;

class PurchaseCalendarService
{
    public function __construct(
        private DemandForecastService $demandForecast,
        private SkuMixService $skuMix,
        private BomExplosionService $bomExplosion,
        private ProductionScheduleService $productionSchedule,
    ) {}

    /**
     * Generate a full purchase + production calendar for a scenario year.
     *
     * Flow: demand forecast → SKU mix → BOM explosion → netting → production schedule → merged timeline.
     *
     * @return array{timeline: array<int, array>, summary: array, sku_mix: array, component_demand: array, netting: array}
     */
    public function generate(Scenario $scenario, int $year): array
    {
        $forecast = $this->demandForecast->forecastYear($scenario, $year);
        $supplyProfiles = SupplyProfile::all()->keyBy(fn ($p) => $p->product_category->value);

        $allTimelines = [];
        $allSkuMixes = [];
        $allComponentDemand = [];
        $allNetting = [];

        $forecastableCategories = collect(ProductCategory::cases())
            ->filter(fn (ProductCategory $cat) => $cat->forecastGroup() !== null);

        foreach ($forecastableCategories as $category) {
            // Aggregate yearly demand for this category
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

            // SKU mix: distribute across individual products
            $skuDistribution = $this->skuMix->distribute($category, $yearlyUnits);

            if (empty($skuDistribution)) {
                continue;
            }

            $skuDetails = $this->skuMix->mixWithDetails($category);
            $allSkuMixes[$category->value] = $skuDetails;

            // Component demand per category (aggregated across all SKUs)
            $componentDemand = $this->bomExplosion->componentDemand($skuDistribution);
            $allComponentDemand[$category->value] = $componentDemand;

            // Netting
            $netted = $this->productionSchedule->netComponentDemand($componentDemand);
            $allNetting[$category->value] = $netted;

            // Generate quarterly timelines (purchase decisions are typically quarterly)
            $quarterNeedDates = [
                "{$year}-03-31", // Q1 need date
                "{$year}-06-30", // Q2 need date
                "{$year}-09-30", // Q3 need date
                "{$year}-12-31", // Q4 need date
            ];

            foreach ($quarterNeedDates as $qi => $needDate) {
                $quarterMonths = [($qi * 3) + 1, ($qi * 3) + 2, ($qi * 3) + 3];
                $quarterUnits = array_sum(array_map(fn ($m) => $monthlyUnits[$m] ?? 0, $quarterMonths));

                if ($quarterUnits <= 0) {
                    continue;
                }

                // Distribute quarterly demand across SKUs proportionally
                $quarterSkuQty = [];
                foreach ($skuDistribution as $productId => $yearQty) {
                    $ratio = $yearQty / $yearlyUnits;
                    $qQty = (int) ceil($quarterUnits * $ratio);

                    if ($qQty > 0) {
                        $quarterSkuQty[$productId] = $qQty;
                    }
                }

                $quarterTimeline = $this->productionSchedule->timeline($quarterSkuQty, $needDate);

                foreach ($quarterTimeline as &$event) {
                    $event['category'] = $category->value;
                    $event['quarter'] = 'Q'.($qi + 1);
                    $event['scenario'] = $scenario->name;
                }
                unset($event);

                $allTimelines = array_merge($allTimelines, $quarterTimeline);
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

        // Deduplicate: merge purchase events for same product + same date
        $allTimelines = $this->deduplicateEvents($allTimelines);

        return [
            'timeline' => $allTimelines,
            'summary' => $this->buildSummary($allTimelines),
            'sku_mix' => $allSkuMixes,
            'component_demand' => $allComponentDemand,
            'netting' => $allNetting,
        ];
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
