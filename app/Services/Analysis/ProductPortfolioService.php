<?php

namespace App\Services\Analysis;

use App\Enums\ProductCategory;
use Illuminate\Support\Facades\DB;

class ProductPortfolioService
{
    private string $since;

    public function __construct()
    {
        $this->since = config('analytics.data_since');
    }

    /**
     * Base query filter: valid orders since analysis start date.
     */
    private function orderFilter(string $alias = 'so'): string
    {
        return "{$alias}.ordered_at >= '{$this->since}' AND {$alias}.financial_status NOT IN ('voided', 'refunded')";
    }

    /**
     * Acquisition profile per product category (and optionally per product).
     *
     * Shows how each category/product performs as an entry point:
     * - Volume in first orders vs repeat orders
     * - Repeat rate of customers whose first order contained this category
     *
     * @return array<int, array{product_category: string, product_title: ?string, first_order_customers: int, repeat_customers: int, repeat_rate: float, units_first: int, units_repeat: int, first_order_share: float}>
     */
    public function acquisitionProfile(bool $drillDown = false): array
    {
        $groupBy = $drillDown ? 'p.product_category, sli.product_title' : 'p.product_category';
        $select = $drillDown ? 'p.product_category, sli.product_title' : 'p.product_category, NULL as product_title';

        return DB::select("
            SELECT
                {$select},
                COUNT(DISTINCT so.customer_id) as first_order_customers,
                COUNT(DISTINCT CASE WHEN sc.local_orders_count >= 2 THEN so.customer_id END) as repeat_customers,
                ROUND(
                    COUNT(DISTINCT CASE WHEN sc.local_orders_count >= 2 THEN so.customer_id END) * 100.0
                    / MAX(1, COUNT(DISTINCT so.customer_id)),
                1) as repeat_rate,
                SUM(sli.quantity) as units_first,
                ROUND(SUM(sli.price * sli.quantity), 0) as revenue_first
            FROM shopify_line_items sli
            INNER JOIN shopify_orders so ON so.id = sli.order_id
            INNER JOIN shopify_customers sc ON sc.id = so.customer_id
            INNER JOIN products p ON p.id = sli.product_id
            WHERE {$this->orderFilter()}
                AND so.is_first_order = 1
                AND p.product_category IS NOT NULL
                AND p.product_category NOT IN ('promotional', 'gift_card')
            GROUP BY {$groupBy}
            HAVING first_order_customers >= 5
            ORDER BY first_order_customers DESC
        ");
    }

    /**
     * Product category transition matrix: what categories appear in order 2 after each category in order 1?
     *
     * @return array<int, object{from_category: string, to_category: string, transitions: int, pct_of_from: float}>
     */
    public function transitionMatrix(bool $drillDown = false): array
    {
        $fromField = $drillDown ? 'sli1.product_title' : 'p1.product_category';
        $toField = $drillDown ? 'sli2.product_title' : 'p2.product_category';
        $minTransitions = $drillDown ? 3 : 5;

        return DB::select("
            WITH customer_orders AS (
                SELECT
                    customer_id,
                    id as order_id,
                    ROW_NUMBER() OVER (PARTITION BY customer_id ORDER BY ordered_at) as order_num
                FROM shopify_orders so
                WHERE {$this->orderFilter('so')}
                    AND so.customer_id IS NOT NULL
            ),
            first_second AS (
                SELECT
                    co1.customer_id,
                    co1.order_id as first_order_id,
                    co2.order_id as second_order_id
                FROM customer_orders co1
                INNER JOIN customer_orders co2
                    ON co2.customer_id = co1.customer_id AND co2.order_num = 2
                WHERE co1.order_num = 1
            )
            SELECT
                {$fromField} as from_category,
                {$toField} as to_category,
                COUNT(*) as transitions,
                ROUND(
                    COUNT(*) * 100.0
                    / SUM(COUNT(*)) OVER (PARTITION BY {$fromField}),
                1) as pct_of_from
            FROM first_second fs
            INNER JOIN shopify_line_items sli1 ON sli1.order_id = fs.first_order_id
            INNER JOIN shopify_line_items sli2 ON sli2.order_id = fs.second_order_id
            INNER JOIN products p1 ON p1.id = sli1.product_id
            INNER JOIN products p2 ON p2.id = sli2.product_id
            WHERE p1.product_category IS NOT NULL
                AND p2.product_category IS NOT NULL
                AND p1.product_category NOT IN ('promotional', 'gift_card')
                AND p2.product_category NOT IN ('promotional', 'gift_card')
            GROUP BY {$fromField}, {$toField}
            HAVING transitions >= {$minTransitions}
            ORDER BY {$fromField}, transitions DESC
        ");
    }

    /**
     * Margin profile per product category (and optionally per product).
     *
     * @return array<int, object{product_category: string, product_title: ?string, total_units: int, revenue: float, cogs: float, gross_margin: float, margin_per_unit: float, margin_pct: float}>
     */
    public function marginProfile(bool $drillDown = false): array
    {
        $groupBy = $drillDown ? 'p.product_category, sli.product_title' : 'p.product_category';
        $select = $drillDown ? 'p.product_category, sli.product_title' : 'p.product_category, NULL as product_title';
        $minUnits = $drillDown ? 5 : 10;

        return DB::select("
            SELECT
                {$select},
                SUM(sli.quantity) as total_units,
                ROUND(SUM(sli.price * sli.quantity), 2) as revenue,
                ROUND(SUM(sli.cost_price * sli.quantity), 2) as cogs,
                ROUND(SUM(sli.price * sli.quantity) - SUM(sli.cost_price * sli.quantity), 2) as gross_margin,
                ROUND(
                    (SUM(sli.price * sli.quantity) - SUM(sli.cost_price * sli.quantity))
                    / MAX(1, SUM(sli.quantity)),
                2) as margin_per_unit,
                ROUND(
                    (SUM(sli.price * sli.quantity) - SUM(sli.cost_price * sli.quantity)) * 100.0
                    / MAX(1, SUM(sli.price * sli.quantity)),
                1) as margin_pct
            FROM shopify_line_items sli
            INNER JOIN shopify_orders so ON so.id = sli.order_id
            INNER JOIN products p ON p.id = sli.product_id
            WHERE {$this->orderFilter()}
                AND sli.cost_price IS NOT NULL
                AND p.product_category IS NOT NULL
                AND p.product_category NOT IN ('promotional', 'gift_card')
            GROUP BY {$groupBy}
            HAVING total_units >= {$minUnits}
            ORDER BY gross_margin DESC
        ");
    }

    /**
     * Timing profile: days to second order per first-order product category.
     *
     * @return array<int, object{product_category: string, product_title: ?string, repeat_customers: int, avg_days: float, within_30d: int, within_60d: int, within_90d: int, within_180d: int, pct_30d: float, pct_60d: float, pct_90d: float, pct_180d: float}>
     */
    public function timingProfile(bool $drillDown = false): array
    {
        $groupBy = $drillDown ? 'fop.product_category, fop.product_title' : 'fop.product_category';
        $select = $drillDown
            ? 'fop.product_category, fop.product_title'
            : 'fop.product_category, NULL as product_title';
        $fopSelect = $drillDown
            ? 'DISTINCT so.customer_id, p.product_category, sli.product_title'
            : 'DISTINCT so.customer_id, p.product_category, NULL as product_title';
        $minCustomers = $drillDown ? 5 : 10;

        $daysDiff = DB::getDriverName() === 'pgsql'
            ? 'EXTRACT(EPOCH FROM (co2.ordered_at - co1.ordered_at)) / 86400'
            : 'CAST(julianday(co2.ordered_at) - julianday(co1.ordered_at) AS INTEGER)';

        return DB::select("
            WITH customer_orders AS (
                SELECT
                    customer_id,
                    ordered_at,
                    ROW_NUMBER() OVER (PARTITION BY customer_id ORDER BY ordered_at) as order_num
                FROM shopify_orders so
                WHERE {$this->orderFilter('so')}
                    AND so.customer_id IS NOT NULL
            ),
            repeat_gaps AS (
                SELECT
                    co1.customer_id,
                    {$daysDiff} as days_to_second
                FROM customer_orders co1
                INNER JOIN customer_orders co2
                    ON co2.customer_id = co1.customer_id AND co2.order_num = 2
                WHERE co1.order_num = 1
            ),
            first_order_products AS (
                SELECT {$fopSelect}
                FROM shopify_orders so
                INNER JOIN shopify_line_items sli ON sli.order_id = so.id
                INNER JOIN products p ON p.id = sli.product_id
                WHERE so.is_first_order = 1
                    AND {$this->orderFilter()}
                    AND p.product_category IS NOT NULL
                    AND p.product_category NOT IN ('promotional', 'gift_card')
            )
            SELECT
                {$select},
                COUNT(*) as repeat_customers,
                ROUND(AVG(rg.days_to_second), 0) as avg_days,
                SUM(CASE WHEN rg.days_to_second <= 30 THEN 1 ELSE 0 END) as within_30d,
                SUM(CASE WHEN rg.days_to_second <= 60 THEN 1 ELSE 0 END) as within_60d,
                SUM(CASE WHEN rg.days_to_second <= 90 THEN 1 ELSE 0 END) as within_90d,
                SUM(CASE WHEN rg.days_to_second <= 180 THEN 1 ELSE 0 END) as within_180d,
                ROUND(SUM(CASE WHEN rg.days_to_second <= 30 THEN 1 ELSE 0 END) * 100.0 / MAX(1, COUNT(*)), 1) as pct_30d,
                ROUND(SUM(CASE WHEN rg.days_to_second <= 60 THEN 1 ELSE 0 END) * 100.0 / MAX(1, COUNT(*)), 1) as pct_60d,
                ROUND(SUM(CASE WHEN rg.days_to_second <= 90 THEN 1 ELSE 0 END) * 100.0 / MAX(1, COUNT(*)), 1) as pct_90d,
                ROUND(SUM(CASE WHEN rg.days_to_second <= 180 THEN 1 ELSE 0 END) * 100.0 / MAX(1, COUNT(*)), 1) as pct_180d
            FROM first_order_products fop
            INNER JOIN repeat_gaps rg ON rg.customer_id = fop.customer_id
            GROUP BY {$groupBy}
            HAVING repeat_customers >= {$minCustomers}
            ORDER BY avg_days ASC
        ");
    }

    /**
     * Combined portfolio scorecard: one row per product category with all key metrics.
     *
     * @return array<int, object{product_category: string, label: string, portfolio_role: string, units_sold: int, revenue: float, gross_margin: float, margin_pct: float, first_order_customers: int, repeat_rate: float, avg_days_to_repeat: ?float, top_next_category: ?string}>
     */
    public function portfolioScorecard(): array
    {
        $margin = collect($this->marginProfile());
        $acquisition = collect($this->acquisitionProfile());
        $timing = collect($this->timingProfile());
        $transitions = collect($this->transitionMatrix());

        // Get portfolio role per category from products table
        $roles = DB::select('
            SELECT product_category, portfolio_role, COUNT(*) as cnt
            FROM products
            WHERE product_category IS NOT NULL AND portfolio_role IS NOT NULL
            GROUP BY product_category, portfolio_role
            ORDER BY cnt DESC
        ');
        $roleMap = collect($roles)->groupBy('product_category')->map(fn ($group) => $group->first()->portfolio_role);

        return $margin->map(function ($m) use ($acquisition, $timing, $transitions, $roleMap) {
            $cat = $m->product_category;
            $acq = $acquisition->firstWhere('product_category', $cat);
            $time = $timing->firstWhere('product_category', $cat);

            // Top next category in transition
            $topNext = $transitions
                ->where('from_category', $cat)
                ->sortByDesc('transitions')
                ->first();

            $catEnum = ProductCategory::tryFrom($cat);

            return (object) [
                'product_category' => $cat,
                'label' => $catEnum?->label() ?? $cat,
                'ecosystem' => $catEnum?->ecosystem() ?? 'unknown',
                'portfolio_role' => $roleMap->get($cat, '—'),
                'units_sold' => (int) $m->total_units,
                'revenue' => (float) $m->revenue,
                'gross_margin' => (float) $m->gross_margin,
                'margin_pct' => (float) $m->margin_pct,
                'margin_per_unit' => (float) $m->margin_per_unit,
                'first_order_customers' => $acq ? (int) $acq->first_order_customers : 0,
                'repeat_rate' => $acq ? (float) $acq->repeat_rate : 0,
                'avg_days_to_repeat' => $time ? (float) $time->avg_days : null,
                'pct_within_90d' => $time ? (float) $time->pct_90d : null,
                'top_next_category' => $topNext ? $topNext->to_category : null,
                'top_next_pct' => $topNext ? (float) $topNext->pct_of_from : null,
            ];
        })->sortByDesc('revenue')->values()->toArray();
    }
}
