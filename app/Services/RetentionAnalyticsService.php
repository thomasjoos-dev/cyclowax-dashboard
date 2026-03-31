<?php

namespace App\Services;

use App\Models\ShopifyCustomer;
use App\Models\ShopifyOrder;
use App\Support\DbDialect;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class RetentionAnalyticsService
{
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
                ->selectRaw(DbDialect::yearMonthExpr('shopify_orders.ordered_at').' as month')
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
     * @return array{cohorts: array, max_months: int}
     */
    public function cohortRetention(int $cohortMonths = 12): array
    {
        return Cache::remember("dashboard:cohort_retention:{$cohortMonths}", 3600, function () use ($cohortMonths) {
            $since = CarbonImmutable::now()->subMonths($cohortMonths)->startOfMonth();

            $customers = ShopifyCustomer::query()
                ->where('first_order_at', '>=', $since)
                ->whereNotNull('first_order_at')
                ->select('id', 'first_order_at')
                ->get();

            $customerIds = $customers->pluck('id');

            $orders = ShopifyOrder::query()
                ->whereIn('customer_id', $customerIds)
                ->select('customer_id', 'ordered_at')
                ->get()
                ->groupBy('customer_id');

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
     * @return array{median_days: int, total_returning: int, curve: array, milestones: array}
     */
    public function timeToSecondOrder(): array
    {
        return Cache::remember('dashboard:time_to_second', 3600, function () {
            $daysDiff = DbDialect::daysDiffExpr('ordered_at', 'lag(ordered_at) OVER (PARTITION BY customer_id ORDER BY ordered_at)');

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

            $dayPoints = [7, 14, 21, 30, 45, 60, 90, 120, 150, 180, 240, 300, 365];
            $curve = collect($dayPoints)->map(function ($day) use ($secondOrderGaps, $total) {
                $count = $secondOrderGaps->filter(fn ($d) => $d <= $day)->count();

                return [
                    'days' => $day,
                    'cumulative_pct' => round(($count / $total) * 100, 1),
                ];
            })->toArray();

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

    public function flushCache(): void
    {
        Cache::forget('dashboard:order_type_split:12');
        Cache::forget('dashboard:cohort_retention:12');
        Cache::forget('dashboard:time_to_second');
        Cache::forget('dashboard:retention_region:15');
    }
}
