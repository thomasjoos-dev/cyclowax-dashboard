<?php

namespace App\Console\Commands;

use App\Models\Scenario;
use App\Services\Forecast\DemandForecastService;
use App\Services\Forecast\ForecastTrackingService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('forecast:generate {scenario : Scenario name (e.g. base, conservative, ambitious)} {--year= : Forecast year (defaults to current)}')]
#[Description('Generate a demand forecast per category per month and save as snapshot')]
class GenerateDemandForecastCommand extends Command
{
    public function handle(DemandForecastService $forecastService, ForecastTrackingService $trackingService): int
    {
        $year = (int) ($this->option('year') ?? date('Y'));
        $scenarioName = $this->argument('scenario');

        $scenario = Scenario::where('name', $scenarioName)->forYear($year)->first();

        if (! $scenario) {
            $this->error("Scenario '{$scenarioName}' not found for year {$year}.");

            return self::FAILURE;
        }

        $this->info("Generating demand forecast: {$scenario->label} ({$year})...");

        $total = $forecastService->totalForecast($scenario, $year);

        // Display monthly summary
        $rows = [];
        foreach ($total['months'] as $month => $data) {
            $rows[] = [
                str_pad($month, 2, '0', STR_PAD_LEFT),
                number_format($data['units']),
                '€'.number_format($data['revenue'], 0, ',', '.'),
            ];
        }
        $rows[] = ['TOTAL', number_format($total['year_total']['units']), '€'.number_format($total['year_total']['revenue'], 0, ',', '.')];

        $this->table(['Month', 'Units', 'Revenue'], $rows);

        // Save snapshot
        $snapshotCount = $trackingService->recordSnapshot($scenario, $year);
        $this->info("Saved {$snapshotCount} forecast snapshot rows.");

        return self::SUCCESS;
    }
}
