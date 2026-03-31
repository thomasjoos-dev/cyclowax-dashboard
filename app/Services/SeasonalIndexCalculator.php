<?php

namespace App\Services;

use App\Models\SeasonalIndex;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SeasonalIndexCalculator
{
    /**
     * Calculate and persist seasonal indices for a region (or global).
     *
     * @return array<int, float>|null Normalized indices keyed by month (1-12), or null if no data
     */
    public function calculate(?string $region = null): ?array
    {
        $monthlyCounts = $this->getMonthlyOrderCounts($region);

        if ($monthlyCounts->isEmpty()) {
            return null;
        }

        $normalized = $this->normalize($monthlyCounts);

        foreach ($normalized as $month => $indexValue) {
            SeasonalIndex::updateOrCreate(
                ['month' => $month, 'region' => $region],
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
    public function getMonthlyOrderCounts(?string $region): Collection
    {
        $query = DB::table('shopify_orders')
            ->whereNotIn('financial_status', ['voided', 'refunded'])
            ->selectRaw("CAST(strftime('%m', ordered_at) AS INTEGER) as month")
            ->selectRaw("strftime('%Y', ordered_at) as year")
            ->selectRaw('COUNT(*) as order_count')
            ->groupBy('year', 'month')
            ->orderBy('year')
            ->orderBy('month');

        if ($region) {
            $query->where('country_code', $region);
        }

        return $query->get();
    }
}
