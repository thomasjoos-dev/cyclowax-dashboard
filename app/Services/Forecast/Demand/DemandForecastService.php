<?php

namespace App\Services\Forecast\Demand;

use App\Enums\ForecastRegion;
use App\Enums\ProductCategory;
use App\Exceptions\InsufficientBaselineException;
use App\Models\Scenario;
use App\Models\ScenarioProductMix;
use App\Services\Analysis\CustomerValueService;
use Illuminate\Support\Facades\Log;

class DemandForecastService
{
    public function __construct(
        private SalesBaselineService $forecastService,
        private QuarterlyAovCalculator $aovCalculator,
        private CategorySeasonalCalculator $seasonalCalculator,
        private DemandEventService $demandEventService,
        private CohortProjectionService $cohortProjectionService,
        private CustomerValueService $customerValueService,
        private ForecastValidationService $validationService,
        private CohortRepeatRevenueCalculator $cohortRepeatCalculator,
        private EventBoostCalculator $eventBoostCalculator,
        private PullForwardCalculator $pullForwardCalculator,
    ) {}

    /**
     * Generate a full year demand forecast per category per month.
     *
     * @return array<int, array<string, array{units: int, revenue: float, acq_revenue: float, rep_revenue: float, seasonal_index: float, event_boost: float, pull_forward: float}>>
     */
    public function forecastYear(Scenario $scenario, int $year, ?ForecastRegion $region = null): array
    {
        $scenario->loadMissing(['assumptions', 'productMixes']);

        $mixes = $this->indexMixesByCategory($scenario, $region);

        if (count($mixes) > 0) {
            $this->validationService->validateProductMixes(collect($mixes));
        }

        $baselineByMonth = $this->getBaselineByMonth($year, count($mixes) > 0, $region);
        $this->validationService->detectBaselineAnomalies($baselineByMonth, $year, $region);
        $assumptions = $this->indexAssumptionsByQuarter($scenario, $region);

        $plannedEvents = $this->demandEventService->plannedForYear($year);

        // Fetch retention curve — regional if available
        $curveData = $this->cohortProjectionService->retentionCurve(12, $region);
        $retentionCurve = $curveData['months'];
        $useCohortModel = ! empty($retentionCurve);

        // Curve adjustment: use regional retention_index if set, else global
        $curveAdjustment = $this->resolveRetentionAdjustment($scenario, $assumptions);

        // Dynamic AOV: quarterly repeat AOV from rolling actuals, with scenario assumption as fallback
        $dynamicAov = $this->aovCalculator->repeatAovByQuarter($year, $region);
        $aovByOrderNumber = $this->aovCalculator->repeatAovByOrderNumber($region);

        // Dynamic acquisition AOV: quarterly first-order AOV from rolling actuals
        $dynamicAcqAov = $this->aovCalculator->acqAovByQuarter($year, $region);

        // Validate AOV consistency with product mix
        $this->validationService->validateAovConsistency($dynamicAov, $assumptions, $mixes, $region);
        $this->validationService->validateAcqAovConsistency($dynamicAcqAov, $mixes, $region);

        $forecast = [];
        $cumulativeCustomers = 0;
        $monthlyCohorts = []; // month → new customer count for cohort tracking

        for ($month = 1; $month <= 12; $month++) {
            $quarter = 'Q'.ceil($month / 3);
            $yearMonth = sprintf('%d-%02d', $year, $month);
            $baseline = $baselineByMonth[$yearMonth] ?? null;

            if (! $baseline) {
                $forecast[$month] = [];

                continue;
            }

            // Get quarterly growth assumptions
            $qa = $assumptions[$quarter] ?? null;

            // Resolve repeat AOV: use normalized (discount-adjusted) from quarterly actuals → scenario fallback
            $quarterAov = $dynamicAov[$quarter] ?? null;
            $quarterRepeatAov = $quarterAov['normalized'] ?? $qa['repeat_aov'] ?? 0;
            if ($quarterRepeatAov <= 0 && $qa) {
                $quarterRepeatAov = $qa['repeat_aov'];
            }

            if ($quarter === 'Q1') {
                // Q1: use actuals directly, distribute via product mix
                $acqRevenue = $baseline['acq_rev'];
                $repRevenue = $baseline['rep_rev'];
                $newCustomers = $baseline['new_customers'];
                $cumulativeCustomers += $newCustomers;
                $monthlyCohorts[$month] = $newCustomers;
            } elseif ($qa) {
                $baseQ1 = $baselineByMonth[sprintf('%d-%02d', $year - 1, $month)] ?? $baseline;
                $newCustomers = (int) round($baseQ1['new_customers'] * $qa['acq_rate']);

                // Acquisition revenue: customers × dynamic AOV (normalized, discount-adjusted)
                $quarterAcqAov = $dynamicAcqAov[$quarter] ?? null;
                $effectiveAcqAov = $quarterAcqAov['normalized'] ?? 0;
                if ($effectiveAcqAov <= 0) {
                    // Fallback: implied AOV from baseline
                    $effectiveAcqAov = $baseQ1['new_customers'] > 0
                        ? $baseQ1['acq_rev'] / $baseQ1['new_customers']
                        : 0;
                }
                $acqRevenue = $newCustomers * $effectiveAcqAov;
                $cumulativeCustomers += $newCustomers;
                $monthlyCohorts[$month] = $newCustomers;

                if ($useCohortModel) {
                    $repRevenue = $this->cohortRepeatCalculator->calculate(
                        $monthlyCohorts, $month, $quarterRepeatAov, $retentionCurve, $curveAdjustment, $aovByOrderNumber,
                    );
                } else {
                    // Flat fallback: original model
                    $repOrders = $cumulativeCustomers * $qa['repeat_rate'] / 3;
                    $repRevenue = $repOrders * $quarterRepeatAov;
                }
            } else {
                $forecast[$month] = [];

                continue;
            }

            $monthForecast = [];

            foreach ($mixes as $categoryValue => $mix) {
                $category = ProductCategory::from($categoryValue);

                // Distribute revenue by product mix shares
                $catAcqRevenue = $acqRevenue * (float) $mix->acq_share;
                $catRepRevenue = $repRevenue * (float) $mix->repeat_share;
                $catBaseRevenue = $catAcqRevenue + $catRepRevenue;

                // Track acq/rep ratio for proportional distribution after adjustments
                $acqRatio = $catBaseRevenue > 0 ? $catAcqRevenue / $catBaseRevenue : 0;

                // Apply seasonal index (regional if available)
                $seasonalIndex = $this->seasonalCalculator->resolveIndex($category, $month, $region);
                $seasonalRevenue = $catBaseRevenue * $seasonalIndex;

                // Apply demand event boost
                $eventBoost = $this->eventBoostCalculator->calculate($plannedEvents, $yearMonth, $category, (float) $mix->avg_unit_price);

                // Apply pull-forward deduction
                $pullForward = $this->pullForwardCalculator->calculate($plannedEvents, $yearMonth, $month, $category, (float) $mix->avg_unit_price);

                $forecastedRevenue = max(0, $seasonalRevenue + $eventBoost - $pullForward);
                $avgPrice = (float) $mix->avg_unit_price;
                $forecastedUnits = $avgPrice > 0 ? (int) round($forecastedRevenue / $avgPrice) : 0;

                // Proportionally split final revenue into acquisition vs repeat
                $finalAcqRevenue = round($forecastedRevenue * $acqRatio, 2);
                $finalRepRevenue = round($forecastedRevenue - $finalAcqRevenue, 2);

                $monthForecast[$categoryValue] = [
                    'units' => $forecastedUnits,
                    'revenue' => round($forecastedRevenue, 2),
                    'acq_revenue' => $finalAcqRevenue,
                    'rep_revenue' => $finalRepRevenue,
                    'seasonal_index' => $seasonalIndex,
                    'event_boost' => round($eventBoost, 2),
                    'pull_forward' => round($pullForward, 2),
                ];
            }

            $forecast[$month] = $monthForecast;
        }

        $this->validationService->validateEventUplift($plannedEvents, $forecast, $mixes, $region);

        return $forecast;
    }

    /**
     * Forecast for a single month.
     *
     * @return array<string, array{units: int, revenue: float, seasonal_index: float, event_boost: float, pull_forward: float}>
     */
    public function forecastMonth(Scenario $scenario, string $yearMonth, ?ForecastRegion $region = null): array
    {
        [$year, $month] = explode('-', $yearMonth);
        $fullYear = $this->forecastYear($scenario, (int) $year, $region);

        return $fullYear[(int) $month] ?? [];
    }

    /**
     * Total forecast aggregated across all categories, per month + year total.
     *
     * @return array{months: array<int, array{units: int, revenue: float, acq_revenue: float, rep_revenue: float}>, year_total: array{units: int, revenue: float, acq_revenue: float, rep_revenue: float}}
     */
    public function totalForecast(Scenario $scenario, int $year, ?ForecastRegion $region = null): array
    {
        $forecast = $this->forecastYear($scenario, $year, $region);

        $months = [];
        $yearUnits = 0;
        $yearRevenue = 0;
        $yearAcqRevenue = 0;
        $yearRepRevenue = 0;

        for ($month = 1; $month <= 12; $month++) {
            $monthData = collect($forecast[$month] ?? []);
            $units = $monthData->sum('units');
            $revenue = $monthData->sum('revenue');
            $acqRevenue = $monthData->sum('acq_revenue');
            $repRevenue = $monthData->sum('rep_revenue');

            $months[$month] = [
                'units' => $units,
                'revenue' => round($revenue, 2),
                'acq_revenue' => round($acqRevenue, 2),
                'rep_revenue' => round($repRevenue, 2),
            ];

            $yearUnits += $units;
            $yearRevenue += $revenue;
            $yearAcqRevenue += $acqRevenue;
            $yearRepRevenue += $repRevenue;
        }

        return [
            'months' => $months,
            'year_total' => [
                'units' => $yearUnits,
                'revenue' => round($yearRevenue, 2),
                'acq_revenue' => round($yearAcqRevenue, 2),
                'rep_revenue' => round($yearRepRevenue, 2),
            ],
        ];
    }

    /**
     * Describe which repeat model would be used for a given scenario.
     *
     * @return array{model: string, curve_adjustment: float, cohorts_used: int, source: string}
     */
    public function repeatModelInfo(Scenario $scenario, ?ForecastRegion $region = null): array
    {
        $curveData = $this->cohortProjectionService->retentionCurve(12, $region);
        $useCohortModel = ! empty($curveData['months']);

        $scenario->loadMissing('assumptions');
        $assumptions = $this->indexAssumptionsByQuarter($scenario, $region);

        return [
            'model' => $useCohortModel ? 'cohort' : 'flat',
            'curve_adjustment' => $this->resolveRetentionAdjustment($scenario, $assumptions),
            'cohorts_used' => $curveData['cohorts_used'],
            'source' => $curveData['source'],
        ];
    }

    /**
     * Get baseline monthly actuals from previous year.
     *
     * @return array<string, array{acq_rev: float, rep_rev: float, new_customers: int, repeat_orders: int}>
     */
    private function getBaselineByMonth(int $year, bool $requireQ1 = true, ?ForecastRegion $region = null): array
    {
        $prevYear = $year - 1;
        $rows = $this->forecastService->monthlyActuals("{$prevYear}-01-01", "{$year}-01-01", $region);

        // Also get current year actuals for Q1
        $currentRows = $this->forecastService->monthlyActuals("{$year}-01-01", "{$year}-04-01", $region);

        $indexed = [];
        foreach (array_merge($rows, $currentRows) as $row) {
            $indexed[$row['month']] = $row;
        }

        // Check Q1 completeness
        $q1Available = 0;
        $q1Missing = [];
        for ($m = 1; $m <= 3; $m++) {
            $key = sprintf('%d-%02d', $year, $m);
            if (isset($indexed[$key])) {
                $q1Available++;
            } else {
                $q1Missing[] = $key;
            }
        }

        if ($requireQ1 && $q1Available === 0) {
            // For regional forecasts, missing Q1 is non-fatal — the region may have no orders
            if ($region !== null) {
                Log::info("No Q1 data for {$region->label()} in {$year} — region will produce zero forecast");

                return [];
            }
            throw InsufficientBaselineException::noQ1Data($year);
        }

        if ($requireQ1 && $q1Available < 3 && $region === null) {
            Log::warning('Incomplete Q1 data for forecast baseline', [
                'year' => $year,
                'missing_months' => $q1Missing,
                'available' => $q1Available,
            ]);
        }

        // Map previous year months to current year for baseline
        $baseline = [];
        for ($month = 1; $month <= 12; $month++) {
            $prevKey = sprintf('%d-%02d', $prevYear, $month);
            $currKey = sprintf('%d-%02d', $year, $month);

            // For Q1: use current year actuals if available
            if ($month <= 3 && isset($indexed[$currKey])) {
                $baseline[$currKey] = $indexed[$currKey];
            } elseif (isset($indexed[$prevKey])) {
                $baseline[sprintf('%d-%02d', $year, $month)] = $indexed[$prevKey];
            }
        }

        return $baseline;
    }

    /**
     * Index scenario assumptions by quarter.
     * Loads regional assumptions first; falls back to global (region=null) per quarter.
     *
     * @return array<string, array{acq_rate: float, repeat_rate: float, repeat_aov: float, retention_index: float|null}>
     */
    private function indexAssumptionsByQuarter(Scenario $scenario, ?ForecastRegion $region = null): array
    {
        $globalAssumptions = [];
        $regionalAssumptions = [];

        foreach ($scenario->assumptions as $assumption) {
            if ($assumption->region === null) {
                $globalAssumptions[$assumption->quarter] = $assumption;
            } elseif ($region !== null && $assumption->region === $region) {
                $regionalAssumptions[$assumption->quarter] = $assumption;
            }
        }

        $indexed = [];
        foreach (['Q1', 'Q2', 'Q3', 'Q4'] as $quarter) {
            $assumption = $regionalAssumptions[$quarter] ?? $globalAssumptions[$quarter] ?? null;

            if ($assumption) {
                $indexed[$quarter] = [
                    'acq_rate' => (float) $assumption->acq_rate,
                    'repeat_rate' => (float) $assumption->repeat_rate,
                    'repeat_aov' => (float) $assumption->repeat_aov,
                    'retention_index' => $assumption->retention_index !== null ? (float) $assumption->retention_index : null,
                ];
            }
        }

        return $indexed;
    }

    /**
     * Index product mixes by category value.
     * Loads regional mixes first; falls back to global (region=null) per category.
     *
     * @return array<string, ScenarioProductMix>
     */
    private function indexMixesByCategory(Scenario $scenario, ?ForecastRegion $region = null): array
    {
        $globalMixes = [];
        $regionalMixes = [];

        foreach ($scenario->productMixes as $mix) {
            $catValue = $mix->product_category->value;

            if ($mix->region === null) {
                $globalMixes[$catValue] = $mix;
            } elseif ($region !== null && $mix->region === $region) {
                $regionalMixes[$catValue] = $mix;
            }
        }

        // Regional takes priority; fall back to global per category
        $result = [];
        foreach ($globalMixes as $catValue => $mix) {
            $result[$catValue] = $regionalMixes[$catValue] ?? $mix;
        }

        // Include any regional-only categories not in global
        foreach ($regionalMixes as $catValue => $mix) {
            if (! isset($result[$catValue])) {
                $result[$catValue] = $mix;
            }
        }

        return $result;
    }

    /**
     * Resolve the retention curve adjustment factor.
     * Regional retention_index (from assumptions) overrides global retention_curve_adjustment.
     *
     * @param  array<string, array{retention_index: float|null}>  $assumptions
     */
    private function resolveRetentionAdjustment(Scenario $scenario, array $assumptions): float
    {
        // Check if any regional assumption has a retention_index set
        foreach ($assumptions as $qa) {
            if (isset($qa['retention_index']) && $qa['retention_index'] !== null) {
                return $qa['retention_index'];
            }
        }

        // Fallback to global scenario-level adjustment
        return (float) ($scenario->retention_curve_adjustment ?? 1.0);
    }

    /**
     * Validate that forecast-implied LTV is consistent with historical and predicted LTV.
     *
     * @return array{forecast_implied_ltv: float, historical_avg_ltv: float, predicted_ltv: float, delta_pct: float, warning: string|null}
     */
    public function validateLtvConsistency(Scenario $scenario, int $year, ?ForecastRegion $region = null): array
    {
        $total = $this->totalForecast($scenario, $year, $region);
        $yearRevenue = $total['year_total']['revenue'];

        // Count new customers from Q1 baseline + Q2-Q4 grown
        $baselineByMonth = $this->getBaselineByMonth($year, false, $region);
        $assumptions = $this->indexAssumptionsByQuarter($scenario, $region);
        $totalNewCustomers = 0;

        for ($month = 1; $month <= 12; $month++) {
            $quarter = 'Q'.ceil($month / 3);
            $yearMonth = sprintf('%d-%02d', $year, $month);
            $baseline = $baselineByMonth[$yearMonth] ?? null;

            if ($baseline === null) {
                continue;
            }

            if ($quarter === 'Q1') {
                $totalNewCustomers += $baseline['new_customers'];
            } else {
                $qa = $assumptions[$quarter] ?? null;
                if ($qa) {
                    $baseQ1 = $baselineByMonth[sprintf('%d-%02d', $year - 1, $month)] ?? $baseline;
                    $totalNewCustomers += (int) round($baseQ1['new_customers'] * $qa['acq_rate']);
                }
            }
        }

        // Historical + predicted LTV
        $historicalCohorts = $this->customerValueService->ltvByCohort(($year - 2).'-01-01');
        $historicalAvgLtv = ! empty($historicalCohorts)
            ? round(collect($historicalCohorts)->avg('avg_ltv'), 2)
            : 0;

        $predictedLtv = $this->cohortProjectionService->predictedLtv($region);

        return $this->validationService->validateLtvConsistency(
            $yearRevenue,
            $totalNewCustomers,
            $historicalAvgLtv,
            $predictedLtv['predicted_ltv_12m'],
            $region,
        );
    }
}
