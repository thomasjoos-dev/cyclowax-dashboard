<?php

namespace App\Services\Analysis;

use App\Support\DbDialect;
use Illuminate\Support\Facades\DB;

class DtcSalesQueryService
{
    /**
     * Aggregate order totals for a date range.
     */
    public function orderTotals(string $from, string $to): object
    {
        return DB::selectOne("
            SELECT
                COUNT(*) as total_orders,
                ROUND(SUM(total_price), 2) as gross_revenue,
                ROUND(SUM(net_revenue), 2) as net_revenue,
                ROUND(SUM(total_cost), 2) as total_cost,
                ROUND(SUM(gross_margin), 2) as gross_margin,
                ROUND(SUM(discounts), 2) as total_discounts,
                ROUND(SUM(refunded), 2) as total_refunded,
                COUNT(CASE WHEN is_first_order IS TRUE THEN 1 END) as first_orders,
                COUNT(CASE WHEN is_first_order IS NOT TRUE THEN 1 END) as repeat_orders
            FROM shopify_orders
            WHERE ordered_at >= ? AND ordered_at < ?
              AND financial_status NOT IN ('voided', 'refunded')
        ", [$from, $to]);
    }

    /**
     * Per-SKU product sales with margins and first/repeat split.
     *
     * @return array<string, array<string, mixed>>
     */
    public function productSalesDetailed(string $from, ?string $to = null): array
    {
        $toClause = $to ? 'AND o.ordered_at < ?' : '';
        $params = $to ? [$from, $to] : [$from];

        $rows = DB::select("
            SELECT
                p.sku,
                p.name,
                p.product_category,
                p.portfolio_role,
                p.journey_phase,
                p.cost_price,
                p.list_price,
                p.is_active,
                p.is_discontinued,
                COUNT(li.id) as line_items,
                SUM(li.quantity) as units_sold,
                ROUND(SUM(li.price * li.quantity), 2) as gross_revenue,
                ROUND(SUM(li.cost_price * li.quantity), 2) as total_cogs,
                ROUND(SUM(li.price * li.quantity) - SUM(li.cost_price * li.quantity), 2) as gross_margin,
                ROUND(
                    CASE WHEN SUM(li.price * li.quantity) > 0
                    THEN ((SUM(li.price * li.quantity) - SUM(li.cost_price * li.quantity)) / SUM(li.price * li.quantity)) * 100
                    ELSE 0 END, 1
                ) as margin_pct,
                ROUND(AVG(li.price), 2) as avg_sell_price,
                ROUND(AVG(li.cost_price), 2) as avg_cost_price,
                SUM(CASE WHEN o.is_first_order IS TRUE THEN li.quantity ELSE 0 END) as units_first_order,
                SUM(CASE WHEN o.is_first_order IS NOT TRUE THEN li.quantity ELSE 0 END) as units_repeat_order
            FROM shopify_line_items li
            JOIN shopify_orders o ON li.order_id = o.id
            JOIN products p ON li.product_id = p.id
            WHERE o.ordered_at >= ? {$toClause}
              AND o.financial_status NOT IN ('voided', 'refunded')
            GROUP BY p.id, p.sku, p.name, p.product_category, p.portfolio_role, p.journey_phase,
                     p.cost_price, p.list_price, p.is_active, p.is_discontinued
            ORDER BY gross_revenue DESC
        ", $params);

        $result = [];
        foreach ($rows as $row) {
            $result[$row->sku] = (array) $row;
        }

        return $result;
    }

    /**
     * Product sales with contribution margin (simpler than detailed).
     *
     * @return array<int, object>
     */
    public function productSales(string $from, string $to): array
    {
        return DB::select("
            SELECT
                li.sku,
                COALESCE(p.name, li.product_title) as product_name,
                p.product_category,
                p.portfolio_role,
                SUM(li.quantity) as units_sold,
                ROUND(SUM(li.price * li.quantity), 2) as gross_revenue,
                ROUND(SUM(li.cost_price * li.quantity), 2) as total_cost,
                ROUND(SUM((li.price - COALESCE(li.cost_price, 0)) * li.quantity), 2) as contribution_margin,
                ROUND(
                    CASE WHEN SUM(li.price * li.quantity) > 0
                    THEN ((SUM(li.price * li.quantity) - SUM(COALESCE(li.cost_price, 0) * li.quantity)) / SUM(li.price * li.quantity)) * 100
                    ELSE 0 END, 1
                ) as margin_pct,
                COUNT(DISTINCT li.order_id) as order_count
            FROM shopify_line_items li
            JOIN shopify_orders o ON li.order_id = o.id
            LEFT JOIN products p ON li.product_id = p.id
            WHERE o.ordered_at >= ? AND o.ordered_at < ?
              AND o.financial_status NOT IN ('voided', 'refunded')
            GROUP BY li.sku, product_name, p.product_category, p.portfolio_role
            ORDER BY gross_revenue DESC
        ", [$from, $to]);
    }

    /**
     * @return array<int, object>
     */
    public function categorySales(string $from, string $to): array
    {
        return DB::select("
            SELECT
                COALESCE(p.product_category, 'overig') as category,
                COUNT(DISTINCT p.id) as sku_count,
                SUM(li.quantity) as units_sold,
                ROUND(SUM(li.price * li.quantity), 2) as gross_revenue,
                ROUND(SUM(li.cost_price * li.quantity), 2) as total_cost,
                ROUND(SUM(li.price * li.quantity) - SUM(li.cost_price * li.quantity), 2) as total_margin,
                ROUND(SUM((li.price - COALESCE(li.cost_price, 0)) * li.quantity), 2) as contribution_margin,
                ROUND(
                    CASE WHEN SUM(li.price * li.quantity) > 0
                    THEN ((SUM(li.price * li.quantity) - SUM(COALESCE(li.cost_price, 0) * li.quantity)) / SUM(li.price * li.quantity)) * 100
                    ELSE 0 END, 1
                ) as margin_pct,
                SUM(CASE WHEN o.is_first_order IS TRUE THEN li.quantity ELSE 0 END) as first_order_units,
                SUM(CASE WHEN o.is_first_order IS NOT TRUE THEN li.quantity ELSE 0 END) as repeat_units
            FROM shopify_line_items li
            JOIN shopify_orders o ON li.order_id = o.id
            LEFT JOIN products p ON li.product_id = p.id
            WHERE o.ordered_at >= ? AND o.ordered_at < ?
              AND o.financial_status NOT IN ('voided', 'refunded')
            GROUP BY category
            ORDER BY gross_revenue DESC
        ", [$from, $to]);
    }

    /**
     * @return array<int, object>
     */
    public function countrySales(string $from, string $to, int $limit = 15): array
    {
        return DB::select("
            SELECT
                COALESCE(shipping_country_code, billing_country_code) as country,
                COUNT(*) as orders,
                ROUND(SUM(total_price), 2) as revenue,
                ROUND(SUM(net_revenue), 2) as net_revenue,
                ROUND(SUM(gross_margin), 2) as gross_margin
            FROM shopify_orders
            WHERE ordered_at >= ? AND ordered_at < ?
              AND financial_status NOT IN ('voided', 'refunded')
            GROUP BY country
            ORDER BY revenue DESC
            LIMIT ?
        ", [$from, $to, $limit]);
    }

    /**
     * @return array<int, object>
     */
    public function provinceSales(string $from, string $to, int $limit = 10): array
    {
        return DB::select("
            SELECT
                COALESCE(shipping_country_code, billing_country_code) as country,
                shipping_province_code as province,
                COUNT(*) as orders,
                ROUND(SUM(total_price), 2) as revenue
            FROM shopify_orders
            WHERE ordered_at >= ? AND ordered_at < ?
              AND financial_status NOT IN ('voided', 'refunded')
            GROUP BY country, province
            ORDER BY revenue DESC
            LIMIT ?
        ", [$from, $to, $limit]);
    }

    /**
     * @return array<int, object>
     */
    public function channelBreakdown(string $from, string $to): array
    {
        return DB::select("
            SELECT
                refined_channel,
                COUNT(*) as orders,
                ROUND(SUM(net_revenue), 2) as net_revenue,
                ROUND(SUM(net_revenue) * 100.0 / (
                    SELECT SUM(net_revenue) FROM shopify_orders
                    WHERE ordered_at >= ? AND ordered_at < ?
                      AND financial_status NOT IN ('voided', 'refunded')
                ), 1) as revenue_share
            FROM shopify_orders
            WHERE ordered_at >= ? AND ordered_at < ?
              AND financial_status NOT IN ('voided', 'refunded')
            GROUP BY refined_channel
            ORDER BY net_revenue DESC
        ", [$from, $to, $from, $to]);
    }

    /**
     * Top products filtered by customer type (first order or returning).
     *
     * @return array<int, object>
     */
    public function productSalesByCustomerType(string $from, string $to, bool $firstOrder, int $limit = 5): array
    {
        return DB::select("
            SELECT
                COALESCE(p.name, li.product_title) as product_name,
                SUM(li.quantity) as units,
                ROUND(SUM(li.price * li.quantity), 2) as revenue
            FROM shopify_line_items li
            JOIN shopify_orders o ON li.order_id = o.id
            LEFT JOIN products p ON li.product_id = p.id
            WHERE o.ordered_at >= ? AND o.ordered_at < ?
              AND o.financial_status NOT IN ('voided', 'refunded')
              AND o.is_first_order = ?
            GROUP BY product_name
            ORDER BY revenue DESC
            LIMIT ?
        ", [$from, $to, $firstOrder, $limit]);
    }

    /**
     * Top products by revenue for a date range.
     *
     * @return array<int, object>
     */
    public function topProducts(string $from, string $to, int $limit = 10): array
    {
        return DB::select("
            SELECT
                COALESCE(p.name, li.product_title) as product_name,
                li.sku,
                SUM(li.quantity) as units,
                ROUND(SUM(li.price * li.quantity), 2) as revenue
            FROM shopify_line_items li
            JOIN shopify_orders o ON li.order_id = o.id
            LEFT JOIN products p ON li.product_id = p.id
            WHERE o.ordered_at >= ? AND o.ordered_at < ?
              AND o.financial_status NOT IN ('voided', 'refunded')
            GROUP BY li.sku, product_name
            ORDER BY revenue DESC
            LIMIT ?
        ", [$from, $to, $limit]);
    }

    /**
     * Monthly sales trend, optionally filtered by SKU prefix or product category.
     *
     * @return array<int, object>
     */
    public function monthlySales(string $from, string $to, ?string $skuPrefix = null, ?string $category = null): array
    {
        $conditions = ['o.ordered_at >= ?', 'o.ordered_at < ?', "o.financial_status NOT IN ('voided', 'refunded')"];
        $params = [$from, $to];

        if ($skuPrefix !== null) {
            $conditions[] = 'li.sku LIKE ?';
            $params[] = $skuPrefix.'%';
        }

        if ($category !== null) {
            $conditions[] = 'p.product_category = ?';
            $params[] = $category;
        }

        $where = implode(' AND ', $conditions);
        $joinProducts = $category !== null ? 'LEFT JOIN products p ON li.product_id = p.id' : '';

        return DB::select('
            SELECT
                '.DbDialect::yearMonthExpr('o.ordered_at')." as month,
                SUM(li.quantity) as units,
                ROUND(SUM(li.price * li.quantity), 2) as revenue,
                COUNT(DISTINCT o.id) as orders
            FROM shopify_line_items li
            JOIN shopify_orders o ON li.order_id = o.id
            {$joinProducts}
            WHERE {$where}
            GROUP BY month
            ORDER BY month
        ", $params);
    }

    /**
     * Weekly sales trend, optionally filtered by SKU prefix or product category.
     *
     * @return array<int, object>
     */
    public function weeklySales(string $from, string $to, ?string $skuPrefix = null, ?string $category = null): array
    {
        $conditions = ['o.ordered_at >= ?', 'o.ordered_at < ?', "o.financial_status NOT IN ('voided', 'refunded')"];
        $params = [$from, $to];

        if ($skuPrefix !== null) {
            $conditions[] = 'li.sku LIKE ?';
            $params[] = $skuPrefix.'%';
        }

        if ($category !== null) {
            $conditions[] = 'p.product_category = ?';
            $params[] = $category;
        }

        $where = implode(' AND ', $conditions);
        $joinProducts = $category !== null ? 'LEFT JOIN products p ON li.product_id = p.id' : '';

        $yearWeek = DbDialect::yearWeekExpr('o.ordered_at');

        return DB::select("
            SELECT
                {$yearWeek} as week,
                MIN(DATE(o.ordered_at)) as week_start,
                MAX(DATE(o.ordered_at)) as week_end,
                SUM(li.quantity) as units,
                COUNT(DISTINCT o.id) as orders
            FROM shopify_line_items li
            JOIN shopify_orders o ON li.order_id = o.id
            {$joinProducts}
            WHERE {$where}
            GROUP BY week
            ORDER BY week
        ", $params);
    }

    /**
     * Order-level monthly trend (not line-item based).
     *
     * @return array<int, object>
     */
    public function monthlyOrderTrend(string $from, string $to): array
    {
        $yearMonth = DbDialect::yearMonthExpr('ordered_at');

        return DB::select("
            SELECT
                {$yearMonth} as month,
                COUNT(*) as total_orders,
                ROUND(SUM(net_revenue), 2) as net_revenue,
                ROUND(SUM(gross_margin), 2) as gross_margin,
                COUNT(CASE WHEN is_first_order IS TRUE THEN 1 END) as first_orders,
                COUNT(CASE WHEN is_first_order IS NOT TRUE THEN 1 END) as repeat_orders
            FROM shopify_orders
            WHERE ordered_at >= ? AND ordered_at < ?
              AND financial_status NOT IN ('voided', 'refunded')
            GROUP BY month
            ORDER BY month
        ", [$from, $to]);
    }

    /**
     * Order-level weekly pattern for a date range.
     *
     * @return array<int, object>
     */
    public function weeklyOrderPattern(string $from, string $to): array
    {
        $week = DbDialect::weekExpr('ordered_at');

        return DB::select("
            SELECT
                {$week} as week_nr,
                MIN(DATE(ordered_at)) as week_start,
                MAX(DATE(ordered_at)) as week_end,
                COUNT(*) as orders,
                ROUND(SUM(net_revenue), 2) as net_revenue,
                COUNT(CASE WHEN is_first_order IS TRUE THEN 1 END) as first_orders
            FROM shopify_orders
            WHERE ordered_at >= ? AND ordered_at < ?
              AND financial_status NOT IN ('voided', 'refunded')
            GROUP BY week_nr
            ORDER BY week_nr
        ", [$from, $to]);
    }

    /**
     * Product catalog with successor information.
     *
     * @return array<string, array<string, mixed>>
     */
    public function productCatalog(): array
    {
        $rows = DB::select('
            SELECT
                p.sku, p.name, p.product_category, p.portfolio_role, p.journey_phase,
                p.wax_recipe, p.heater_generation, p.cost_price, p.list_price,
                p.is_active, p.is_discontinued, p.discontinued_at,
                succ.sku as successor_sku, succ.name as successor_name
            FROM products p
            LEFT JOIN products succ ON p.successor_product_id = succ.id
            ORDER BY p.product_category, p.name
        ');

        $result = [];
        foreach ($rows as $row) {
            $result[$row->sku] = (array) $row;
        }

        return $result;
    }

    /**
     * Latest stock snapshot per product.
     *
     * @return array<string, array<string, mixed>>
     */
    public function stockData(): array
    {
        $rows = DB::select('
            SELECT
                p.sku, ps.qty_on_hand, ps.qty_forecasted, ps.recorded_at
            FROM product_stock_snapshots ps
            JOIN products p ON ps.product_id = p.id
            WHERE ps.recorded_at = (SELECT MAX(recorded_at) FROM product_stock_snapshots)
        ');

        $result = [];
        foreach ($rows as $row) {
            $result[$row->sku] = (array) $row;
        }

        return $result;
    }

    /**
     * Revenue share for a specific product category.
     */
    public function categoryRevenueShare(string $from, string $to, string $category, ?bool $firstOrder = null): float
    {
        $firstOrderClause = $firstOrder !== null ? 'AND o.is_first_order = ?' : '';
        $params = [$from, $to];

        if ($firstOrder !== null) {
            $params[] = $firstOrder;
        }

        $result = DB::selectOne("
            SELECT
                ROUND(SUM(CASE WHEN p.product_category = '{$category}' THEN li.price * li.quantity ELSE 0 END) * 100.0
                    / NULLIF(SUM(li.price * li.quantity), 0), 1) as pct
            FROM shopify_line_items li
            JOIN shopify_orders o ON li.order_id = o.id
            LEFT JOIN products p ON li.product_id = p.id
            WHERE o.ordered_at >= ? AND o.ordered_at < ?
              AND o.financial_status NOT IN ('voided', 'refunded')
              {$firstOrderClause}
        ", $params);

        return (float) ($result->pct ?? 0);
    }
}
