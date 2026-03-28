<?php

namespace App\Console\Commands;

use App\Models\SeasonalIndex;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

#[Signature('seasonal:calculate {--region= : Calculate for a specific country code instead of global}')]
#[Description('Calculate monthly seasonal indices from historical order data')]
class CalculateSeasonalIndicesCommand extends Command
{
    public function handle(): int
    {
        $region = $this->option('region');
        $label = $region ?? 'global';
        $this->info("Calculating seasonal indices ({$label})...");

        $monthlyCounts = $this->getMonthlyOrderCounts($region);

        if ($monthlyCounts->isEmpty()) {
            $this->error('No order data found.');

            return self::FAILURE;
        }

        // Calculate average orders per calendar month across all years
        $monthlyAverages = [];
        foreach ($monthlyCounts as $row) {
            $monthlyAverages[$row->month][] = $row->order_count;
        }

        $indices = [];
        foreach ($monthlyAverages as $month => $counts) {
            $indices[$month] = array_sum($counts) / count($counts);
        }

        // Normalize: average index = 1.0
        $overallAvg = array_sum($indices) / count($indices);

        if ($overallAvg == 0) {
            $this->error('Average monthly orders is zero — cannot calculate indices.');

            return self::FAILURE;
        }

        $normalized = [];
        foreach ($indices as $month => $avg) {
            $normalized[$month] = round($avg / $overallAvg, 4);
        }

        // Upsert into seasonal_indices
        foreach ($normalized as $month => $indexValue) {
            SeasonalIndex::updateOrCreate(
                ['month' => $month, 'region' => $region],
                ['index_value' => $indexValue, 'source' => 'calculated'],
            );
        }

        $this->info('Seasonal indices calculated:');
        $this->table(
            ['Month', 'Index', 'Interpretation'],
            collect($normalized)->map(fn ($val, $month) => [
                $month,
                number_format($val, 4),
                $val >= 1.1 ? 'Above average' : ($val <= 0.9 ? 'Below average' : 'Average'),
            ])->sortKeys()->values()->toArray(),
        );

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, object{month: int, year: string, order_count: int}>
     */
    private function getMonthlyOrderCounts(?string $region): Collection
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
