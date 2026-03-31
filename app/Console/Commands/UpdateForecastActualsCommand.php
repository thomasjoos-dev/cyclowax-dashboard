<?php

namespace App\Console\Commands;

use App\Services\Forecast\ForecastTrackingService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('forecast:update-actuals {yearMonth : Month to update (YYYY-MM format)}')]
#[Description('Fill in actual units and revenue for a completed forecast month')]
class UpdateForecastActualsCommand extends Command
{
    public function handle(ForecastTrackingService $service): int
    {
        $yearMonth = $this->argument('yearMonth');

        if (! preg_match('/^\d{4}-\d{2}$/', $yearMonth)) {
            $this->error('Invalid format. Use YYYY-MM (e.g. 2026-03).');

            return self::FAILURE;
        }

        $this->info("Updating actuals for {$yearMonth}...");

        $updated = $service->updateActuals($yearMonth);

        if ($updated === 0) {
            $this->warn('No forecast snapshots found for this month. Run forecast:generate first.');

            return self::FAILURE;
        }

        $this->info("Updated {$updated} snapshot rows with actual data.");

        return self::SUCCESS;
    }
}
