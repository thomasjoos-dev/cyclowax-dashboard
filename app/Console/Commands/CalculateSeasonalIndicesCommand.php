<?php

namespace App\Console\Commands;

use App\Services\Forecast\Demand\SeasonalIndexCalculator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('seasonal:calculate {--region= : Calculate for a specific country code instead of global}')]
#[Description('Calculate monthly seasonal indices from historical order data')]
class CalculateSeasonalIndicesCommand extends Command
{
    public function handle(SeasonalIndexCalculator $calculator): int
    {
        $region = $this->option('region');
        $label = $region ?? 'global';
        $this->info("Calculating seasonal indices ({$label})...");

        $normalized = $calculator->calculate($region);

        if ($normalized === null) {
            $this->error('No order data found or average is zero.');

            return self::FAILURE;
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
}
