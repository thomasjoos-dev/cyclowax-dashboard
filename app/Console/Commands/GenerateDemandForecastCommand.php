<?php

namespace App\Console\Commands;

use App\Enums\ForecastRegion;
use App\Models\Scenario;
use App\Services\Forecast\Demand\DemandForecastService;
use App\Services\Forecast\Demand\RegionalForecastAggregator;
use App\Services\Forecast\Tracking\ForecastTrackingService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

#[Signature('forecast:generate {scenario : Scenario name (e.g. base, conservative, ambitious)} {--year= : Forecast year (defaults to current)} {--region= : Generate for a specific region (e.g. de, eu_alpine)} {--all-regions : Generate for all regions with summary}')]
#[Description('Generate a demand forecast per category per month and save as snapshot')]
class GenerateDemandForecastCommand extends Command
{
    public function handle(
        DemandForecastService $forecastService,
        ForecastTrackingService $trackingService,
        RegionalForecastAggregator $aggregator,
    ): int {
        try {
            $year = (int) ($this->option('year') ?? date('Y'));
            $scenarioName = $this->argument('scenario');

            $scenario = Scenario::where('name', $scenarioName)->forYear($year)->first();

            if (! $scenario) {
                $this->error("Scenario '{$scenarioName}' not found for year {$year}.");

                return self::FAILURE;
            }

            // Resolve region
            $regionValue = $this->option('region');
            $allRegions = $this->option('all-regions');
            $region = null;

            if ($regionValue) {
                $region = ForecastRegion::tryFrom($regionValue);
                if (! $region) {
                    $this->error("Unknown region: {$regionValue}. Valid: ".implode(', ', array_map(fn ($r) => $r->value, ForecastRegion::cases())));

                    return self::FAILURE;
                }
            }

            if ($allRegions) {
                return $this->generateAllRegions($scenario, $year, $forecastService, $trackingService, $aggregator);
            }

            return $this->generateSingle($scenario, $year, $region, $forecastService, $trackingService);
        } catch (\Throwable $e) {
            Log::error('GenerateDemandForecastCommand failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function generateSingle(
        Scenario $scenario,
        int $year,
        ?ForecastRegion $region,
        DemandForecastService $forecastService,
        ForecastTrackingService $trackingService,
    ): int {
        $regionLabel = $region ? " [{$region->label()}]" : '';
        $this->info("Generating demand forecast: {$scenario->label} ({$year}){$regionLabel}...");

        // Show repeat model info
        $modelInfo = $forecastService->repeatModelInfo($scenario, $region);
        if ($modelInfo['model'] === 'cohort') {
            $this->info("Repeat model: cohort-based ({$modelInfo['cohorts_used']} cohorts, curve adjustment: {$modelInfo['curve_adjustment']}, source: {$modelInfo['source']})");
        } else {
            $this->warn('Repeat model: flat (no retention data available)');
        }

        $total = $forecastService->totalForecast($scenario, $year, $region);

        $this->displayMonthlyTable($total);

        // Save snapshot
        $snapshotCount = $trackingService->recordSnapshot($scenario, $year, $region);
        $this->info("Saved {$snapshotCount} forecast snapshot rows.");

        return self::SUCCESS;
    }

    private function generateAllRegions(
        Scenario $scenario,
        int $year,
        DemandForecastService $forecastService,
        ForecastTrackingService $trackingService,
        RegionalForecastAggregator $aggregator,
    ): int {
        $this->info("Generating regional demand forecast: {$scenario->label} ({$year})...");
        $this->newLine();

        $result = $aggregator->forecastAllRegions($scenario, $year);

        // Regional summary table
        $summaryRows = [];
        foreach (ForecastRegion::cases() as $region) {
            $rt = $result['region_totals'][$region->value] ?? ['units' => 0, 'revenue' => 0.0, 'cm1' => ['cm1' => 0.0, 'cm1_pct' => 0.0]];
            if ($rt['revenue'] <= 0) {
                continue;
            }

            $summaryRows[] = [
                $region->label(),
                number_format($rt['units']),
                '€'.number_format($rt['revenue'], 0, ',', '.'),
                '€'.number_format($rt['cm1']['cm1'], 0, ',', '.'),
                $rt['cm1']['cm1_pct'].'%',
            ];
        }

        $summaryRows[] = [
            'TOTAL',
            number_format($result['year_total']['units']),
            '€'.number_format($result['year_total']['revenue'], 0, ',', '.'),
            '€'.number_format($result['cm1_total']['cm1'], 0, ',', '.'),
            $result['cm1_total']['cm1_pct'].'%',
        ];

        $this->table(['Region', 'Units', 'Revenue', 'CM1', 'CM1%'], $summaryRows);

        // Save snapshots per region
        $totalSnapshots = 0;
        foreach (ForecastRegion::cases() as $region) {
            $totalSnapshots += $trackingService->recordSnapshot($scenario, $year, $region);
        }
        // Also save global (null region) snapshot
        $totalSnapshots += $trackingService->recordSnapshot($scenario, $year);

        $this->newLine();
        $this->info("Saved {$totalSnapshots} forecast snapshot rows (all regions + global).");

        return self::SUCCESS;
    }

    /**
     * @param  array{months: array<int, array{units: int, revenue: float}>, year_total: array{units: int, revenue: float}}  $total
     */
    private function displayMonthlyTable(array $total): void
    {
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
    }
}
