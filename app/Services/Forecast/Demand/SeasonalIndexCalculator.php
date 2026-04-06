<?php

namespace App\Services\Forecast\Demand;

use App\Enums\ForecastRegion;
use App\Models\SeasonalIndex;
use App\Support\DbDialect;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SeasonalIndexCalculator
{
    /**
     * Calculate and persist seasonal indices for a region (or global).
     *
     * @return array<int, float>|null Normalized indices keyed by month (1-12), or null if no data
     */
    public function calculate(?ForecastRegion $region = null): ?array
    {
        $monthlyCounts = $this->getMonthlyOrderCounts($region);

        if ($monthlyCounts->isEmpty()) {
            return null;
        }

        $normalized = $this->normalize($monthlyCounts);

        $regionValue = $region?->value;

        foreach ($normalized as $month => $indexValue) {
            SeasonalIndex::updateOrCreate(
                ['month' => $month, 'region' => $regionValue],
                ['index_value' => $indexValue, 'source' => 'calculated'],
            );
        }

        return $normalized;
    }

    /**
     * Normalize monthly order counts to indices where average = 1.0.
     *
     * @return array<int, float>|null
     */
    public function normalize(Collection $monthlyCounts): ?array
    {
        $monthlyAverages = [];
        foreach ($monthlyCounts as $row) {
            $monthlyAverages[$row->month][] = $row->order_count;
        }

        $indices = [];
        foreach ($monthlyAverages as $month => $counts) {
            $indices[$month] = array_sum($counts) / count($counts);
        }

        $overallAvg = array_sum($indices) / count($indices);

        if ($overallAvg == 0) {
            return null;
        }

        $normalized = [];
        foreach ($indices as $month => $avg) {
            $normalized[$month] = round($avg / $overallAvg, 4);
        }

        return $normalized;
    }

    /**
     * @return Collection<int, object{month: int, year: string, order_count: int}>
     */
    public function getMonthlyOrderCounts(?ForecastRegion $region): Collection
    {
        $query = DB::table('shopify_orders')
            ->whereNotIn('financial_status', ['voided', 'refunded'])
            ->selectRaw(DbDialect::monthExpr('ordered_at').' as month')
            ->selectRaw(DbDialect::yearExpr('ordered_at').' as year')
            ->selectRaw('COUNT(*) as order_count')
            ->groupBy('year', 'month')
            ->orderBy('year')
            ->orderBy('month');

        if ($region !== null) {
            $countries = $region->countries();

            if ($countries === []) {
                // ROW: exclude all mapped countries
                $allMapped = collect(ForecastRegion::cases())
                    ->filter(fn (ForecastRegion $r) => $r !== ForecastRegion::Row)
                    ->flatMap(fn (ForecastRegion $r) => $r->countries())
                    ->all();

                $query->whereNotIn('shipping_country_code', $allMapped);
            } else {
                $query->whereIn('shipping_country_code', $countries);
            }
        }

        return $query->get();
    }
}
