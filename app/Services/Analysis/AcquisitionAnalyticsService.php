<?php

namespace App\Services\Analysis;

use App\Models\ShopifyCustomer;
use App\Support\DbDialect;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

class AcquisitionAnalyticsService
{
    /**
     * @return array<int, array{month: string, count: int}>
     */
    public function acquisitionTrend(int $months = 12): array
    {
        return Cache::remember("dashboard:acquisition_trend:{$months}", config('dashboard.cache_ttl'), function () use ($months) {
            $since = CarbonImmutable::now()->subMonths($months)->startOfMonth();

            return ShopifyCustomer::query()
                ->where('first_order_at', '>=', $since)
                ->selectRaw(DbDialect::yearMonthExpr('first_order_at').' as month, count(*) as count')
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
        return Cache::remember("dashboard:acquisition_region:{$limit}", config('dashboard.cache_ttl'), function () use ($limit) {
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
     * @return array{top: array, other: array}
     */
    public function regionGrowthRates(): array
    {
        return Cache::remember('dashboard:region_growth', config('dashboard.cache_ttl'), function () {
            $since = CarbonImmutable::now()->subMonths(6)->startOfMonth();

            $data = ShopifyCustomer::query()
                ->whereNotNull('country_code')
                ->where('first_order_at', '>=', $since)
                ->selectRaw('country_code, '.DbDialect::yearMonthExpr('first_order_at').' as month, count(*) as count')
                ->groupBy('country_code', 'month')
                ->get();

            $months = collect();
            for ($i = 5; $i >= 0; $i--) {
                $months->push(CarbonImmutable::now()->subMonths($i)->format('Y-m'));
            }

            $byCountry = $data->groupBy('country_code');

            $regions = $byCountry->map(function ($rows, $code) use ($months) {
                $monthlyCounts = $rows->pluck('count', 'month');

                $trend = $months->map(fn ($m) => [
                    'month' => $m,
                    'count' => (int) $monthlyCounts->get($m, 0),
                ])->toArray();

                $current = (int) $monthlyCounts->get($months->last(), 0);

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

    public function flushCache(): void
    {
        Cache::forget('dashboard:acquisition_trend:12');
        Cache::forget('dashboard:acquisition_region:10');
        Cache::forget('dashboard:region_growth');
    }
}
