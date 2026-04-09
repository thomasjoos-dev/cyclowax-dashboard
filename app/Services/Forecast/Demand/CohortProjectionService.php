<?php

namespace App\Services\Forecast\Demand;

use App\Enums\ForecastRegion;
use App\Models\Scenario;
use App\Models\ShopifyCustomer;
use App\Models\ShopifyOrder;
use App\Services\Analysis\DashboardService;
use App\Services\Forecast\Tracking\ScenarioService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

class CohortProjectionService
{
    private const MIN_COHORTS_FOR_OWN_CURVE = 3;

    private const MIN_COHORT_SIZE = 10;

    public function __construct(
        private DashboardService $dashboard,
        private SalesBaselineService $forecast,
        private QuarterlyAovCalculator $aovCalculator,
        private ScenarioService $scenarioService,
    ) {}

    /**
     * Derive a baseline retention curve from historical cohort data.
     * Returns cumulative retention % at each month (1-12).
     *
     * When a region is provided, it builds a region-specific curve if enough data
     * exists (≥3 cohorts with ≥10 customers). Otherwise falls back to global.
     *
     * @return array{months: array<int, float>, cohorts_used: int, avg_cohort_size: float, source: string}
     */
    public function retentionCurve(int $cohortMonths = 12, ?ForecastRegion $region = null): array
    {
        if ($region !== null) {
            $regionalData = $this->buildCohortData($cohortMonths, $region);
            $qualifiedCohorts = collect($regionalData['cohorts'])
                ->filter(fn (array $c) => $c['size'] >= self::MIN_COHORT_SIZE);

            if ($qualifiedCohorts->count() >= self::MIN_COHORTS_FOR_OWN_CURVE) {
                $curve = $this->averageCurve($regionalData['cohorts']);

                return [
                    ...$curve,
                    'source' => "regional:{$region->value}",
                ];
            }

            Log::info("Retention curve fallback to global for {$region->label()}: only {$qualifiedCohorts->count()} qualified cohorts (need ".self::MIN_COHORTS_FOR_OWN_CURVE.')');

            // Fall back to global curve but report it
            $globalCurve = $this->retentionCurve($cohortMonths);

            return [
                ...$globalCurve,
                'source' => 'global_fallback',
            ];
        }

        // Global: use existing DashboardService path (cached)
        $data = $this->dashboard->cohortRetention($cohortMonths);

        return [
            ...$this->averageCurve($data['cohorts']),
            'source' => 'global',
        ];
    }

    /**
     * Build cohort retention data filtered by forecast region.
     * A customer belongs to a region based on their first order's shipping country.
     *
     * @return array{cohorts: array<int, array{cohort: string, size: int, retention: array<int, float>}>, max_months: int}
     */
    public function buildCohortData(int $cohortMonths, ?ForecastRegion $region = null): array
    {
        $since = CarbonImmutable::now()->subMonths($cohortMonths)->startOfMonth();

        $customerQuery = ShopifyCustomer::query()
            ->where('first_order_at', '>=', $since)
            ->whereNotNull('first_order_at')
            ->select('id', 'first_order_at');

        if ($region !== null) {
            // Filter customers by their first order's shipping country
            $customerQuery->whereExists(function ($query) use ($region) {
                $query->select('id')
                    ->from('shopify_orders')
                    ->whereColumn('shopify_orders.customer_id', 'shopify_customers.id')
                    ->whereColumn('shopify_orders.ordered_at', 'shopify_customers.first_order_at');

                $countries = $region->countries();
                if ($countries === []) {
                    $allMapped = collect(ForecastRegion::cases())
                        ->filter(fn (ForecastRegion $r) => $r !== ForecastRegion::Row)
                        ->flatMap(fn (ForecastRegion $r) => $r->countries())
                        ->all();
                    $query->whereNotIn('shopify_orders.shipping_country_code', $allMapped);
                } else {
                    $query->whereIn('shopify_orders.shipping_country_code', $countries);
                }
            });
        }

        $customers = $customerQuery->get();
        $customerIds = $customers->pluck('id');

        $orders = ShopifyOrder::query()
            ->whereIn('customer_id', $customerIds)
            ->select('customer_id', 'ordered_at')
            ->get()
            ->groupBy('customer_id');

        $cohorts = $customers->groupBy(fn ($c) => $c->first_order_at->format('Y-m'));

        $result = [];

        foreach ($cohorts->sortKeys() as $cohortMonth => $cohortCustomers) {
            $cohortStart = CarbonImmutable::parse($cohortMonth.'-01');
            $size = $cohortCustomers->count();
            $monthsSinceCohort = (int) $cohortStart->diffInMonths(CarbonImmutable::now());
            $maxMonth = min($monthsSinceCohort, 12);

            $retention = [];

            for ($m = 1; $m <= $maxMonth; $m++) {
                $cutoff = $cohortStart->addMonths($m);

                $retained = $cohortCustomers->filter(function ($customer) use ($orders, $cohortStart, $cutoff) {
                    $customerOrders = $orders->get($customer->id, collect());

                    return $customerOrders->contains(function ($order) use ($cohortStart, $cutoff) {
                        $orderDate = CarbonImmutable::parse($order->ordered_at);

                        return $orderDate > $cohortStart->endOfMonth() && $orderDate <= $cutoff->endOfMonth();
                    });
                })->count();

                $retention[$m] = $size > 0 ? round(($retained / $size) * 100, 1) : 0;
            }

            $result[] = [
                'cohort' => $cohortMonth,
                'size' => $size,
                'retention' => $retention,
            ];
        }

        return [
            'cohorts' => $result,
            'max_months' => 12,
        ];
    }

    /**
     * Get interpolated retention rate for a specific month age.
     * Linearly interpolates between known data points in the curve.
     *
     * @param  array<int, float>  $retentionCurve  Month → cumulative retention %
     */
    public function monthlyRetentionRate(int $monthsAge, array $retentionCurve): float
    {
        if (empty($retentionCurve) || $monthsAge < 1) {
            return 0.0;
        }

        if (isset($retentionCurve[$monthsAge])) {
            return $retentionCurve[$monthsAge];
        }

        $months = array_keys($retentionCurve);
        sort($months);

        // Before first data point: linear scale from zero
        if ($monthsAge < $months[0]) {
            return round($retentionCurve[$months[0]] * ($monthsAge / $months[0]), 2);
        }

        // After last data point: plateau at last known value
        $lastMonth = end($months);
        if ($monthsAge > $lastMonth) {
            return $retentionCurve[$lastMonth];
        }

        // Interpolate between surrounding points
        $lower = null;
        $upper = null;
        foreach ($months as $m) {
            if ($m <= $monthsAge) {
                $lower = $m;
            }
            if ($m >= $monthsAge && $upper === null) {
                $upper = $m;
            }
        }

        $ratio = ($monthsAge - $lower) / ($upper - $lower);

        return round($retentionCurve[$lower] + $ratio * ($retentionCurve[$upper] - $retentionCurve[$lower]), 2);
    }

    /**
     * Project cumulative revenue for a cohort over time.
     *
     * @param  int  $cohortSize  Number of new customers in the cohort
     * @param  float  $firstOrderAov  Average first order value
     * @param  float  $repeatAov  Average repeat order value
     * @param  array<int, float>  $retentionCurve  Month → cumulative retention % (from retentionCurve())
     * @param  int  $months  How many months to project
     * @return array<int, array{month: int, cumulative_repeaters: int, cumulative_repeat_revenue: float, cumulative_total_revenue: float}>
     */
    public function projectCohort(int $cohortSize, float $firstOrderAov, float $repeatAov, array $retentionCurve, int $months = 12): array
    {
        $firstOrderRevenue = round($cohortSize * $firstOrderAov, 2);
        $projection = [];

        for ($m = 1; $m <= $months; $m++) {
            $retentionPct = $retentionCurve[$m] ?? end($retentionCurve) ?: 0;
            $cumulativeRepeaters = (int) round($cohortSize * $retentionPct / 100);
            $cumulativeRepeatRevenue = round($cumulativeRepeaters * $repeatAov, 2);

            $projection[$m] = [
                'month' => $m,
                'cumulative_repeaters' => $cumulativeRepeaters,
                'cumulative_repeat_revenue' => $cumulativeRepeatRevenue,
                'cumulative_total_revenue' => $firstOrderRevenue + $cumulativeRepeatRevenue,
            ];
        }

        return $projection;
    }

    /**
     * Calculate predicted 12-month LTV from retention curve and age-aware AOV.
     * Combines first-order AOV + Σ(incremental_retention × age-appropriate AOV).
     *
     * @return array{predicted_ltv_12m: float, first_order_aov: float, predicted_repeat_value: float, retention_curve_source: string}
     */
    public function predictedLtv(?ForecastRegion $region = null, int $months = 12): array
    {
        $curveData = $this->retentionCurve($months, $region);
        $retentionCurve = $curveData['months'];

        if (empty($retentionCurve)) {
            return [
                'predicted_ltv_12m' => 0,
                'first_order_aov' => 0,
                'predicted_repeat_value' => 0,
                'retention_curve_source' => $curveData['source'] ?? 'none',
            ];
        }

        // Get first-order AOV from last 12 months
        $year = (int) date('Y');
        $acqActuals = $this->forecast->monthlyActuals(
            ($year - 1).'-01-01',
            $year.'-01-01',
            $region,
        );
        $totalAcqRev = collect($acqActuals)->sum('acq_rev');
        $totalNewCustomers = collect($acqActuals)->sum('new_customers');
        $firstOrderAov = $totalNewCustomers > 0 ? round($totalAcqRev / $totalNewCustomers, 2) : 0;

        // Get age-aware repeat AOV
        $aovByOrder = $this->aovCalculator->repeatAovByOrderNumber($region);

        // Sum incremental retention × effective AOV over the projection horizon
        $predictedRepeatValue = 0.0;
        for ($m = 1; $m <= $months; $m++) {
            $currentRetention = $this->monthlyRetentionRate($m, $retentionCurve);
            $previousRetention = $m > 1 ? $this->monthlyRetentionRate($m - 1, $retentionCurve) : 0;

            $incrementalPct = max(0, $currentRetention - $previousRetention);

            // Age-aware: young cohorts → 2nd-order AOV, mature → 3rd+ AOV
            $effectiveAov = $m <= 3
                ? $aovByOrder['second_order']
                : $aovByOrder['third_plus'];

            $predictedRepeatValue += ($incrementalPct / 100) * $effectiveAov;
        }

        $predictedRepeatValue = round($predictedRepeatValue, 2);

        return [
            'predicted_ltv_12m' => round($firstOrderAov + $predictedRepeatValue, 2),
            'first_order_aov' => $firstOrderAov,
            'predicted_repeat_value' => $predictedRepeatValue,
            'retention_curve_source' => $curveData['source'] ?? 'global',
        ];
    }

    /**
     * Project a full year by combining quarterly cohorts with a scenario.
     *
     * @return array{quarters: array, year_total: float, year_from_repeats: float}
     */
    public function projectYear(int $year, int $scenarioId): array
    {
        $scenario = Scenario::findOrFail($scenarioId);
        $forecastInput = $this->scenarioService->toForecastInput($scenario);
        $scenarioResult = $this->forecast->calculateScenario($forecastInput);

        $curve = $this->retentionCurve();
        $retentionMonths = $curve['months'];

        // Get repeat AOV from rolling quarterly actuals (seasonal-aware, discount-adjusted)
        $quarterlyAov = $this->aovCalculator->repeatAovByQuarter($year);
        $avgRepeatAov = collect($quarterlyAov)->map(fn ($v) => $v['normalized'] ?? 0)->filter(fn ($v) => $v > 0)->avg() ?: 0;

        // Final fallback: calculate from monthly actuals if dynamic AOV unavailable
        if ($avgRepeatAov <= 0) {
            $recentActuals = $this->forecast->monthlyActuals($year.'-01-01', ($year + 1).'-01-01');
            $avgRepeatAov = collect($recentActuals)->where('repeat_orders', '>', 0)->avg('rep_aov') ?: 0;
        }

        $quarters = [];
        $yearFromRepeats = 0;

        foreach ($scenarioResult['quarters'] as $qName => $q) {
            $cohortSize = $q['new_cust'];
            $acqRevenue = $q['acq_rev'];
            $firstAov = $cohortSize > 0 ? $acqRevenue / $cohortSize : 0;

            // How many months does this cohort have to produce repeats within the year?
            $monthsRemaining = match ($qName) {
                'Q1' => 9,  // Q1 cohort has 9 months of repeat opportunity in-year
                'Q2' => 6,
                'Q3' => 3,
                'Q4' => 0,  // Q4 cohort: no in-year repeat time
                default => 0,
            };

            $projectedRepeatRevenue = 0;
            if ($monthsRemaining > 0 && ! empty($retentionMonths)) {
                $projection = $this->projectCohort($cohortSize, $firstAov, $avgRepeatAov, $retentionMonths, $monthsRemaining);
                $lastMonth = end($projection);
                $projectedRepeatRevenue = $lastMonth['cumulative_repeat_revenue'] ?? 0;
            }

            $quarters[$qName] = [
                'cohort_size' => $cohortSize,
                'acq_revenue' => $acqRevenue,
                'projected_repeat_revenue' => round($projectedRepeatRevenue, 2),
                'months_for_repeats' => $monthsRemaining,
            ];

            $yearFromRepeats += $projectedRepeatRevenue;
        }

        $yearTotal = collect($quarters)->sum('acq_revenue') + $yearFromRepeats;

        return [
            'quarters' => $quarters,
            'year_total' => round($yearTotal, 2),
            'year_from_repeats' => round($yearFromRepeats, 2),
        ];
    }

    /**
     * Compare actual cohort performance vs projected.
     *
     * @return array<int, array{cohort: string, size: int, actual_retention_3m: float, projected_retention_3m: float, delta: float}>
     */
    public function compareActualVsProjected(): array
    {
        $data = $this->dashboard->cohortRetention(12);
        $curve = $this->retentionCurve();
        $retentionMonths = $curve['months'];

        $comparisons = [];

        foreach ($data['cohorts'] as $cohort) {
            // Only compare cohorts that are at least 3 months old
            if (! isset($cohort['retention'][3])) {
                continue;
            }

            $actual3m = $cohort['retention'][3];
            $projected3m = $retentionMonths[3] ?? 0;

            $comparisons[] = [
                'cohort' => $cohort['cohort'],
                'size' => $cohort['size'],
                'actual_retention_3m' => $actual3m,
                'projected_retention_3m' => $projected3m,
                'delta' => round($actual3m - $projected3m, 2),
            ];
        }

        return $comparisons;
    }

    /**
     * Average retention across all cohorts for each month-age.
     *
     * @param  array<int, array{cohort: string, size: int, retention: array<int, float>}>  $cohorts
     * @return array{months: array<int, float>, cohorts_used: int, avg_cohort_size: float}
     */
    private function averageCurve(array $cohorts): array
    {
        if (empty($cohorts)) {
            return ['months' => [], 'cohorts_used' => 0, 'avg_cohort_size' => 0];
        }

        $monthSums = [];
        $monthCounts = [];

        foreach ($cohorts as $cohort) {
            foreach ($cohort['retention'] as $month => $pct) {
                $monthSums[$month] = ($monthSums[$month] ?? 0) + $pct;
                $monthCounts[$month] = ($monthCounts[$month] ?? 0) + 1;
            }
        }

        $avgRetention = [];
        foreach ($monthSums as $month => $sum) {
            $avgRetention[$month] = round($sum / $monthCounts[$month], 2);
        }

        ksort($avgRetention);

        return [
            'months' => $avgRetention,
            'cohorts_used' => count($cohorts),
            'avg_cohort_size' => round(collect($cohorts)->avg('size'), 0),
        ];
    }
}
