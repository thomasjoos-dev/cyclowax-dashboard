<?php

namespace App\Services\Forecast\Demand;

use App\Enums\ForecastRegion;
use Illuminate\Support\Facades\DB;

class RegionalCostService
{
    /**
     * Build a cost profile for a region based on current product costs and historical shipping data.
     *
     * @return array{
     *     cogs_per_unit: array<string, float>,
     *     avg_shipping_per_order: float,
     *     avg_units_per_order: float,
     *     payment_fee_pct: float,
     *     payment_fee_fixed: float,
     *     orders_analysed: int
     * }
     */
    public function costProfile(?ForecastRegion $region = null): array
    {
        return [
            'cogs_per_unit' => $this->cogsPerUnitByCategory(),
            'avg_shipping_per_order' => $this->avgShippingPerOrder($region),
            'avg_units_per_order' => $this->avgUnitsPerOrder($region),
            'payment_fee_pct' => (float) config('fees.payment.percentage', 1.9) / 100,
            'payment_fee_fixed' => (float) config('fees.payment.fixed', 0.25),
            'orders_analysed' => $this->orderCount($region),
        ];
    }

    /**
     * Calculate CM1 for forecasted revenue + units per category for a region.
     *
     * @param  array<string, array{units: int, revenue: float}>  $categoryForecasts  category_value => {units, revenue}
     * @return array{
     *     net_revenue: float,
     *     cogs: float,
     *     shipping_cost: float,
     *     payment_fee: float,
     *     cm1: float,
     *     cm1_pct: float
     * }
     */
    public function calculateCm1(array $categoryForecasts, ?ForecastRegion $region = null): array
    {
        $profile = $this->costProfile($region);

        $totalRevenue = 0.0;
        $totalCogs = 0.0;
        $totalUnits = 0;

        foreach ($categoryForecasts as $categoryValue => $data) {
            $totalRevenue += $data['revenue'];
            $totalUnits += $data['units'];

            $cogsPerUnit = $profile['cogs_per_unit'][$categoryValue] ?? 0.0;
            $totalCogs += $data['units'] * $cogsPerUnit;
        }

        // Estimate order count from units (using avg units per order for this region)
        $avgUnitsPerOrder = $profile['avg_units_per_order'] > 0 ? $profile['avg_units_per_order'] : 1.5;
        $estimatedOrders = $totalUnits / $avgUnitsPerOrder;

        $shippingCost = round($estimatedOrders * $profile['avg_shipping_per_order'], 2);
        $paymentFee = round($totalRevenue * $profile['payment_fee_pct'] + $estimatedOrders * $profile['payment_fee_fixed'], 2);
        $totalCogs = round($totalCogs, 2);
        $cm1 = round($totalRevenue - $totalCogs - $shippingCost - $paymentFee, 2);
        $cm1Pct = $totalRevenue > 0 ? round($cm1 / $totalRevenue * 100, 1) : 0.0;

        return [
            'net_revenue' => round($totalRevenue, 2),
            'cogs' => $totalCogs,
            'shipping_cost' => $shippingCost,
            'payment_fee' => $paymentFee,
            'cm1' => $cm1,
            'cm1_pct' => $cm1Pct,
        ];
    }

    /**
     * Current COGS per unit per product category, weighted by SKU sales mix.
     * Uses the current Odoo cost_price on products, not historical line item costs.
     *
     * @return array<string, float> category_value => weighted avg cost per unit
     */
    private function cogsPerUnitByCategory(): array
    {
        // Weighted average: weight = units sold in last 12 months per product
        // This gives a mix-weighted COGS that reflects what we actually sell per category
        $result = DB::table('products')
            ->leftJoin('shopify_line_items', 'products.id', '=', 'shopify_line_items.product_id')
            ->leftJoin('shopify_orders', 'shopify_line_items.order_id', '=', 'shopify_orders.id')
            ->whereNotNull('products.product_category')
            ->whereNotNull('products.cost_price')
            ->where('products.cost_price', '>', 0)
            ->where(function ($q) {
                $q->whereNull('shopify_orders.id')
                    ->orWhere(function ($q2) {
                        $q2->whereNotIn('shopify_orders.financial_status', ['voided', 'refunded'])
                            ->where('shopify_orders.ordered_at', '>=', now()->subMonths(12)->toDateString());
                    });
            })
            ->select(
                'products.product_category',
                DB::raw('CASE WHEN SUM(shopify_line_items.quantity) > 0 THEN SUM(products.cost_price * shopify_line_items.quantity) / SUM(shopify_line_items.quantity) ELSE AVG(products.cost_price) END as weighted_cogs'),
            )
            ->groupBy('products.product_category')
            ->get();

        return $result
            ->pluck('weighted_cogs', 'product_category')
            ->map(fn ($v) => round((float) $v, 4))
            ->all();
    }

    /**
     * Average shipping cost per order for a region.
     */
    private function avgShippingPerOrder(?ForecastRegion $region = null): float
    {
        $query = DB::table('shopify_orders')
            ->whereNotIn('financial_status', ['voided', 'refunded'])
            ->whereNotNull('shipping_cost')
            ->where('shipping_cost', '>', 0);

        $this->applyRegionFilter($query, $region);

        $result = $query->selectRaw('AVG(shipping_cost) as avg_ship')->first();

        return round((float) ($result->avg_ship ?? 0), 2);
    }

    /**
     * Average units per order for a region (for estimating order count from units).
     */
    private function avgUnitsPerOrder(?ForecastRegion $region = null): float
    {
        $query = DB::table('shopify_orders')
            ->join('shopify_line_items', 'shopify_orders.id', '=', 'shopify_line_items.order_id')
            ->whereNotIn('shopify_orders.financial_status', ['voided', 'refunded']);

        $this->applyRegionFilter($query, $region, 'shopify_orders');

        $result = $query->selectRaw('CAST(SUM(shopify_line_items.quantity) AS FLOAT) / COUNT(DISTINCT shopify_orders.id) as avg_units')->first();

        return round((float) ($result->avg_units ?? 1.5), 2);
    }

    /**
     * Total order count for a region.
     */
    private function orderCount(?ForecastRegion $region = null): int
    {
        $query = DB::table('shopify_orders')
            ->whereNotIn('financial_status', ['voided', 'refunded']);

        $this->applyRegionFilter($query, $region);

        return (int) $query->count();
    }

    /**
     * Apply a region filter to a query builder.
     */
    private function applyRegionFilter($query, ?ForecastRegion $region, string $table = 'shopify_orders'): void
    {
        if ($region === null) {
            return;
        }

        $countries = $region->countries();

        if ($countries === []) {
            $allMapped = collect(ForecastRegion::cases())
                ->filter(fn (ForecastRegion $r) => $r !== ForecastRegion::Row)
                ->flatMap(fn (ForecastRegion $r) => $r->countries())
                ->all();

            $query->where(function ($q) use ($table, $allMapped) {
                $q->whereNotIn("{$table}.shipping_country_code", $allMapped)
                    ->orWhereNull("{$table}.shipping_country_code");
            });
        } else {
            $query->whereIn("{$table}.shipping_country_code", $countries);
        }
    }
}
