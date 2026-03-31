<?php

namespace App\Services\Analysis;

use App\Models\ShopifyCustomer;
use App\Models\ShopifyOrder;
use App\Support\DbDialect;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

class RevenueAnalyticsService
{
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
     * @return array<int, array{month: string, new_revenue: float, returning_revenue: float}>
     */
    public function revenueSplit(int $months = 12): array
    {
        return Cache::remember("dashboard:revenue_split:{$months}", 3600, function () use ($months) {
            $since = CarbonImmutable::now()->subMonths($months)->startOfMonth();

            return ShopifyOrder::query()
                ->where('ordered_at', '>=', $since)
                ->join('shopify_customers', 'shopify_orders.customer_id', '=', 'shopify_customers.id')
                ->selectRaw(DbDialect::yearMonthExpr('shopify_orders.ordered_at').' as month')
                ->selectRaw('sum(case when shopify_customers.orders_count <= 1 then (shopify_orders.net_revenue) else 0 end) as new_revenue')
                ->selectRaw('sum(case when shopify_customers.orders_count > 1 then (shopify_orders.net_revenue) else 0 end) as returning_revenue')
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
     * @return array{first_order: array, returning: array}
     */
    public function aovTrend(int $months = 12): array
    {
        return Cache::remember("dashboard:aov_trend:{$months}", 3600, function () use ($months) {
            $since = CarbonImmutable::now()->subMonths($months)->startOfMonth();

            $data = ShopifyOrder::query()
                ->where('ordered_at', '>=', $since)
                ->join('shopify_customers', 'shopify_orders.customer_id', '=', 'shopify_customers.id')
                ->selectRaw(DbDialect::yearMonthExpr('shopify_orders.ordered_at').' as month')
                ->selectRaw('avg(case when shopify_customers.orders_count <= 1 then (shopify_orders.net_revenue) end) as first_aov')
                ->selectRaw('avg(case when shopify_customers.orders_count > 1 then (shopify_orders.net_revenue) end) as returning_aov')
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

    public function flushCache(): void
    {
        Cache::forget('dashboard:kpi:mtd');
        Cache::forget('dashboard:kpi:qtd');
        Cache::forget('dashboard:kpi:ytd');
        Cache::forget('dashboard:revenue_split:12');
        Cache::forget('dashboard:aov_trend:12');
    }

    /**
     * @return array{0: array{0: CarbonImmutable, 1: CarbonImmutable}, 1: array{0: CarbonImmutable, 1: CarbonImmutable}}
     */
    private function periodRanges(string $period): array
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
            default => [
                [$now->startOfMonth(), $now->endOfDay()],
                [$now->subMonth()->startOfMonth(), $now->subMonth()->setDay(min($now->day, $now->subMonth()->daysInMonth))->endOfDay()],
            ],
        };
    }

    private function revenueInRange(CarbonImmutable $from, CarbonImmutable $to): float
    {
        return (float) ShopifyOrder::query()
            ->whereBetween('ordered_at', [$from, $to])
            ->selectRaw('sum(net_revenue) as net_revenue')
            ->value('net_revenue');
    }

    private function ordersInRange(CarbonImmutable $from, CarbonImmutable $to): int
    {
        return ShopifyOrder::query()
            ->whereBetween('ordered_at', [$from, $to])
            ->count();
    }

    private function newCustomersInRange(CarbonImmutable $from, CarbonImmutable $to): int
    {
        return ShopifyCustomer::query()
            ->whereBetween('first_order_at', [$from, $to])
            ->count();
    }

    private function returningOrderRate(CarbonImmutable $from, CarbonImmutable $to): float
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

    private function percentageChange(float $previous, float $current): float
    {
        if ($previous == 0) {
            return $current > 0 ? 100 : 0;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }
}
