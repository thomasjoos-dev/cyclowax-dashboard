<?php

namespace App\Console\Commands;

use App\Enums\ForecastRegion;
use App\Services\Forecast\Demand\SeasonalIndexCalculator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

#[Signature('seasonal:calculate {--region= : Calculate for a specific region (e.g. de, eu_alpine)}')]
#[Description('Calculate monthly seasonal indices from historical order data')]
class CalculateSeasonalIndicesCommand extends Command
{
    public function handle(SeasonalIndexCalculator $calculator): int
    {
        try {
            $regionValue = $this->option('region');
            $region = null;

            if ($regionValue) {
                $region = ForecastRegion::tryFrom($regionValue);

                if (! $region) {
                    $this->error("Unknown region: {$regionValue}. Valid: ".implode(', ', array_map(fn ($r) => $r->value, ForecastRegion::cases())));

                    return self::FAILURE;
                }
            }

            $label = $region ? $region->label() : 'global';
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
        } catch (\Throwable $e) {
            Log::error('CalculateSeasonalIndicesCommand failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
