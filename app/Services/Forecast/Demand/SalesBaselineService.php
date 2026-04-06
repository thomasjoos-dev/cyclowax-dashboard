<?php

namespace App\Services\Forecast\Demand;

use App\Enums\ForecastRegion;
use App\Support\DbDialect;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SalesBaselineService
{
    /**
     * Actuals for any period, split into acquisition vs repeat.
     *
     * @return array{total_rev: int, acq_rev: int, rep_rev: int, new_customers: int, repeat_orders: int}
     */
    public function periodActuals(string $from, string $to, ?ForecastRegion $region = null): array
    {
        $bindings = [$from, $to];
        $regionFilter = $this->regionWhereClause($region, $bindings);

        $r = DB::selectOne("
            SELECT
                ROUND(COALESCE(SUM(net_revenue), 0), 0) as total_rev,
                ROUND(COALESCE(SUM(CASE WHEN is_first_order IS TRUE THEN net_revenue ELSE 0 END), 0), 0) as acq_rev,
                ROUND(COALESCE(SUM(CASE WHEN is_first_order IS NOT TRUE THEN net_revenue ELSE 0 END), 0), 0) as rep_rev,
                COALESCE(SUM(CASE WHEN is_first_order IS TRUE THEN 1 ELSE 0 END), 0) as new_customers,
                COALESCE(SUM(CASE WHEN is_first_order IS NOT TRUE THEN 1 ELSE 0 END), 0) as repeat_orders
            FROM shopify_orders
            WHERE ordered_at >= ? AND ordered_at < ?
                AND financial_status NOT IN ('voided', 'refunded')
                {$regionFilter}
        ", $bindings);

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
    public function monthlyActuals(string $from, string $to, ?ForecastRegion $region = null): array
    {
        $bindings = [$from, $to];
        $regionFilter = $this->regionWhereClause($region, $bindings);

        $rows = DB::select('
            SELECT
                '.DbDialect::yearMonthExpr('ordered_at')." as month,
                ROUND(SUM(net_revenue), 0) as total_rev,
                ROUND(SUM(CASE WHEN is_first_order IS TRUE THEN net_revenue ELSE 0 END), 0) as acq_rev,
                ROUND(SUM(CASE WHEN is_first_order IS NOT TRUE THEN net_revenue ELSE 0 END), 0) as rep_rev,
                SUM(CASE WHEN is_first_order IS TRUE THEN 1 ELSE 0 END) as new_customers,
                SUM(CASE WHEN is_first_order IS NOT TRUE THEN 1 ELSE 0 END) as repeat_orders
            FROM shopify_orders
            WHERE ordered_at >= ? AND ordered_at < ?
                AND financial_status NOT IN ('voided', 'refunded')
                {$regionFilter}
            GROUP BY month
            ORDER BY month
        ", $bindings);

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
    public function yearRevenue(int $year, ?ForecastRegion $region = null): float
    {
        $from = $year.'-01-01';
        $to = ($year + 1).'-01-01';
        $bindings = [$from, $to];
        $regionFilter = $this->regionWhereClause($region, $bindings);

        return (float) DB::selectOne("
            SELECT ROUND(COALESCE(SUM(net_revenue), 0), 0) as rev
            FROM shopify_orders
            WHERE ordered_at >= ? AND ordered_at < ?
                AND financial_status NOT IN ('voided', 'refunded')
                {$regionFilter}
        ", $bindings)->rev;
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

    /**
     * Calculate acquisition AOV per quarter from rolling actuals.
     * Same pattern as repeatAovByQuarter: 6-month rolling window, both actual and normalized.
     * Used to make acquisition revenue = customers × AOV instead of lump revenue × growth rate.
     *
     * @return array<string, array{actual: float, normalized: float}> Quarter => AOV variants
     */
    public function acqAovByQuarter(int $year, ?ForecastRegion $region = null): array
    {
        $quarterMonths = [
            'Q1' => [1, 2, 3],
            'Q2' => [4, 5, 6],
            'Q3' => [7, 8, 9],
            'Q4' => [10, 11, 12],
        ];

        $from = ($year - 1).'-07-01';
        $to = ($year + 1).'-01-01';
        $bindings = [$from, $to];
        $regionFilter = $this->regionWhereClause($region, $bindings);

        $monthExpr = DbDialect::monthExpr('ordered_at');
        $normalizedExpr = $this->discountAdjustedRevenueExpr();

        $rows = DB::select("
            SELECT
                {$monthExpr} as order_month,
                SUM(net_revenue) as total_rev,
                SUM({$normalizedExpr}) as normalized_rev,
                COUNT(*) as order_count
            FROM shopify_orders
            WHERE ordered_at >= ? AND ordered_at < ?
                AND is_first_order IS TRUE
                AND financial_status NOT IN ('voided', 'refunded')
                {$regionFilter}
            GROUP BY order_month
        ", $bindings);

        $monthlyData = collect($rows)->keyBy('order_month');

        $totalRev = $monthlyData->sum('total_rev');
        $totalNormalized = $monthlyData->sum('normalized_rev');
        $totalOrders = $monthlyData->sum('order_count');
        $fallbackActual = $totalOrders > 0 ? round($totalRev / $totalOrders, 2) : 0;
        $fallbackNormalized = $totalOrders > 0 ? round($totalNormalized / $totalOrders, 2) : 0;

        $result = [];
        foreach ($quarterMonths as $quarter => $months) {
            $qRev = 0;
            $qNormalized = 0;
            $qOrders = 0;

            $windowMonths = array_unique(array_merge(
                $months,
                [min(12, max(1, $months[0] - 1))],
                [min(12, max(1, end($months) + 1))],
            ));

            foreach ($windowMonths as $m) {
                $data = $monthlyData->get($m);
                if ($data) {
                    $qRev += (float) $data->total_rev;
                    $qNormalized += (float) $data->normalized_rev;
                    $qOrders += (int) $data->order_count;
                }
            }

            $result[$quarter] = [
                'actual' => $qOrders >= 5 ? round($qRev / $qOrders, 2) : $fallbackActual,
                'normalized' => $qOrders >= 5 ? round($qNormalized / $qOrders, 2) : $fallbackNormalized,
            ];
        }

        return $result;
    }

    /**
     * Calculate repeat AOV per quarter from rolling actuals.
     * Uses a 6-month rolling window centred on each quarter for seasonal accuracy.
     * Falls back to 12-month average if a quarter has insufficient data.
     *
     * Returns both actual AOV (includes discounts) and normalized AOV (discount-adjusted)
     * so the forecast can use discount-free pricing while tracking shows real AOV.
     *
     * @return array<string, array{actual: float, normalized: float}> Quarter => AOV variants
     */
    public function repeatAovByQuarter(int $year, ?ForecastRegion $region = null): array
    {
        $quarterMonths = [
            'Q1' => [1, 2, 3],
            'Q2' => [4, 5, 6],
            'Q3' => [7, 8, 9],
            'Q4' => [10, 11, 12],
        ];

        // Fetch 18 months of data (current year + 6 months prior) for rolling window
        $from = ($year - 1).'-07-01';
        $to = ($year + 1).'-01-01';
        $bindings = [$from, $to];
        $regionFilter = $this->regionWhereClause($region, $bindings);

        $monthExpr = DbDialect::monthExpr('ordered_at');
        $normalizedExpr = $this->discountAdjustedRevenueExpr();

        $rows = DB::select("
            SELECT
                {$monthExpr} as order_month,
                SUM(net_revenue) as total_rev,
                SUM({$normalizedExpr}) as normalized_rev,
                COUNT(*) as order_count
            FROM shopify_orders
            WHERE ordered_at >= ? AND ordered_at < ?
                AND is_first_order IS NOT TRUE
                AND financial_status NOT IN ('voided', 'refunded')
                {$regionFilter}
            GROUP BY order_month
        ", $bindings);

        $monthlyData = collect($rows)->keyBy('order_month');

        // 12-month fallback
        $totalRev = $monthlyData->sum('total_rev');
        $totalNormalized = $monthlyData->sum('normalized_rev');
        $totalOrders = $monthlyData->sum('order_count');
        $fallbackActual = $totalOrders > 0 ? round($totalRev / $totalOrders, 2) : 0;
        $fallbackNormalized = $totalOrders > 0 ? round($totalNormalized / $totalOrders, 2) : 0;

        $result = [];
        foreach ($quarterMonths as $quarter => $months) {
            $qRev = 0;
            $qNormalized = 0;
            $qOrders = 0;

            // Rolling window: the quarter's own months + 1 month before and after
            $windowMonths = array_unique(array_merge(
                $months,
                [min(12, max(1, $months[0] - 1))],
                [min(12, max(1, end($months) + 1))],
            ));

            foreach ($windowMonths as $m) {
                $data = $monthlyData->get($m);
                if ($data) {
                    $qRev += (float) $data->total_rev;
                    $qNormalized += (float) $data->normalized_rev;
                    $qOrders += (int) $data->order_count;
                }
            }

            $result[$quarter] = [
                'actual' => $qOrders >= 5 ? round($qRev / $qOrders, 2) : $fallbackActual,
                'normalized' => $qOrders >= 5 ? round($qNormalized / $qOrders, 2) : $fallbackNormalized,
            ];
        }

        return $result;
    }

    /**
     * Calculate repeat AOV split by order number (2nd order vs 3rd+).
     * The 2nd order is typically a different basket (kits, heaters) than subsequent
     * orders which shift towards consumables (wax, chain wear).
     *
     * Returns both actual and normalized (discount-adjusted) AOV per group.
     *
     * @return array{second_order: float, third_plus: float, overall: float}
     */
    public function repeatAovByOrderNumber(?ForecastRegion $region = null): array
    {
        $from = now()->subMonths(12)->toDateString();
        $normalizedExpr = $this->discountAdjustedRevenueExpr('o');

        // Build region filter for the subquery (uses table alias 'o')
        $bindings = [$from];
        $regionFilterRaw = '';
        if ($region !== null) {
            $countries = $region->countries();

            if ($countries === []) {
                $allMapped = collect(ForecastRegion::cases())
                    ->filter(fn (ForecastRegion $r) => $r !== ForecastRegion::Row)
                    ->flatMap(fn (ForecastRegion $r) => $r->countries())
                    ->all();

                $placeholders = implode(',', array_fill(0, count($allMapped), '?'));
                $bindings = array_merge($bindings, $allMapped);
                $regionFilterRaw = "AND (o.shipping_country_code NOT IN ({$placeholders}) OR o.shipping_country_code IS NULL)";
            } else {
                $placeholders = implode(',', array_fill(0, count($countries), '?'));
                $bindings = array_merge($bindings, $countries);
                $regionFilterRaw = "AND o.shipping_country_code IN ({$placeholders})";
            }
        }

        $rows = DB::select("
            SELECT
                CASE
                    WHEN order_sequence = 2 THEN 'second_order'
                    ELSE 'third_plus'
                END as order_group,
                AVG(net_revenue) as avg_aov,
                AVG(normalized_revenue) as avg_normalized_aov,
                COUNT(*) as order_count
            FROM (
                SELECT
                    o.net_revenue,
                    {$normalizedExpr} as normalized_revenue,
                    ROW_NUMBER() OVER (PARTITION BY o.customer_id ORDER BY o.ordered_at) as order_sequence
                FROM shopify_orders o
                WHERE o.ordered_at >= ?
                    AND o.financial_status NOT IN ('voided', 'refunded')
                    {$regionFilterRaw}
            ) ranked
            WHERE order_sequence >= 2
            GROUP BY order_group
        ", $bindings);

        $groups = collect($rows)->keyBy('order_group');

        $secondAov = round((float) ($groups->get('second_order')?->avg_aov ?? 0), 2);
        $thirdAov = round((float) ($groups->get('third_plus')?->avg_aov ?? 0), 2);

        // Overall weighted average for fallback
        $totalRev = $groups->sum(fn ($g) => (float) $g->avg_aov * (int) $g->order_count);
        $totalOrders = $groups->sum(fn ($g) => (int) $g->order_count);
        $overall = $totalOrders > 0 ? round($totalRev / $totalOrders, 2) : 0;

        return [
            'second_order' => $secondAov ?: $overall,
            'third_plus' => $thirdAov ?: $overall,
            'overall' => $overall,
        ];
    }

    /**
     * Return rate per quarter: percentage of orders with partial refunds
     * and average refund amount. Logs a warning if a quarter's return rate
     * deviates more than 5 percentage points from the trailing 12-month average.
     *
     * @return array<string, array{return_rate: float, avg_refund: float, order_count: int, refunded_count: int}>
     */
    public function returnRateByQuarter(int $year, ?ForecastRegion $region = null): array
    {
        $quarterDates = [
            'Q1' => [$year.'-01-01', $year.'-04-01'],
            'Q2' => [$year.'-04-01', $year.'-07-01'],
            'Q3' => [$year.'-07-01', $year.'-10-01'],
            'Q4' => [$year.'-10-01', ($year + 1).'-01-01'],
        ];

        // Trailing 12-month average for comparison
        $trailingFrom = ($year - 1).'-01-01';
        $trailingTo = $year.'-01-01';
        $trailingBindings = [$trailingFrom, $trailingTo];
        $trailingRegion = $this->regionWhereClause($region, $trailingBindings);

        $trailing = DB::selectOne("
            SELECT
                COUNT(*) as total,
                SUM(CASE WHEN refunded > 0 THEN 1 ELSE 0 END) as refunded_count
            FROM shopify_orders
            WHERE ordered_at >= ? AND ordered_at < ?
                AND financial_status NOT IN ('voided', 'refunded')
                {$trailingRegion}
        ", $trailingBindings);

        $trailingTotal = (int) ($trailing->total ?? 0);
        $trailingRefunded = (int) ($trailing->refunded_count ?? 0);
        $trailingRate = $trailingTotal > 0 ? $trailingRefunded / $trailingTotal * 100 : 0;

        $result = [];
        foreach ($quarterDates as $quarter => [$from, $to]) {
            $bindings = [$from, $to];
            $regionFilter = $this->regionWhereClause($region, $bindings);

            $row = DB::selectOne("
                SELECT
                    COUNT(*) as total,
                    SUM(CASE WHEN refunded > 0 THEN 1 ELSE 0 END) as refunded_count,
                    ROUND(AVG(CASE WHEN refunded > 0 THEN refunded END), 2) as avg_refund
                FROM shopify_orders
                WHERE ordered_at >= ? AND ordered_at < ?
                    AND financial_status NOT IN ('voided', 'refunded')
                    {$regionFilter}
            ", $bindings);

            $total = (int) ($row->total ?? 0);
            $refundedCount = (int) ($row->refunded_count ?? 0);
            $rate = $total > 0 ? round($refundedCount / $total * 100, 1) : 0;

            if ($trailingRate > 0 && abs($rate - $trailingRate) > 5.0) {
                Log::warning('Return rate deviation from trailing average', [
                    'quarter' => $quarter,
                    'region' => $region?->value ?? 'global',
                    'quarter_rate' => $rate,
                    'trailing_rate' => round($trailingRate, 1),
                    'delta_pp' => round($rate - $trailingRate, 1),
                ]);
            }

            $result[$quarter] = [
                'return_rate' => $rate,
                'avg_refund' => (float) ($row->avg_refund ?? 0),
                'order_count' => $total,
                'refunded_count' => $refundedCount,
            ];
        }

        return $result;
    }

    /**
     * Return rate per product category over the last 12 months.
     * Joins through line items to attribute partial refunds to categories.
     *
     * @return array<string, array{return_rate: float, avg_refund: float, order_count: int}>
     */
    public function returnRateByCategory(int $year, ?ForecastRegion $region = null): array
    {
        $from = $year.'-01-01';
        $to = ($year + 1).'-01-01';
        $bindings = [$from, $to];
        $regionFilter = $this->regionWhereClause($region, $bindings);

        $rows = DB::select("
            SELECT
                p.product_category,
                COUNT(DISTINCT so.id) as order_count,
                COUNT(DISTINCT CASE WHEN so.refunded > 0 THEN so.id END) as refunded_count,
                ROUND(AVG(CASE WHEN so.refunded > 0 THEN so.refunded END), 2) as avg_refund
            FROM shopify_orders so
            JOIN shopify_line_items sli ON sli.order_id = so.id
            JOIN products p ON p.id = sli.product_id
            WHERE so.ordered_at >= ? AND so.ordered_at < ?
                AND so.financial_status NOT IN ('voided', 'refunded')
                AND p.product_category IS NOT NULL
                {$regionFilter}
            GROUP BY p.product_category
        ", $bindings);

        $result = [];
        foreach ($rows as $row) {
            $total = (int) $row->order_count;
            $refunded = (int) $row->refunded_count;

            $result[$row->product_category] = [
                'return_rate' => $total > 0 ? round($refunded / $total * 100, 1) : 0,
                'avg_refund' => (float) ($row->avg_refund ?? 0),
                'order_count' => $total,
            ];
        }

        return $result;
    }

    /**
     * Gross AOV per quarter (before refunds): net_revenue + refunded.
     * Compare with net AOV to see how much return-driven AOV reduction occurs.
     *
     * @return array<string, array{gross_aov: float, net_aov: float, refund_impact: float}>
     */
    public function grossAovByQuarter(int $year, ?ForecastRegion $region = null): array
    {
        $quarterMonths = [
            'Q1' => [1, 2, 3],
            'Q2' => [4, 5, 6],
            'Q3' => [7, 8, 9],
            'Q4' => [10, 11, 12],
        ];

        $from = $year.'-01-01';
        $to = ($year + 1).'-01-01';
        $bindings = [$from, $to];
        $regionFilter = $this->regionWhereClause($region, $bindings);

        $monthExpr = DbDialect::monthExpr('ordered_at');

        $rows = DB::select("
            SELECT
                {$monthExpr} as order_month,
                SUM(net_revenue) as net_rev,
                SUM(net_revenue + COALESCE(refunded, 0)) as gross_rev,
                COUNT(*) as order_count
            FROM shopify_orders
            WHERE ordered_at >= ? AND ordered_at < ?
                AND is_first_order IS NOT TRUE
                AND financial_status NOT IN ('voided', 'refunded')
                {$regionFilter}
            GROUP BY order_month
        ", $bindings);

        $monthlyData = collect($rows)->keyBy('order_month');

        $result = [];
        foreach ($quarterMonths as $quarter => $months) {
            $netRev = 0;
            $grossRev = 0;
            $orders = 0;

            foreach ($months as $m) {
                $data = $monthlyData->get($m);
                if ($data) {
                    $netRev += (float) $data->net_rev;
                    $grossRev += (float) $data->gross_rev;
                    $orders += (int) $data->order_count;
                }
            }

            $grossAov = $orders > 0 ? round($grossRev / $orders, 2) : 0;
            $netAov = $orders > 0 ? round($netRev / $orders, 2) : 0;

            $result[$quarter] = [
                'gross_aov' => $grossAov,
                'net_aov' => $netAov,
                'refund_impact' => round($grossAov - $netAov, 2),
            ];
        }

        return $result;
    }

    /**
     * Discount rate diagnostics: percentage of orders with non-zero discounts
     * and average discount amount, for visibility into promo impact on AOV.
     *
     * @return array{discount_rate: float, avg_discount: float, orders_with_discount: int, total_orders: int}
     */
    public function discountRate(?ForecastRegion $region = null): array
    {
        $from = now()->subMonths(12)->toDateString();
        $bindings = [$from];
        $regionFilter = $this->regionWhereClause($region, $bindings);

        $row = DB::selectOne("
            SELECT
                COUNT(*) as total_orders,
                SUM(CASE WHEN discounts > 0 THEN 1 ELSE 0 END) as orders_with_discount,
                ROUND(AVG(CASE WHEN discounts > 0 THEN discounts END), 2) as avg_discount
            FROM shopify_orders
            WHERE ordered_at >= ?
                AND financial_status NOT IN ('voided', 'refunded')
                {$regionFilter}
        ", $bindings);

        $total = (int) $row->total_orders;
        $withDiscount = (int) $row->orders_with_discount;

        return [
            'discount_rate' => $total > 0 ? round($withDiscount / $total * 100, 1) : 0,
            'avg_discount' => (float) ($row->avg_discount ?? 0),
            'orders_with_discount' => $withDiscount,
            'total_orders' => $total,
        ];
    }

    /**
     * SQL expression for discount-adjusted revenue: what the customer would have
     * paid without the discount (net_revenue + discounts).
     *
     * @param  string|null  $alias  Table alias prefix (e.g. 'o' → 'o.net_revenue')
     */
    private function discountAdjustedRevenueExpr(?string $alias = null): string
    {
        $prefix = $alias ? "{$alias}." : '';

        return "({$prefix}net_revenue + COALESCE({$prefix}discounts, 0))";
    }

    /**
     * Build a WHERE clause fragment to filter orders by forecast region.
     * Appends country codes to the bindings array.
     *
     * @param  array<int, mixed>  $bindings
     */
    private function regionWhereClause(?ForecastRegion $region, array &$bindings): string
    {
        if ($region === null) {
            return '';
        }

        $countries = $region->countries();

        if ($countries === []) {
            // ROW: exclude all mapped countries
            $allMapped = collect(ForecastRegion::cases())
                ->filter(fn (ForecastRegion $r) => $r !== ForecastRegion::Row)
                ->flatMap(fn (ForecastRegion $r) => $r->countries())
                ->all();

            $placeholders = implode(',', array_fill(0, count($allMapped), '?'));
            $bindings = array_merge($bindings, $allMapped);

            return "AND (shipping_country_code NOT IN ({$placeholders}) OR shipping_country_code IS NULL)";
        }

        $placeholders = implode(',', array_fill(0, count($countries), '?'));
        $bindings = array_merge($bindings, $countries);

        return "AND shipping_country_code IN ({$placeholders})";
    }
}
