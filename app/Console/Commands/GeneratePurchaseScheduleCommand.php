<?php

namespace App\Console\Commands;

use App\Models\Scenario;
use App\Services\Forecast\StockPlanningService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('forecast:purchase-schedule {scenario : Scenario name} {--year= : Forecast year (defaults to current)}')]
#[Description('Generate a purchase schedule based on demand forecast and supply chain parameters')]
class GeneratePurchaseScheduleCommand extends Command
{
    public function handle(StockPlanningService $service): int
    {
        $year = (int) ($this->option('year') ?? date('Y'));
        $scenarioName = $this->argument('scenario');

        $scenario = Scenario::where('name', $scenarioName)->forYear($year)->first();

        if (! $scenario) {
            $this->error("Scenario '{$scenarioName}' not found for year {$year}.");

            return self::FAILURE;
        }

        $this->info("Purchase schedule: {$scenario->label} ({$year})");

        $timeline = $service->reorderTimeline($scenario, $year);

        if (empty($timeline)) {
            $this->info('No purchase orders needed — stock covers forecasted demand.');

            return self::SUCCESS;
        }

        $rows = [];
        foreach ($timeline as $entry) {
            $rows[] = [
                $entry['order_date'],
                $entry['category'],
                number_format($entry['quantity']),
                "Month {$entry['delivery_month']}",
                $entry['reason'],
            ];
        }

        $this->table(['Order Date', 'Category', 'Quantity', 'Delivery', 'Reason'], $rows);
        $this->info('Total purchase orders: '.count($timeline));

        return self::SUCCESS;
    }
}
