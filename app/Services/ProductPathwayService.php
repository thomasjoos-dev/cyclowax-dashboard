<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class ProductPathwayService
{
    private string $since;

    public function __construct()
    {
        $this->since = config('analytics.data_since');
    }

    /**
     * Second purchase behavior after a first-order product category.
     *
     * @return array<int, object>
     */
    public function nextPurchase(string $category, int $minCustomers = 5, ?string $since = null): array
    {
        $since ??= $this->since;

        return DB::select("
            WITH customer_orders AS (
                SELECT customer_id, id as order_id, ROW_NUMBER() OVER (PARTITION BY customer_id ORDER BY ordered_at) as order_num
                FROM shopify_orders so
                WHERE so.ordered_at >= ? AND so.financial_status NOT IN ('voided', 'refunded') AND so.customer_id IS NOT NULL
            ),
            first_second AS (
                SELECT co1.customer_id, co1.order_id as first_order_id, co2.order_id as second_order_id
                FROM customer_orders co1
                INNER JOIN customer_orders co2 ON co2.customer_id = co1.customer_id AND co2.order_num = 2
                WHERE co1.order_num = 1
            )
            SELECT sli2.product_title, p2.product_category, COUNT(*) as cnt,
                ROUND(COUNT(*) * 100.0 / SUM(COUNT(*)) OVER(), 1) as pct
            FROM first_second fs
            INNER JOIN shopify_line_items sli1 ON sli1.order_id = fs.first_order_id
            INNER JOIN products p1 ON p1.id = sli1.product_id
            INNER JOIN shopify_line_items sli2 ON sli2.order_id = fs.second_order_id
            INNER JOIN products p2 ON p2.id = sli2.product_id
            WHERE p1.product_category = ?
                AND p2.product_category NOT IN ('promotional', 'gift_card')
            GROUP BY sli2.product_title, p2.product_category
            HAVING cnt >= ?
            ORDER BY cnt DESC
        ", [$since, $category, $minCustomers]);
    }

    /**
     * Three-step customer journey from an entry category.
     *
     * Determines the dominant category per order (highest revenue line item),
     * then traces step1 → step2 → step3 for customers with 3+ orders.
     *
     * @return array<int, object>
     */
    public function threeStepJourney(string $entryCategory = 'starter_kit', int $minCustomers = 5, ?string $since = null): array
    {
        $since ??= $this->since;

        return DB::select("
            WITH order_categories AS (
                SELECT sli.order_id, p.product_category,
                    ROW_NUMBER() OVER (PARTITION BY sli.order_id ORDER BY sli.price * sli.quantity DESC) as rn
                FROM shopify_line_items sli
                INNER JOIN products p ON p.id = sli.product_id
                WHERE p.product_category NOT IN ('promotional', 'gift_card')
                    AND p.product_category IS NOT NULL
            ),
            order_main_cat AS (
                SELECT order_id, product_category FROM order_categories WHERE rn = 1
            ),
            customer_journey AS (
                SELECT
                    so.customer_id,
                    so.id as order_id,
                    ROW_NUMBER() OVER (PARTITION BY so.customer_id ORDER BY so.ordered_at) as n
                FROM shopify_orders so
                WHERE so.ordered_at >= ?
                    AND so.financial_status NOT IN ('voided', 'refunded')
                    AND so.customer_id IS NOT NULL
            )
            SELECT
                oc2.product_category as step2,
                oc3.product_category as step3,
                COUNT(*) as customers
            FROM customer_journey cj1
            INNER JOIN customer_journey cj2 ON cj2.customer_id = cj1.customer_id AND cj2.n = 2
            INNER JOIN customer_journey cj3 ON cj3.customer_id = cj1.customer_id AND cj3.n = 3
            INNER JOIN order_main_cat oc1 ON oc1.order_id = cj1.order_id AND oc1.product_category = ?
            INNER JOIN order_main_cat oc2 ON oc2.order_id = cj2.order_id
            INNER JOIN order_main_cat oc3 ON oc3.order_id = cj3.order_id
            WHERE cj1.n = 1
            GROUP BY step2, step3
            HAVING customers >= ?
            ORDER BY customers DESC
        ", [$since, $entryCategory, $minCustomers]);
    }
}
