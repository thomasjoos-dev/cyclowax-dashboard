<?php

namespace App\Services\Forecast\Demand;

use App\Enums\ForecastGroup;
use App\Enums\ForecastRegion;
use App\Enums\ProductCategory;
use App\Exceptions\InsufficientBaselineException;
use App\Exceptions\InvalidProductMixException;
use App\Models\Scenario;
use App\Models\ScenarioProductMix;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class DemandForecastService
{
    public function __construct(
        private SalesBaselineService $forecastService,
        private CategorySeasonalCalculator $seasonalCalculator,
        private DemandEventService $demandEventService,
        private CohortProjectionService $cohortProjectionService,
    ) {}

    /**
     * Generate a full year demand forecast per category per month.
     *
     * @return array<int, array<string, array{units: int, revenue: float, seasonal_index: float, event_boost: float, pull_forward: float}>>
     */
    public function forecastYear(Scenario $scenario, int $year, ?ForecastRegion $region = null): array
    {
        $scenario->loadMissing(['assumptions', 'productMixes']);

        $mixes = $this->indexMixesByCategory($scenario, $region);

        if (count($mixes) > 0) {
            $this->validateProductMixes(collect($mixes));
        }

        $baselineByMonth = $this->getBaselineByMonth($year, count($mixes) > 0, $region);
        $assumptions = $this->indexAssumptionsByQuarter($scenario, $region);

        $plannedEvents = $this->demandEventService->plannedForYear($year);

        // Fetch retention curve — regional if available
        $curveData = $this->cohortProjectionService->retentionCurve(12, $region);
        $retentionCurve = $curveData['months'];
        $useCohortModel = ! empty($retentionCurve);

        // Curve adjustment: use regional retention_index if set, else global
        $curveAdjustment = $this->resolveRetentionAdjustment($scenario, $assumptions);

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

            if ($quarter === 'Q1') {
                // Q1: use actuals directly, distribute via product mix
                $acqRevenue = $baseline['acq_rev'];
                $repRevenue = $baseline['rep_rev'];
                $newCustomers = $baseline['new_customers'];
                $cumulativeCustomers += $newCustomers;
                $monthlyCohorts[$month] = $newCustomers;
            } elseif ($qa) {
                $baseQ1 = $baselineByMonth[sprintf('%d-%02d', $year - 1, $month)] ?? $baseline;
                $acqRevenue = $baseQ1['acq_rev'] * $qa['acq_rate'];
                $newCustomers = (int) round($baseQ1['new_customers'] * $qa['acq_rate']);
                $cumulativeCustomers += $newCustomers;
                $monthlyCohorts[$month] = $newCustomers;

                if ($useCohortModel) {
                    $repRevenue = $this->calculateCohortRepeatRevenue(
                        $monthlyCohorts, $month, $qa['repeat_aov'], $retentionCurve, $curveAdjustment,
                    );
                } else {
                    // Flat fallback: original model
                    $repOrders = $cumulativeCustomers * $qa['repeat_rate'] / 3;
                    $repRevenue = $repOrders * $qa['repeat_aov'];
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

                // Apply seasonal index (regional if available)
                $seasonalIndex = $this->seasonalCalculator->resolveIndex($category, $month, $region);
                $seasonalRevenue = $catBaseRevenue * $seasonalIndex;

                // Apply demand event boost
                $eventBoost = $this->calculateEventBoost($plannedEvents, $yearMonth, $category, (float) $mix->avg_unit_price);

                // Apply pull-forward deduction
                $pullForward = $this->calculatePullForward($plannedEvents, $yearMonth, $month, $category, (float) $mix->avg_unit_price);

                $forecastedRevenue = max(0, $seasonalRevenue + $eventBoost - $pullForward);
                $avgPrice = (float) $mix->avg_unit_price;
                $forecastedUnits = $avgPrice > 0 ? (int) round($forecastedRevenue / $avgPrice) : 0;

                $monthForecast[$categoryValue] = [
                    'units' => $forecastedUnits,
                    'revenue' => round($forecastedRevenue, 2),
                    'seasonal_index' => $seasonalIndex,
                    'event_boost' => round($eventBoost, 2),
                    'pull_forward' => round($pullForward, 2),
                ];
            }

            $forecast[$month] = $monthForecast;
        }

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
     * @return array{months: array<int, array{units: int, revenue: float}>, year_total: array{units: int, revenue: float}}
     */
    public function totalForecast(Scenario $scenario, int $year, ?ForecastRegion $region = null): array
    {
        $forecast = $this->forecastYear($scenario, $year, $region);

        $months = [];
        $yearUnits = 0;
        $yearRevenue = 0;

        for ($month = 1; $month <= 12; $month++) {
            $monthData = $forecast[$month] ?? [];
            $units = collect($monthData)->sum('units');
            $revenue = collect($monthData)->sum('revenue');

            $months[$month] = [
                'units' => $units,
                'revenue' => round($revenue, 2),
            ];

            $yearUnits += $units;
            $yearRevenue += $revenue;
        }

        return [
            'months' => $months,
            'year_total' => [
                'units' => $yearUnits,
                'revenue' => round($yearRevenue, 2),
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
     * Validate that product mix shares are within acceptable ranges.
     *
     * @param  Collection<string, ScenarioProductMix>  $mixes
     *
     * @throws InvalidProductMixException
     */
    private function validateProductMixes(Collection $mixes): void
    {
        $violations = [];

        foreach ($mixes as $categoryValue => $mix) {
            $acq = (float) $mix->acq_share;
            $rep = (float) $mix->repeat_share;

            if ($acq < 0 || $acq > 1) {
                $violations["{$categoryValue}.acq_share"] = "value {$acq} not in [0, 1]";
            }
            if ($rep < 0 || $rep > 1) {
                $violations["{$categoryValue}.repeat_share"] = "value {$rep} not in [0, 1]";
            }
        }

        if (count($violations) > 0) {
            throw InvalidProductMixException::sharesOutOfRange($violations);
        }

        $acqSum = $mixes->sum(fn (ScenarioProductMix $m) => (float) $m->acq_share);
        $repSum = $mixes->sum(fn (ScenarioProductMix $m) => (float) $m->repeat_share);

        if ($acqSum < 0.95 || $acqSum > 1.05) {
            throw InvalidProductMixException::sumOutOfTolerance('acq_share', $acqSum);
        }

        if ($repSum < 0.95 || $repSum > 1.05) {
            throw InvalidProductMixException::sumOutOfTolerance('repeat_share', $repSum);
        }
    }

    /**
     * Calculate repeat revenue for a forecast month using cohort-based retention.
     *
     * @param  array<int, int>  $cohorts  Month number → new customer count
     * @param  int  $forecastMonth  The month (1-12) to calculate repeat for
     * @param  float  $repeatAov  Average repeat order value
     * @param  array<int, float>  $retentionCurve  Month → cumulative retention %
     * @param  float  $curveAdjustment  Scalar to shift the curve (1.0 = no change)
     */
    private function calculateCohortRepeatRevenue(
        array $cohorts,
        int $forecastMonth,
        float $repeatAov,
        array $retentionCurve,
        float $curveAdjustment = 1.0,
    ): float {
        $totalRepeatRevenue = 0.0;

        foreach ($cohorts as $cohortMonth => $cohortSize) {
            if ($cohortMonth >= $forecastMonth || $cohortSize <= 0) {
                continue;
            }

            $age = $forecastMonth - $cohortMonth;
            $prevAge = $age - 1;

            $currentRetention = $this->cohortProjectionService->monthlyRetentionRate($age, $retentionCurve) * $curveAdjustment;
            $previousRetention = $prevAge > 0
                ? $this->cohortProjectionService->monthlyRetentionRate($prevAge, $retentionCurve) * $curveAdjustment
                : 0.0;

            // Incremental repeaters this month (delta between cumulative retention points)
            $incrementalPct = max(0, $currentRetention - $previousRetention);
            $incrementalRepeaters = $cohortSize * $incrementalPct / 100;

            $totalRepeatRevenue += $incrementalRepeaters * $repeatAov;
        }

        return round($totalRepeatRevenue, 2);
    }

    /**
     * Calculate demand event boost for a specific month and category.
     */
    private function calculateEventBoost(Collection $events, string $yearMonth, ProductCategory $category, float $avgUnitPrice): float
    {
        $boost = 0;
        $monthStart = $yearMonth.'-01';
        $monthEnd = $yearMonth.'-'.cal_days_in_month(CAL_GREGORIAN, (int) substr($yearMonth, 5, 2), (int) substr($yearMonth, 0, 4));

        foreach ($events as $event) {
            if ($event->start_date->gt($monthEnd) || $event->end_date->lt($monthStart)) {
                continue;
            }

            $eventCategory = $event->categories
                ->first(fn ($ec) => $ec->product_category === $category);

            if ($eventCategory && $eventCategory->expected_uplift_units) {
                // Distribute uplift across event duration months
                $eventMonths = max(1, $event->start_date->diffInMonths($event->end_date) + 1);
                $monthlyUplift = $eventCategory->expected_uplift_units / $eventMonths;
                $boost += $monthlyUplift * $avgUnitPrice;
            }
        }

        return $boost;
    }

    /**
     * Calculate pull-forward deduction: units pulled from this month by a previous month's event.
     */
    private function calculatePullForward(Collection $events, string $yearMonth, int $month, ProductCategory $category, float $avgUnitPrice): float
    {
        // Only Getting Started products have pull-forward
        $group = $category->forecastGroup();
        if ($group !== ForecastGroup::GettingStarted) {
            return 0;
        }

        $deduction = 0;

        foreach ($events as $event) {
            // Check if event ended in the previous month (pull-forward affects month after event)
            $eventEndMonth = (int) $event->end_date->format('m');
            $eventEndYear = (int) $event->end_date->format('Y');
            $forecastYear = (int) substr($yearMonth, 0, 4);

            // Pull forward affects the 4 weeks after event end
            $afterEventMonth = $eventEndMonth + 1;
            $afterEventYear = $eventEndYear;
            if ($afterEventMonth > 12) {
                $afterEventMonth = 1;
                $afterEventYear++;
            }

            if ($month !== $afterEventMonth || $forecastYear !== $afterEventYear) {
                continue;
            }

            $eventCategory = $event->categories
                ->first(fn ($ec) => $ec->product_category === $category);

            if ($eventCategory && $eventCategory->expected_uplift_units && (float) $eventCategory->pull_forward_pct > 0) {
                $pullForwardUnits = $eventCategory->expected_uplift_units * (float) $eventCategory->pull_forward_pct / 100;
                $deduction += $pullForwardUnits * $avgUnitPrice;
            }
        }

        return $deduction;
    }
}
