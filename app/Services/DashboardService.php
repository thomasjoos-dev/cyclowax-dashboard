<?php

namespace App\Services;

use App\Models\ShopifyCustomer;
use App\Models\ShopifyLineItem;
use App\Models\ShopifyOrder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class DashboardService
{
    /**
     * Get a database-agnostic expression to extract 'YYYY-MM' from a timestamp column.
     */
    protected function yearMonthExpr(string $column): string
    {
        if (DB::getDriverName() === 'pgsql') {
            return "to_char({$column}, 'YYYY-MM')";
        }

        return "strftime('%Y-%m', {$column})";
    }

    /**
     * Get a database-agnostic expression to calculate days between two timestamps.
     */
    protected function daysDiffExpr(string $column1, string $column2): string
    {
        if (DB::getDriverName() === 'pgsql') {
            return "EXTRACT(EPOCH FROM ({$column1} - {$column2})) / 86400";
        }

        return "julianday({$column1}) - julianday({$column2})";
    }

    /**
     * @return array{revenue: float, revenue_change: float, orders: int, orders_change: float, new_customers: int, new_customers_change: float, returning_rate: float}
     */
    public function kpiMetrics(string $period = 'mtd'): array
    {
        return Cache::remember("dashboard:kpi:{$period}", 3600, function () use ($period) {
            [$current, $previous] = $this->periodRanges($period);

            $currentRevenue = $this->revenueInRange($current[0], $current[1]);
            $previousRevenue = $this->revenueInRange($previous[0], $previous[1]);

            $currentOrders = $this->ordersInRange($current[0], $current[1]);
            $previousOrders = $this->ordersInRange($previous[0], $previous[1]);

            $currentNewCustomers = $this->newCustomersInRange($current[0], $current[1]);
            $previousNewCustomers = $this->newCustomersInRange($previous[0], $previous[1]);

            $currentReturningRate = $this->returningOrderRate($current[0], $current[1]);

            return [
                'revenue' => $currentRevenue,
                'revenue_change' => $this->percentageChange($previousRevenue, $currentRevenue),
                'orders' => $currentOrders,
                'orders_change' => $this->percentageChange($previousOrders, $currentOrders),
                'new_customers' => $currentNewCustomers,
                'new_customers_change' => $this->percentageChange($previousNewCustomers, $currentNewCustomers),
                'returning_rate' => $currentReturningRate,
            ];
        });
    }

    /**
     * @return array<int, array{month: string, count: int}>
     */
    public function acquisitionTrend(int $months = 12): array
    {
        return Cache::remember("dashboard:acquisition_trend:{$months}", 3600, function () use ($months) {
            $since = CarbonImmutable::now()->subMonths($months)->startOfMonth();

            return ShopifyCustomer::query()
                ->where('first_order_at', '>=', $since)
                ->selectRaw("{$this->yearMonthExpr('first_order_at')} as month, count(*) as count")
                ->groupBy('month')
                ->orderBy('month')
                ->get()
                ->toArray();
        });
    }

    /**
     * @return array<int, array{country_code: string, count: int, percentage: float}>
     */
    public function acquisitionByRegion(int $limit = 10): array
    {
        return Cache::remember("dashboard:acquisition_region:{$limit}", 3600, function () use ($limit) {
            $total = ShopifyCustomer::query()->whereNotNull('country_code')->count();

            if ($total === 0) {
                return [];
            }

            return ShopifyCustomer::query()
                ->whereNotNull('country_code')
                ->selectRaw('country_code, count(*) as count')
                ->groupBy('country_code')
                ->orderByDesc('count')
                ->limit($limit)
                ->get()
                ->map(fn ($row) => [
                    'country_code' => $row->country_code,
                    'count' => $row->count,
                    'percentage' => round(($row->count / $total) * 100, 1),
                ])
                ->toArray();
        });
    }

    /**
     * @return array{top: array<int, array{country_code: string, trend: array<int, array{month: string, count: int}>, current: int, growth: float}>, other: array<int, array{country_code: string, current: int, growth: float}>}
     */
    public function regionGrowthRates(): array
    {
        return Cache::remember('dashboard:region_growth', 3600, function () {
            $since = CarbonImmutable::now()->subMonths(6)->startOfMonth();

            // Get monthly counts per region for last 6 months
            $data = ShopifyCustomer::query()
                ->whereNotNull('country_code')
                ->where('first_order_at', '>=', $since)
                ->selectRaw("country_code, {$this->yearMonthExpr('first_order_at')} as month, count(*) as count")
                ->groupBy('country_code', 'month')
                ->get();

            // Build 6 months of labels
            $months = collect();
            for ($i = 5; $i >= 0; $i--) {
                $months->push(CarbonImmutable::now()->subMonths($i)->format('Y-m'));
            }

            // Group by country
            $byCountry = $data->groupBy('country_code');

            $regions = $byCountry->map(function ($rows, $code) use ($months) {
                $monthlyCounts = $rows->pluck('count', 'month');

                $trend = $months->map(fn ($m) => [
                    'month' => $m,
                    'count' => (int) $monthlyCounts->get($m, 0),
                ])->toArray();

                $current = (int) $monthlyCounts->get($months->last(), 0);

                // Average MoM growth over 6 months
                $monthlyValues = $months->map(fn ($m) => (int) $monthlyCounts->get($m, 0))->toArray();
                $momChanges = [];
                for ($i = 1; $i < count($monthlyValues); $i++) {
                    if ($monthlyValues[$i - 1] > 0) {
                        $momChanges[] = (($monthlyValues[$i] - $monthlyValues[$i - 1]) / $monthlyValues[$i - 1]) * 100;
                    }
                }
                $avgGrowth = count($momChanges) > 0 ? round(array_sum($momChanges) / count($momChanges), 1) : 0;

                return [
                    'country_code' => $code,
                    'trend' => $trend,
                    'current' => $current,
                    'growth' => $avgGrowth,
                ];
            })
                ->sortByDesc('current')
                ->values();

            // Split into top (>=20 current) and other
            $threshold = 20;

            return [
                'top' => $regions->filter(fn ($r) => $r['current'] >= $threshold)->values()->toArray(),
                'other' => $regions->filter(fn ($r) => $r['current'] < $threshold)->map(fn ($r) => [
                    'country_code' => $r['country_code'],
                    'current' => $r['current'],
                    'growth' => $r['growth'],
                ])->values()->toArray(),
            ];
        });
    }

    /**
     * @return array<int, array{month: string, first_pct: float, returning_pct: float, first_count: int, returning_count: int}>
     */
    public function orderTypeSplit(int $months = 12): array
    {
        return Cache::remember("dashboard:order_type_split:{$months}", 3600, function () use ($months) {
            $since = CarbonImmutable::now()->subMonths($months)->startOfMonth();

            $orders = ShopifyOrder::query()
                ->where('ordered_at', '>=', $since)
                ->join('shopify_customers', 'shopify_orders.customer_id', '=', 'shopify_customers.id')
                ->selectRaw("{$this->yearMonthExpr('shopify_orders.ordered_at')} as month")
                ->selectRaw('sum(case when shopify_customers.orders_count <= 1 then 1 else 0 end) as first_count')
                ->selectRaw('sum(case when shopify_customers.orders_count > 1 then 1 else 0 end) as returning_count')
                ->groupBy('month')
                ->orderBy('month')
                ->get();

            return $orders->map(function ($row) {
                $total = $row->first_count + $row->returning_count;

                return [
                    'month' => $row->month,
                    'first_count' => (int) $row->first_count,
                    'returning_count' => (int) $row->returning_count,
                    'first_pct' => $total > 0 ? round(($row->first_count / $total) * 100, 1) : 0,
                    'returning_pct' => $total > 0 ? round(($row->returning_count / $total) * 100, 1) : 0,
                ];
            })->toArray();
        });
    }

    /**
     * @return array<int, array{month: string, new_revenue: float, returning_revenue: float}>
     */
    public function revenueSplit(int $months = 12): array
    {
        return Cache::remember("dashboard:revenue_split:{$months}", 3600, function () use ($months) {
            $since = CarbonImmutable::now()->subMonths($months)->startOfMonth();

            return ShopifyOrder::query()
                ->where('ordered_at', '>=', $since)
                ->join('shopify_customers', 'shopify_orders.customer_id', '=', 'shopify_customers.id')
                ->selectRaw("{$this->yearMonthExpr('shopify_orders.ordered_at')} as month")
                ->selectRaw('sum(case when shopify_customers.orders_count <= 1 then (shopify_orders.total_price - shopify_orders.tax - shopify_orders.refunded) else 0 end) as new_revenue')
                ->selectRaw('sum(case when shopify_customers.orders_count > 1 then (shopify_orders.total_price - shopify_orders.tax - shopify_orders.refunded) else 0 end) as returning_revenue')
                ->groupBy('month')
                ->orderBy('month')
                ->get()
                ->map(fn ($row) => [
                    'month' => $row->month,
                    'new_revenue' => round((float) $row->new_revenue, 2),
                    'returning_revenue' => round((float) $row->returning_revenue, 2),
                ])
                ->toArray();
        });
    }

    /**
     * Cumulative cohort retention matrix.
     *
     * @return array{cohorts: array<int, array{cohort: string, size: int, retention: array<int, float>}>, max_months: int}
     */
    public function cohortRetention(int $cohortMonths = 12): array
    {
        return Cache::remember("dashboard:cohort_retention:{$cohortMonths}", 3600, function () use ($cohortMonths) {
            $since = CarbonImmutable::now()->subMonths($cohortMonths)->startOfMonth();

            // Get all customers with their cohort month and order dates
            $customers = ShopifyCustomer::query()
                ->where('first_order_at', '>=', $since)
                ->whereNotNull('first_order_at')
                ->select('id', 'first_order_at')
                ->get();

            $customerIds = $customers->pluck('id');

            // Get all orders for these customers
            $orders = ShopifyOrder::query()
                ->whereIn('customer_id', $customerIds)
                ->select('customer_id', 'ordered_at')
                ->get()
                ->groupBy('customer_id');

            // Build cohorts
            $cohorts = $customers->groupBy(fn ($c) => $c->first_order_at->format('Y-m'));

            $result = [];

            foreach ($cohorts->sortKeys() as $cohortMonth => $cohortCustomers) {
                $cohortStart = CarbonImmutable::parse($cohortMonth.'-01');
                $size = $cohortCustomers->count();
                $monthsSinceCohort = (int) $cohortStart->diffInMonths(CarbonImmutable::now());
                $maxMonth = min($monthsSinceCohort, 12);

                $retention = [];

                for ($m = 1; $m <= $maxMonth; $m++) {
                    $cutoff = $cohortStart->addMonths($m);

                    // Cumulative: count customers who made ANY repeat order within M months
                    $retained = $cohortCustomers->filter(function ($customer) use ($orders, $cohortStart, $cutoff) {
                        $customerOrders = $orders->get($customer->id, collect());

                        return $customerOrders->contains(function ($order) use ($cohortStart, $cutoff) {
                            $orderDate = CarbonImmutable::parse($order->ordered_at);

                            return $orderDate > $cohortStart->endOfMonth() && $orderDate <= $cutoff->endOfMonth();
                        });
                    })->count();

                    $retention[$m] = $size > 0 ? round(($retained / $size) * 100, 1) : 0;
                }

                $result[] = [
                    'cohort' => $cohortMonth,
                    'size' => $size,
                    'retention' => $retention,
                ];
            }

            return [
                'cohorts' => $result,
                'max_months' => 12,
            ];
        });
    }

    /**
     * @return array{median_days: int, total_returning: int, curve: array<int, array{days: int, cumulative_pct: float}>, milestones: array<string, float>}
     */
    public function timeToSecondOrder(): array
    {
        return Cache::remember('dashboard:time_to_second', 3600, function () {
            // Find the gap between first and second order per customer
            $daysDiff = $this->daysDiffExpr('ordered_at', 'lag(ordered_at) OVER (PARTITION BY customer_id ORDER BY ordered_at)');

            $gaps = DB::select("
                SELECT
                    customer_id,
                    {$daysDiff} as days_gap
                FROM shopify_orders
                WHERE customer_id IS NOT NULL
                ORDER BY customer_id, ordered_at
            ");

            $secondOrderGaps = collect($gaps)
                ->filter(fn ($row) => $row->days_gap !== null && $row->days_gap > 0)
                ->groupBy('customer_id')
                ->map(fn ($rows) => (int) round($rows->first()->days_gap))
                ->values()
                ->sort()
                ->values();

            if ($secondOrderGaps->isEmpty()) {
                return ['median_days' => 0, 'total_returning' => 0, 'curve' => [], 'milestones' => []];
            }

            $total = $secondOrderGaps->count();
            $median = (int) round($secondOrderGaps->median());

            // Build cumulative curve at day intervals: 7, 14, 21, 30, 45, 60, 90, 120, 150, 180, 240, 300, 365
            $dayPoints = [7, 14, 21, 30, 45, 60, 90, 120, 150, 180, 240, 300, 365];
            $curve = collect($dayPoints)->map(function ($day) use ($secondOrderGaps, $total) {
                $count = $secondOrderGaps->filter(fn ($d) => $d <= $day)->count();

                return [
                    'days' => $day,
                    'cumulative_pct' => round(($count / $total) * 100, 1),
                ];
            })->toArray();

            // Key milestones
            $milestoneDays = [14, 30, 60, 90, 180, 365];
            $milestones = [];
            foreach ($milestoneDays as $day) {
                $count = $secondOrderGaps->filter(fn ($d) => $d <= $day)->count();
                $milestones["{$day}d"] = round(($count / $total) * 100, 1);
            }

            return [
                'median_days' => $median,
                'total_returning' => $total,
                'curve' => $curve,
                'milestones' => $milestones,
            ];
        });
    }

    /**
     * @return array<int, array{country_code: string, total_customers: int, returning_customers: int, retention_pct: float}>
     */
    public function retentionByRegion(int $limit = 15): array
    {
        return Cache::remember("dashboard:retention_region:{$limit}", 3600, function () use ($limit) {
            return ShopifyCustomer::query()
                ->whereNotNull('country_code')
                ->selectRaw('country_code')
                ->selectRaw('count(*) as total_customers')
                ->selectRaw('sum(case when orders_count > 1 then 1 else 0 end) as returning_customers')
                ->groupBy('country_code')
                ->havingRaw('count(*) >= 10')
                ->orderByDesc('total_customers')
                ->limit($limit)
                ->get()
                ->map(fn ($row) => [
                    'country_code' => $row->country_code,
                    'total_customers' => (int) $row->total_customers,
                    'returning_customers' => (int) $row->returning_customers,
                    'retention_pct' => round(($row->returning_customers / $row->total_customers) * 100, 1),
                ])
                ->toArray();
        });
    }

    /**
     * @return array{first_order: array<int, array{month: string, aov: float}>, returning: array<int, array{month: string, aov: float}>}
     */
    public function aovTrend(int $months = 12): array
    {
        return Cache::remember("dashboard:aov_trend:{$months}", 3600, function () use ($months) {
            $since = CarbonImmutable::now()->subMonths($months)->startOfMonth();

            $data = ShopifyOrder::query()
                ->where('ordered_at', '>=', $since)
                ->join('shopify_customers', 'shopify_orders.customer_id', '=', 'shopify_customers.id')
                ->selectRaw("{$this->yearMonthExpr('shopify_orders.ordered_at')} as month")
                ->selectRaw('avg(case when shopify_customers.orders_count <= 1 then (shopify_orders.total_price - shopify_orders.tax - shopify_orders.refunded) end) as first_aov')
                ->selectRaw('avg(case when shopify_customers.orders_count > 1 then (shopify_orders.total_price - shopify_orders.tax - shopify_orders.refunded) end) as returning_aov')
                ->groupBy('month')
                ->orderBy('month')
                ->get();

            return $data->map(fn ($row) => [
                'month' => $row->month,
                'first_aov' => round((float) $row->first_aov, 2),
                'returning_aov' => round((float) $row->returning_aov, 2),
            ])->toArray();
        });
    }

    /**
     * @return array<int, array{product_title: string, count: int, percentage: float}>
     */
    public function topProductsFirstOrder(int $limit = 10): array
    {
        return Cache::remember("dashboard:top_products_first:{$limit}", 3600, function () use ($limit) {
            return $this->topProducts(true, $limit);
        });
    }

    /**
     * @return array<int, array{product_title: string, count: int, percentage: float}>
     */
    public function topProductsReturning(int $limit = 10): array
    {
        return Cache::remember("dashboard:top_products_returning:{$limit}", 3600, function () use ($limit) {
            return $this->topProducts(false, $limit);
        });
    }

    /**
     * @return array<int, array{product_title: string, count: int, percentage: float}>
     */
    protected function topProducts(bool $firstOrder, int $limit): array
    {
        $operator = $firstOrder ? '<=' : '>';

        $total = ShopifyLineItem::query()
            ->join('shopify_orders', 'shopify_line_items.order_id', '=', 'shopify_orders.id')
            ->join('shopify_customers', 'shopify_orders.customer_id', '=', 'shopify_customers.id')
            ->whereRaw("shopify_customers.orders_count {$operator} 1")
            ->count();

        if ($total === 0) {
            return [];
        }

        return ShopifyLineItem::query()
            ->join('shopify_orders', 'shopify_line_items.order_id', '=', 'shopify_orders.id')
            ->join('shopify_customers', 'shopify_orders.customer_id', '=', 'shopify_customers.id')
            ->whereRaw("shopify_customers.orders_count {$operator} 1")
            ->selectRaw('shopify_line_items.product_title, sum(shopify_line_items.quantity) as count')
            ->groupBy('shopify_line_items.product_title')
            ->orderByDesc('count')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'product_title' => $row->product_title,
                'count' => (int) $row->count,
                'percentage' => round(($row->count / $total) * 100, 1),
            ])
            ->toArray();
    }

    public function flushCache(): void
    {
        $keys = [
            'dashboard:kpi:mtd', 'dashboard:kpi:qtd', 'dashboard:kpi:ytd',
            'dashboard:acquisition_trend:12',
            'dashboard:acquisition_region:10',
            'dashboard:region_growth',
            'dashboard:order_type_split:12',
            'dashboard:revenue_split:12',
            'dashboard:cohort_retention:12',
            'dashboard:time_to_second',
            'dashboard:retention_region:15',
            'dashboard:aov_trend:12',
            'dashboard:top_products_first:10',
            'dashboard:top_products_returning:10',
        ];

        foreach ($keys as $key) {
            Cache::forget($key);
        }
    }

    /**
     * @return array{0: array{0: CarbonImmutable, 1: CarbonImmutable}, 1: array{0: CarbonImmutable, 1: CarbonImmutable}}
     */
    protected function periodRanges(string $period): array
    {
        $now = CarbonImmutable::now();

        return match ($period) {
            'qtd' => [
                [$now->firstOfQuarter()->startOfDay(), $now->endOfDay()],
                [$now->subQuarter()->firstOfQuarter()->startOfDay(), $now->subQuarter()->endOfDay()],
            ],
            'ytd' => [
                [$now->startOfYear(), $now->endOfDay()],
                [$now->subYear()->startOfYear(), $now->subYear()->setMonth($now->month)->setDay($now->day)->endOfDay()],
            ],
            default => [ // mtd
                [$now->startOfMonth(), $now->endOfDay()],
                [$now->subMonth()->startOfMonth(), $now->subMonth()->setDay(min($now->day, $now->subMonth()->daysInMonth))->endOfDay()],
            ],
        };
    }

    protected function revenueInRange(CarbonImmutable $from, CarbonImmutable $to): float
    {
        return (float) ShopifyOrder::query()
            ->whereBetween('ordered_at', [$from, $to])
            ->selectRaw('sum(total_price - tax - refunded) as net_revenue')
            ->value('net_revenue');
    }

    protected function ordersInRange(CarbonImmutable $from, CarbonImmutable $to): int
    {
        return ShopifyOrder::query()
            ->whereBetween('ordered_at', [$from, $to])
            ->count();
    }

    protected function newCustomersInRange(CarbonImmutable $from, CarbonImmutable $to): int
    {
        return ShopifyCustomer::query()
            ->whereBetween('first_order_at', [$from, $to])
            ->count();
    }

    protected function returningOrderRate(CarbonImmutable $from, CarbonImmutable $to): float
    {
        $total = ShopifyOrder::query()
            ->whereBetween('ordered_at', [$from, $to])
            ->whereNotNull('customer_id')
            ->count();

        if ($total === 0) {
            return 0;
        }

        $returning = ShopifyOrder::query()
            ->whereBetween('ordered_at', [$from, $to])
            ->whereNotNull('customer_id')
            ->join('shopify_customers', 'shopify_orders.customer_id', '=', 'shopify_customers.id')
            ->where('shopify_customers.orders_count', '>', 1)
            ->count();

        return round(($returning / $total) * 100, 1);
    }

    protected function percentageChange(float $previous, float $current): float
    {
        if ($previous == 0) {
            return $current > 0 ? 100 : 0;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }
}
