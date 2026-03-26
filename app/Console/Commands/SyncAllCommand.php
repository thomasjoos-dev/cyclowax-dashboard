<?php

namespace App\Console\Commands;

use App\Services\DashboardService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

#[Signature('sync:all')]
#[Description('Run the full daily sync pipeline: Shopify orders → Odoo products → compute margins')]
class SyncAllCommand extends Command
{
    public function handle(): int
    {
        $pipelineStart = microtime(true);

        $this->components->info('Starting sync pipeline...');

        $steps = [
            ['shopify:sync-orders', 'Shopify order sync'],
            ['odoo:sync-products', 'Odoo product sync'],
            ['odoo:sync-shipping-costs', 'Odoo shipping cost sync'],
            ['orders:compute-margins', 'Margin computation'],
            ['customers:calculate-rfm', 'RFM scoring'],
        ];

        $failures = 0;

        foreach ($steps as [$command, $label]) {
            $this->runStep($command, $label, $failures);
        }

        app(DashboardService::class)->flushCache();
        $this->components->info('Dashboard cache flushed.');

        $duration = round(microtime(true) - $pipelineStart, 1);

        $this->newLine();

        if ($failures > 0) {
            $this->components->warn("Pipeline completed with {$failures} failure(s) in {$duration}s.");
            Log::warning('Sync pipeline completed with failures', ['failures' => $failures, 'duration' => $duration]);

            return self::FAILURE;
        }

        $this->components->info("Pipeline completed successfully in {$duration}s.");
        Log::info('Sync pipeline completed', ['duration' => $duration]);

        return self::SUCCESS;
    }

    protected function runStep(string $command, string $label, int &$failures): void
    {
        $this->newLine();
        $this->components->info("[{$label}]");

        $start = microtime(true);

        try {
            $exitCode = $this->call($command);
            $duration = round(microtime(true) - $start, 1);

            if ($exitCode !== self::SUCCESS) {
                $this->components->error("{$label} failed (exit code {$exitCode}) after {$duration}s.");
                Log::error("Sync step failed: {$label}", ['command' => $command, 'exit_code' => $exitCode]);
                $failures++;
            } else {
                $this->components->info("{$label} completed in {$duration}s.");
            }
        } catch (\Throwable $e) {
            $duration = round(microtime(true) - $start, 1);
            $this->components->error("{$label} threw exception after {$duration}s: {$e->getMessage()}");
            Log::error("Sync step exception: {$label}", ['command' => $command, 'error' => $e->getMessage()]);
            $failures++;
        }
    }
}
