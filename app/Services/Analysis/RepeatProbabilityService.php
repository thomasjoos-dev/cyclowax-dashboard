<?php

namespace App\Services\Analysis;

use Illuminate\Support\Facades\DB;

class RepeatProbabilityService
{
    private string $since;

    public function __construct()
    {
        $this->since = config('analytics.data_since');
    }

    /**
     * Repeat probability per product category.
     *
     * Returns P(2nd order), P(3rd|2nd), avg LTV, and avg LTV for repeaters.
     *
     * @param  array<int, string>|null  $categories  Filter to specific categories, or null for all
     * @return array<int, object>
     */
    public function byCategory(?array $categories = null, ?string $since = null): array
    {
        $since ??= $this->since;

        $defaultCategories = ['starter_kit', 'wax_kit', 'chain', 'wax_tablet', 'pocket_wax'];
        $cats = $categories ?? $defaultCategories;

        $placeholders = implode(',', array_fill(0, count($cats), '?'));

        return DB::select("
            WITH first_order_products AS (
                SELECT DISTINCT so.customer_id, p.product_category
                FROM shopify_orders so
                INNER JOIN shopify_line_items sli ON sli.order_id = so.id
                INNER JOIN products p ON p.id = sli.product_id
                WHERE so.is_first_order = 1
                    AND so.ordered_at >= ?
                    AND so.financial_status NOT IN ('voided', 'refunded')
                    AND p.product_category IN ({$placeholders})
            ),
            customer_orders AS (
                SELECT customer_id, COUNT(*) as order_count, SUM(net_revenue) as total_ltv
                FROM shopify_orders
                WHERE ordered_at >= ? AND financial_status NOT IN ('voided', 'refunded')
                GROUP BY customer_id
            )
            SELECT
                fop.product_category,
                COUNT(DISTINCT fop.customer_id) as total_customers,
                ROUND(COUNT(DISTINCT CASE WHEN co.order_count >= 2 THEN fop.customer_id END) * 100.0 / COUNT(DISTINCT fop.customer_id), 1) as pct_2nd,
                ROUND(COUNT(DISTINCT CASE WHEN co.order_count >= 3 THEN fop.customer_id END) * 100.0 / NULLIF(COUNT(DISTINCT CASE WHEN co.order_count >= 2 THEN fop.customer_id END), 0), 1) as pct_3rd_given_2nd,
                ROUND(AVG(co.total_ltv), 0) as avg_ltv,
                ROUND(AVG(CASE WHEN co.order_count >= 2 THEN co.total_ltv END), 0) as avg_ltv_repeaters
            FROM first_order_products fop
            INNER JOIN customer_orders co ON co.customer_id = fop.customer_id
            GROUP BY fop.product_category
            ORDER BY pct_2nd DESC
        ", array_merge([$since], $cats, [$since]));
    }
}
