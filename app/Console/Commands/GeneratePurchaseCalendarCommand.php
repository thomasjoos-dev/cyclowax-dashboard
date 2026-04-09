<?php

namespace App\Console\Commands;

use App\Enums\Warehouse;
use App\Models\PurchaseCalendarRun;
use App\Models\Scenario;
use App\Models\SupplyProfile;
use App\Services\Forecast\Supply\ComponentNettingService;
use App\Services\Forecast\Supply\PurchaseCalendarTrackingService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

#[Signature('forecast:purchase-calendar {scenario : Scenario name (e.g. base)} {--year= : Forecast year (defaults to current)} {--warehouse= : Generate for a specific warehouse (be, us)} {--export : Export to CSV on Desktop}')]
#[Description('Generate purchase + production calendar based on demand forecast, BOM explosion and stock netting')]
class GeneratePurchaseCalendarCommand extends Command
{
    public function handle(PurchaseCalendarTrackingService $trackingService, ComponentNettingService $netting): int
    {
        try {
            $scenarioName = $this->argument('scenario');
            $year = (int) ($this->option('year') ?? now()->year);

            $scenario = Scenario::where('name', $scenarioName)->where('year', $year)->first();

            if (! $scenario) {
                $this->error("Scenario '{$scenarioName}' not found for year {$year}.");

                return self::FAILURE;
            }

            // Resolve optional warehouse filter
            $warehouseValue = $this->option('warehouse');
            $warehouse = null;
            if ($warehouseValue) {
                $warehouse = Warehouse::tryFrom($warehouseValue);
                if (! $warehouse) {
                    $this->error("Unknown warehouse: {$warehouseValue}. Valid: ".implode(', ', array_map(fn ($w) => $w->value, Warehouse::cases())));

                    return self::FAILURE;
                }
            }

            // Data quality warnings
            $this->checkStockFreshness($netting);
            $this->checkSupplyProfileValidation();

            $warehouseLabel = $warehouse ? " [{$warehouse->label()}]" : '';
            $this->info("Generating purchase + production calendar: {$scenario->label} ({$year}){$warehouseLabel}...");
            $this->newLine();

            $run = $trackingService->record($scenario, $year, $warehouse);

            if ($run->events->isEmpty()) {
                $this->warn('No events generated. Check forecast data and BOM configuration.');

                return self::SUCCESS;
            }

            $this->renderRun($run);

            // CSV export
            if ($this->option('export')) {
                $this->exportCsv($run);
            }

            $this->info("Persisted: {$run->events->count()} events (generated_at: {$run->generated_at->format('Y-m-d H:i')})");

            return self::SUCCESS;
        } catch (\Throwable $e) {
            Log::error('GeneratePurchaseCalendarCommand failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function renderRun(PurchaseCalendarRun $run): void
    {
        // Summary
        $summary = $run->summary;
        $this->info("Timeline: {$summary['total_events']} events ({$summary['purchase_events']} purchases, {$summary['production_events']} production orders)");
        $this->newLine();

        // Netting summary
        $this->components->info('Component Netting Summary:');
        $nettingRows = [];

        foreach ($run->netting_summary as $category => $components) {
            foreach ($components as $comp) {
                if ($comp['gross_need'] <= 0) {
                    continue;
                }

                $shortfallMonth = $comp['first_shortfall_month'] ?? null;
                $shortfallLabel = $shortfallMonth
                    ? date('M', mktime(0, 0, 0, $shortfallMonth, 1))
                    : '-';

                $nettingRows[] = [
                    $category,
                    $comp['sku'],
                    substr($comp['name'], 0, 30),
                    number_format($comp['gross_need']),
                    number_format($comp['stock_available']),
                    number_format($comp['open_po_qty']),
                    number_format($comp['net_need']),
                    $shortfallLabel,
                    $comp['net_need'] > 0 ? 'ORDER' : 'OK',
                ];
            }
        }

        $this->table(
            ['Category', 'SKU', 'Product', 'Gross Need', 'Stock', 'Open PO', 'Net Need', '1st Short', 'Status'],
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

        foreach ($run->events as $event) {
            $timelineRows[] = [
                $event->date->format('Y-m-d'),
                $icons[$event->event_type] ?? $event->event_type,
                $event->sku ?: '-',
                substr($event->name, 0, 35),
                number_format((float) $event->quantity),
                $event->supplier ?? '-',
                $event->month_label ?? '-',
                substr($event->note ?? '', 0, 45),
            ];
        }

        $this->table(
            ['Date', 'Type', 'SKU', 'Product', 'Qty', 'Supplier', 'Month', 'Note'],
            $timelineRows,
        );
    }

    private function checkStockFreshness(ComponentNettingService $netting): void
    {
        $freshness = $netting->stockFreshness();

        if ($freshness['latest_at'] === null) {
            $this->warn('⚠ No stock snapshots found. Run odoo:sync-products first.');
            $this->newLine();

            return;
        }

        if ($freshness['is_stale']) {
            $this->warn("⚠ Stock data is {$freshness['age_hours']}h old (last sync: {$freshness['latest_at']->format('Y-m-d H:i')}). Netting may be inaccurate. Run odoo:sync-products to refresh.");
            $this->newLine();
        }
    }

    private function checkSupplyProfileValidation(): void
    {
        $unvalidated = SupplyProfile::whereNull('validated_at')->get();

        if ($unvalidated->isEmpty()) {
            return;
        }

        $categories = $unvalidated->map(fn ($p) => $p->product_category->value)->implode(', ');
        $this->warn("⚠ {$unvalidated->count()} supply profile(s) not validated by procurement: {$categories}");
        $this->newLine();
    }

    private function exportCsv(PurchaseCalendarRun $run): void
    {
        $scenario = $run->scenario;
        $filename = "Cyclowax_Purchase_Calendar_{$scenario->name}_{$run->year}.csv";
        $path = ($_SERVER['HOME'] ?? '/tmp').'/'.'Desktop/'.$filename;

        $fp = fopen($path, 'w');
        fwrite($fp, "\xEF\xBB\xBF"); // UTF-8 BOM

        $headers = ['Date', 'Event Type', 'SKU', 'Product', 'Quantity', 'Gross Qty', 'Net Qty', 'Supplier', 'Category', 'Month', 'Scenario', 'Note'];
        fwrite($fp, implode(';', $headers)."\n");

        foreach ($run->events as $event) {
            $row = [
                $event->date->format('Y-m-d'),
                $event->event_type,
                $event->sku ?? '',
                '"'.str_replace('"', '""', $event->name ?? '').'"',
                $event->quantity,
                $event->gross_quantity ?? '',
                $event->net_quantity ?? '',
                '"'.str_replace('"', '""', $event->supplier ?? '').'"',
                $event->product_category->value ?? '',
                $event->month_label ?? '',
                $scenario->name,
                '"'.str_replace('"', '""', $event->note ?? '').'"',
            ];
            fwrite($fp, implode(';', $row)."\n");
        }

        fclose($fp);

        $this->newLine();
        $this->info("Exported to: {$path}");
    }
}
