<?php

namespace App\Services\Forecast\Demand;

use App\Enums\ForecastRegion;
use App\Support\DbDialect;
use Illuminate\Support\Facades\DB;

class QuarterlyAovCalculator
{
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
