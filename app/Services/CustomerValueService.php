<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class CustomerValueService
{
    private string $since = '2024-01-01';

    /**
     * Canonical LTV calculation: SUM(net_revenue) per customer.
     *
     * @return array<int, array{customer_id: int, order_count: int, total_ltv: float}>
     */
    public function ltvPerCustomer(?string $since = null): array
    {
        $since ??= $this->since;

        return DB::select('
            SELECT
                sc.id as customer_id,
                COUNT(so.id) as order_count,
                ROUND(SUM(so.net_revenue), 2) as total_ltv
            FROM shopify_customers sc
            JOIN shopify_orders so ON so.customer_id = sc.id
            WHERE so.ordered_at >= ?
                AND so.financial_status NOT IN (\'voided\', \'refunded\')
                AND so.net_revenue > 0
            GROUP BY sc.id
        ', [$since]);
    }

    /**
     * Average LTV per monthly acquisition cohort.
     *
     * @return array<int, array{cohort: string, customers: int, avg_ltv: float, total_ltv: float}>
     */
    public function ltvByCohort(?string $since = null): array
    {
        $since ??= $this->since;

        return DB::select("
            WITH customer_ltv AS (
                SELECT
                    sc.id,
                    strftime('%Y-%m', sc.first_order_at) as cohort,
                    ROUND(SUM(so.net_revenue), 2) as total_ltv
                FROM shopify_customers sc
                JOIN shopify_orders so ON so.customer_id = sc.id
                WHERE sc.first_order_at >= ?
                    AND so.financial_status NOT IN ('voided', 'refunded')
                    AND so.net_revenue > 0
                GROUP BY sc.id
            )
            SELECT
                cohort,
                COUNT(*) as customers,
                ROUND(AVG(total_ltv), 2) as avg_ltv,
                ROUND(SUM(total_ltv), 2) as total_ltv
            FROM customer_ltv
            GROUP BY cohort
            ORDER BY cohort
        ", [$since]);
    }

    /**
     * Average LTV per RFM segment.
     *
     * @return array<int, array{segment: string, customers: int, avg_ltv: float, total_ltv: float}>
     */
    public function ltvBySegment(?string $since = null): array
    {
        $since ??= $this->since;

        return DB::select("
            WITH customer_ltv AS (
                SELECT
                    sc.id,
                    sc.rfm_segment as segment,
                    ROUND(SUM(so.net_revenue), 2) as total_ltv
                FROM shopify_customers sc
                JOIN shopify_orders so ON so.customer_id = sc.id
                WHERE so.ordered_at >= ?
                    AND so.financial_status NOT IN ('voided', 'refunded')
                    AND so.net_revenue > 0
                    AND sc.rfm_segment IS NOT NULL
                GROUP BY sc.id
            )
            SELECT
                segment,
                COUNT(*) as customers,
                ROUND(AVG(total_ltv), 2) as avg_ltv,
                ROUND(SUM(total_ltv), 2) as total_ltv
            FROM customer_ltv
            GROUP BY segment
            ORDER BY avg_ltv DESC
        ", [$since]);
    }

    /**
     * Average LTV per acquisition channel.
     *
     * @return array<int, array{channel: string, customers: int, avg_ltv: float, total_ltv: float}>
     */
    public function ltvByChannel(?string $since = null): array
    {
        $since ??= $this->since;

        return DB::select("
            WITH customer_ltv AS (
                SELECT
                    sc.id,
                    COALESCE(sc.first_order_channel, 'unknown') as channel,
                    ROUND(SUM(so.net_revenue), 2) as total_ltv
                FROM shopify_customers sc
                JOIN shopify_orders so ON so.customer_id = sc.id
                WHERE sc.first_order_at >= ?
                    AND so.financial_status NOT IN ('voided', 'refunded')
                    AND so.net_revenue > 0
                GROUP BY sc.id
            )
            SELECT
                channel,
                COUNT(*) as customers,
                ROUND(AVG(total_ltv), 2) as avg_ltv,
                ROUND(SUM(total_ltv), 2) as total_ltv
            FROM customer_ltv
            GROUP BY channel
            ORDER BY total_ltv DESC
        ", [$since]);
    }

    /**
     * Average LTV per country.
     *
     * @return array<int, array{country_code: string, customers: int, avg_ltv: float, total_ltv: float}>
     */
    public function ltvByRegion(?string $since = null): array
    {
        $since ??= $this->since;

        return DB::select("
            WITH customer_ltv AS (
                SELECT
                    sc.id,
                    COALESCE(sc.country_code, 'unknown') as country_code,
                    ROUND(SUM(so.net_revenue), 2) as total_ltv
                FROM shopify_customers sc
                JOIN shopify_orders so ON so.customer_id = sc.id
                WHERE sc.first_order_at >= ?
                    AND so.financial_status NOT IN ('voided', 'refunded')
                    AND so.net_revenue > 0
                GROUP BY sc.id
            )
            SELECT
                country_code,
                COUNT(*) as customers,
                ROUND(AVG(total_ltv), 2) as avg_ltv,
                ROUND(SUM(total_ltv), 2) as total_ltv
            FROM customer_ltv
            GROUP BY country_code
            ORDER BY total_ltv DESC
        ", [$since]);
    }

    /**
     * Average LTV per first-purchase product category.
     *
     * @return array<int, array{category: string, customers: int, avg_ltv: float, avg_ltv_repeaters: float, repeat_rate: float}>
     */
    public function ltvByFirstProduct(?string $since = null): array
    {
        $since ??= $this->since;

        return DB::select("
            WITH customer_orders AS (
                SELECT
                    sc.id as customer_id,
                    COUNT(so.id) as order_count,
                    ROUND(SUM(so.net_revenue), 2) as total_ltv
                FROM shopify_customers sc
                JOIN shopify_orders so ON so.customer_id = sc.id
                WHERE so.ordered_at >= ?
                    AND so.financial_status NOT IN ('voided', 'refunded')
                    AND so.net_revenue > 0
                GROUP BY sc.id
            ),
            first_order_products AS (
                SELECT
                    so.customer_id,
                    p.product_category as category
                FROM shopify_orders so
                JOIN shopify_line_items sli ON sli.order_id = so.id
                JOIN products p ON p.id = sli.product_id
                WHERE so.is_first_order = 1
                    AND p.product_category IS NOT NULL
                GROUP BY so.customer_id, p.product_category
            )
            SELECT
                fop.category,
                COUNT(*) as customers,
                ROUND(AVG(co.total_ltv), 2) as avg_ltv,
                ROUND(AVG(CASE WHEN co.order_count >= 2 THEN co.total_ltv END), 2) as avg_ltv_repeaters,
                ROUND(SUM(CASE WHEN co.order_count >= 2 THEN 1.0 ELSE 0 END) * 100 / COUNT(*), 1) as repeat_rate
            FROM first_order_products fop
            JOIN customer_orders co ON co.customer_id = fop.customer_id
            GROUP BY fop.category
            HAVING COUNT(*) >= 5
            ORDER BY avg_ltv DESC
        ", [$since]);
    }

    /**
     * Klaviyo predicted CLV alongside internal LTV for comparison.
     *
     * @return array<int, array{email: string, internal_ltv: float, klaviyo_historic_clv: float, klaviyo_predicted_clv: float, klaviyo_total_clv: float}>
     */
    public function compareWithKlaviyo(int $limit = 100): array
    {
        return DB::select("
            SELECT
                rp.email,
                ROUND(COALESCE(SUM(so.net_revenue), 0), 2) as internal_ltv,
                ROUND(COALESCE(kp.historic_clv, 0), 2) as klaviyo_historic_clv,
                ROUND(COALESCE(kp.predicted_clv, 0), 2) as klaviyo_predicted_clv,
                ROUND(COALESCE(kp.total_clv, 0), 2) as klaviyo_total_clv
            FROM rider_profiles rp
            LEFT JOIN shopify_customers sc ON sc.id = rp.shopify_customer_id
            LEFT JOIN shopify_orders so ON so.customer_id = sc.id
                AND so.financial_status NOT IN ('voided', 'refunded')
                AND so.net_revenue > 0
            LEFT JOIN klaviyo_profiles kp ON kp.id = rp.klaviyo_profile_id
            WHERE rp.shopify_customer_id IS NOT NULL
                AND rp.klaviyo_profile_id IS NOT NULL
            GROUP BY rp.id
            ORDER BY internal_ltv DESC
            LIMIT ?
        ", [$limit]);
    }
}
