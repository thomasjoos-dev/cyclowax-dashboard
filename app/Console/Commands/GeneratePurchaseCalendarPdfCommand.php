<?php

namespace App\Console\Commands;

use App\Models\PurchaseCalendarRun;
use App\Models\Scenario;
use App\Services\Forecast\Supply\PurchaseCalendarPdfService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

#[Signature('forecast:purchase-calendar-pdf {--year= : Forecast year (defaults to current)} {--months= : Month range to include, e.g. 4-6 for Apr-Jun}')]
#[Description('Generate Purchase & Production Calendar PDF for all active scenarios')]
class GeneratePurchaseCalendarPdfCommand extends Command
{
    public function handle(PurchaseCalendarPdfService $pdfService): int
    {
        try {
            ini_set('memory_limit', '512M');

            $year = (int) ($this->option('year') ?? now()->year);
            $months = $this->parseMonthRange();

            $scenarios = Scenario::query()
                ->active()
                ->forYear($year)
                ->orderBy('name')
                ->get();

            if ($scenarios->isEmpty()) {
                $this->error("No active scenarios found for year {$year}.");

                return self::FAILURE;
            }

            $this->info("Loading purchase calendar runs for {$year}...");

            $runs = [];
            $missing = [];

            foreach ($scenarios as $scenario) {
                $run = PurchaseCalendarRun::query()
                    ->forScenario($scenario)
                    ->forYear($year)
                    ->forWarehouse(null)
                    ->latest('generated_at')
                    ->first();

                if ($run) {
                    $run->load('events');
                    $runs[$scenario->id] = $run;
                    $this->info("  {$scenario->label}: {$run->events->count()} events (generated {$run->generated_at->format('d M Y H:i')})");
                } else {
                    $missing[] = $scenario->label;
                }
            }

            if (count($missing) > 0) {
                $this->warn('No calendar run found for: '.implode(', ', $missing));
                $this->warn('Run forecast:purchase-calendar {scenario} first to generate calendar data.');
            }

            if (count($runs) === 0) {
                $this->error('No calendar runs available. Cannot generate PDF.');

                return self::FAILURE;
            }

            if ($months) {
                $monthNames = implode('-', [
                    date('M', mktime(0, 0, 0, $months[0], 1)),
                    date('M', mktime(0, 0, 0, end($months), 1)),
                ]);
                $this->info("Filtering to months: {$monthNames}");
            }

            $this->info('Rendering PDF per scenario...');
            $results = $pdfService->generate($scenarios, $runs, $year, $months);

            $this->newLine();
            foreach ($results as $name => $paths) {
                $this->info("  {$name}: {$paths['desktop']}");
            }

            return self::SUCCESS;
        } catch (\Throwable $e) {
            Log::error('GeneratePurchaseCalendarPdfCommand failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * Parse --months option (e.g. "4-6") into an array of month numbers.
     *
     * @return array<int, int>|null
     */
    private function parseMonthRange(): ?array
    {
        $raw = $this->option('months');

        if (! $raw) {
            return null;
        }

        if (str_contains($raw, '-')) {
            [$from, $to] = explode('-', $raw, 2);

            return range((int) $from, (int) $to);
        }

        return [(int) $raw];
    }
}
