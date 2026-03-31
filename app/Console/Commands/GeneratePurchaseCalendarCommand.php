<?php

namespace App\Console\Commands;

use App\Models\Scenario;
use App\Services\Forecast\PurchaseCalendarService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('forecast:purchase-calendar {scenario : Scenario name (e.g. base)} {--year= : Forecast year (defaults to current)} {--export : Export to CSV on Desktop}')]
#[Description('Generate purchase + production calendar based on demand forecast, BOM explosion and stock netting')]
class GeneratePurchaseCalendarCommand extends Command
{
    public function handle(PurchaseCalendarService $calendarService): int
    {
        $scenarioName = $this->argument('scenario');
        $year = (int) ($this->option('year') ?? now()->year);

        $scenario = Scenario::where('name', $scenarioName)->where('year', $year)->first();

        if (! $scenario) {
            $this->error("Scenario '{$scenarioName}' not found for year {$year}.");

            return self::FAILURE;
        }

        $this->info("Generating purchase + production calendar: {$scenario->label} ({$year})...");
        $this->newLine();

        $result = $calendarService->generate($scenario, $year);
        $timeline = $result['timeline'];

        if (empty($timeline)) {
            $this->warn('No events generated. Check forecast data and BOM configuration.');

            return self::SUCCESS;
        }

        // Summary
        $summary = $result['summary'];
        $this->info("Timeline: {$summary['total_events']} events ({$summary['purchase_events']} purchases, {$summary['production_events']} production orders)");
        $this->newLine();

        // Netting summary
        $this->components->info('Component Netting Summary:');
        $nettingRows = [];

        foreach ($result['netting'] as $category => $components) {
            foreach ($components as $comp) {
                if ($comp['gross_need'] <= 0) {
                    continue;
                }

                $nettingRows[] = [
                    $category,
                    $comp['sku'],
                    substr($comp['name'], 0, 35),
                    number_format($comp['gross_need']),
                    number_format($comp['stock_available']),
                    number_format($comp['open_po_qty']),
                    number_format($comp['net_need']),
                    $comp['net_need'] > 0 ? 'ORDER' : 'OK',
                ];
            }
        }

        $this->table(
            ['Category', 'SKU', 'Product', 'Gross Need', 'Stock', 'Open PO', 'Net Need', 'Status'],
            $nettingRows,
        );

        $this->newLine();

        // Timeline table
        $this->components->info('Purchase + Production Calendar:');

        $icons = [
            'purchase' => 'ORDER',
            'receipt' => 'RECV',
            'production_start' => 'PROD→',
            'production_done' => 'DONE✓',
        ];

        $timelineRows = [];

        foreach ($timeline as $event) {
            $timelineRows[] = [
                $event['date'],
                $icons[$event['event_type']] ?? $event['event_type'],
                $event['sku'] ?: '-',
                substr($event['name'], 0, 35),
                number_format($event['quantity']),
                $event['supplier'] ?? '-',
                $event['quarter'] ?? '-',
                substr($event['note'] ?? '', 0, 45),
            ];
        }

        $this->table(
            ['Date', 'Type', 'SKU', 'Product', 'Qty', 'Supplier', 'Quarter', 'Note'],
            $timelineRows,
        );

        // CSV export
        if ($this->option('export')) {
            $this->exportCsv($result, $scenario, $year);
        }

        return self::SUCCESS;
    }

    /**
     * @param  array{timeline: array, summary: array, netting: array}  $result
     */
    private function exportCsv(array $result, Scenario $scenario, int $year): void
    {
        $filename = "Cyclowax_Purchase_Calendar_{$scenario->name}_{$year}.csv";
        $path = ($_SERVER['HOME'] ?? '/tmp').'/'.'Desktop/'.$filename;

        $fp = fopen($path, 'w');
        fwrite($fp, "\xEF\xBB\xBF"); // UTF-8 BOM

        // Timeline sheet
        $headers = ['Date', 'Event Type', 'SKU', 'Product', 'Quantity', 'Gross Qty', 'Net Qty', 'Supplier', 'Category', 'Quarter', 'Scenario', 'Note'];
        fwrite($fp, implode(';', $headers)."\n");

        foreach ($result['timeline'] as $event) {
            $row = [
                $event['date'],
                $event['event_type'],
                $event['sku'] ?? '',
                '"'.str_replace('"', '""', $event['name'] ?? '').'"',
                $event['quantity'],
                $event['gross_quantity'] ?? '',
                $event['net_quantity'] ?? '',
                '"'.str_replace('"', '""', $event['supplier'] ?? '').'"',
                $event['category'] ?? '',
                $event['quarter'] ?? '',
                $event['scenario'] ?? '',
                '"'.str_replace('"', '""', $event['note'] ?? '').'"',
            ];
            fwrite($fp, implode(';', $row)."\n");
        }

        fclose($fp);

        $this->newLine();
        $this->info("Exported to: {$path}");
    }
}
