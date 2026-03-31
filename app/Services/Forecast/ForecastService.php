<?php

namespace App\Services\Forecast;

use App\Support\DbDialect;
use Illuminate\Support\Facades\DB;

class ForecastService
{
    /**
     * Actuals for any period, split into acquisition vs repeat.
     *
     * @return array{total_rev: int, acq_rev: int, rep_rev: int, new_customers: int, repeat_orders: int}
     */
    public function periodActuals(string $from, string $to): array
    {
        $r = DB::selectOne('
            SELECT
                ROUND(COALESCE(SUM(net_revenue), 0), 0) as total_rev,
                ROUND(COALESCE(SUM(CASE WHEN is_first_order = 1 THEN net_revenue ELSE 0 END), 0), 0) as acq_rev,
                ROUND(COALESCE(SUM(CASE WHEN is_first_order = 0 THEN net_revenue ELSE 0 END), 0), 0) as rep_rev,
                COALESCE(SUM(CASE WHEN is_first_order = 1 THEN 1 ELSE 0 END), 0) as new_customers,
                COALESCE(SUM(CASE WHEN is_first_order = 0 THEN 1 ELSE 0 END), 0) as repeat_orders
            FROM shopify_orders
            WHERE ordered_at >= ? AND ordered_at < ?
                AND financial_status NOT IN (\'voided\', \'refunded\')
        ', [$from, $to]);

        return [
            'total_rev' => (int) $r->total_rev,
            'acq_rev' => (int) $r->acq_rev,
            'rep_rev' => (int) $r->rep_rev,
            'new_customers' => (int) $r->new_customers,
            'repeat_orders' => (int) $r->repeat_orders,
        ];
    }

    /**
     * Monthly actuals within a period, for dashboard chart plotting.
     *
     * @return array<int, array{month: string, total_rev: float, acq_rev: float, rep_rev: float, new_customers: int, repeat_orders: int, acq_aov: float, rep_aov: float}>
     */
    public function monthlyActuals(string $from, string $to): array
    {
        $rows = DB::select('
            SELECT
                '.DbDialect::yearMonthExpr('ordered_at')." as month,
                ROUND(SUM(net_revenue), 0) as total_rev,
                ROUND(SUM(CASE WHEN is_first_order = 1 THEN net_revenue ELSE 0 END), 0) as acq_rev,
                ROUND(SUM(CASE WHEN is_first_order = 0 THEN net_revenue ELSE 0 END), 0) as rep_rev,
                SUM(CASE WHEN is_first_order = 1 THEN 1 ELSE 0 END) as new_customers,
                SUM(CASE WHEN is_first_order = 0 THEN 1 ELSE 0 END) as repeat_orders
            FROM shopify_orders
            WHERE ordered_at >= ? AND ordered_at < ?
                AND financial_status NOT IN ('voided', 'refunded')
            GROUP BY month
            ORDER BY month
        ", [$from, $to]);

        return array_map(fn ($r) => [
            'month' => $r->month,
            'total_rev' => (float) $r->total_rev,
            'acq_rev' => (float) $r->acq_rev,
            'rep_rev' => (float) $r->rep_rev,
            'new_customers' => (int) $r->new_customers,
            'repeat_orders' => (int) $r->repeat_orders,
            'acq_aov' => (int) $r->new_customers > 0 ? round((float) $r->acq_rev / (int) $r->new_customers, 2) : 0,
            'rep_aov' => (int) $r->repeat_orders > 0 ? round((float) $r->rep_rev / (int) $r->repeat_orders, 2) : 0,
        ], $rows);
    }

    /**
     * Total net revenue for a full calendar year.
     */
    public function yearRevenue(int $year): float
    {
        $from = $year.'-01-01';
        $to = ($year + 1).'-01-01';

        return (float) DB::selectOne('
            SELECT ROUND(COALESCE(SUM(net_revenue), 0), 0) as rev
            FROM shopify_orders
            WHERE ordered_at >= ? AND ordered_at < ?
                AND financial_status NOT IN (\'voided\', \'refunded\')
        ', [$from, $to])->rev;
    }

    /**
     * Calculate a single forecast scenario based on quarterly assumptions.
     *
     * Each quarter in $quarters should contain:
     * - acq_rate: float — factor relative to baseline (e.g. 0.70 = 70% of baseline)
     * - repeat_rate: float — expected repeat rate (e.g. 0.20 = 20%)
     * - repeat_aov: float — average order value for repeat orders
     *
     * Q1 is always taken from $baselineActuals (actuals).
     * If $baselineActuals is null, it defaults to Q1 of the current year.
     *
     * @param  array<string, array{acq_rate: float, repeat_rate: float, repeat_aov: float}>  $quarters  Keys: Q2, Q3, Q4
     * @param  array{total_rev: int, acq_rev: int, rep_rev: int, new_customers: int, repeat_orders: int}|null  $baselineActuals
     * @return array{quarters: array, totals: array{new_cust: int, acq_total: int, rep_orders: int, rep_total: int, total: int}}
     */
    public function calculateScenario(array $quarters, ?array $baselineActuals = null): array
    {
        if ($baselineActuals === null) {
            $year = (int) date('Y');
            $baselineActuals = $this->periodActuals($year.'-01-01', $year.'-04-01');
        }

        $baselineAcqRev = $baselineActuals['acq_rev'];
        $baselineNewCust = $baselineActuals['new_customers'];

        // Q1 is always actuals
        $result = [
            'Q1' => [
                'new_cust' => $baselineActuals['new_customers'],
                'acq_rev' => $baselineActuals['acq_rev'],
                'rep_orders' => $baselineActuals['repeat_orders'],
                'rep_rev' => $baselineActuals['rep_rev'],
            ],
        ];

        // Cumulative customer base for repeat calculation
        $cumulativeCustomers = $baselineNewCust;

        foreach (['Q2', 'Q3', 'Q4'] as $q) {
            if (! isset($quarters[$q])) {
                continue;
            }

            $assumptions = $quarters[$q];
            $acqRate = $assumptions['acq_rate'];
            $repeatRate = $assumptions['repeat_rate'];
            $repeatAov = $assumptions['repeat_aov'];

            $newCust = (int) round($baselineNewCust * $acqRate);
            $acqRev = (int) round($baselineAcqRev * $acqRate);

            // Repeat orders based on cumulative customer pool
            $repOrders = (int) round($cumulativeCustomers * $repeatRate);
            $repRev = (int) round($repOrders * $repeatAov);

            $result[$q] = [
                'new_cust' => $newCust,
                'acq_rev' => $acqRev,
                'rep_orders' => $repOrders,
                'rep_rev' => $repRev,
            ];

            $cumulativeCustomers += $newCust;
        }

        // Calculate totals
        $totals = [
            'new_cust' => collect($result)->sum('new_cust'),
            'acq_total' => collect($result)->sum('acq_rev'),
            'rep_orders' => collect($result)->sum('rep_orders'),
            'rep_total' => collect($result)->sum('rep_rev'),
        ];
        $totals['total'] = $totals['acq_total'] + $totals['rep_total'];

        return [
            'quarters' => $result,
            'totals' => $totals,
        ];
    }

    /**
     * Compare actuals vs a scenario projection for completed quarters.
     *
     * @return array<string, array{projected_rev: int, actual_rev: int, delta: int, delta_pct: float, status: string}>
     */
    public function compareActualsVsProjected(array $scenarioResult, int $year): array
    {
        $quarterDates = [
            'Q1' => [$year.'-01-01', $year.'-04-01'],
            'Q2' => [$year.'-04-01', $year.'-07-01'],
            'Q3' => [$year.'-07-01', $year.'-10-01'],
            'Q4' => [$year.'-10-01', ($year + 1).'-01-01'],
        ];

        $comparison = [];

        foreach ($scenarioResult['quarters'] as $q => $projected) {
            [$from, $to] = $quarterDates[$q];

            // Only compare quarters that have started
            if ($from > date('Y-m-d')) {
                continue;
            }

            $actuals = $this->periodActuals($from, $to);
            $projectedRev = $projected['acq_rev'] + $projected['rep_rev'];
            $actualRev = $actuals['total_rev'];
            $delta = $actualRev - $projectedRev;
            $deltaPct = $projectedRev > 0 ? round($delta * 100 / $projectedRev, 1) : 0;

            $status = 'on_track';
            if ($deltaPct < -10) {
                $status = 'behind';
            } elseif ($deltaPct > 10) {
                $status = 'ahead';
            }

            $comparison[$q] = [
                'projected_rev' => $projectedRev,
                'actual_rev' => $actualRev,
                'delta' => $delta,
                'delta_pct' => $deltaPct,
                'status' => $status,
            ];
        }

        return $comparison;
    }
}
