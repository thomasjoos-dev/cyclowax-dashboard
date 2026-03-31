<?php

namespace App\Services\Forecast;

use App\Models\Scenario;
use App\Services\Analysis\DashboardService;

class CohortProjectionService
{
    public function __construct(
        private DashboardService $dashboard,
        private ForecastService $forecast,
        private ScenarioService $scenarioService,
    ) {}

    /**
     * Derive a baseline retention curve from historical cohort data.
     * Returns cumulative retention % at each month (1-12).
     *
     * @return array{months: array<int, float>, cohorts_used: int, avg_cohort_size: float}
     */
    public function retentionCurve(int $cohortMonths = 12): array
    {
        $data = $this->dashboard->cohortRetention($cohortMonths);
        $cohorts = $data['cohorts'];

        if (empty($cohorts)) {
            return ['months' => [], 'cohorts_used' => 0, 'avg_cohort_size' => 0];
        }

        // Average retention across all cohorts for each month
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

        // Get repeat AOV from recent data
        $recentActuals = $this->forecast->monthlyActuals($year.'-01-01', ($year + 1).'-01-01');
        $avgRepeatAov = collect($recentActuals)->where('repeat_orders', '>', 0)->avg('rep_aov') ?: 95;

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
}
