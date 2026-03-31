<?php

namespace App\Console\Commands;

use App\Models\SyncState;
use App\Services\Analysis\DashboardService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

#[Signature('sync:all {--full : Force full sync for all steps, bypassing incremental logic}')]
#[Description('Run the full daily sync pipeline: Shopify → Odoo → Klaviyo → margins → RFM → profiles')]
class SyncAllCommand extends Command
{
    private const FULL_SYNC_COMMANDS = [
        'klaviyo:sync-profiles',
        'klaviyo:sync-campaigns',
        'klaviyo:sync-engagement',
        'orders:compute-margins',
    ];

    public function handle(): int
    {
        $pipelineStart = microtime(true);
        $isFull = (bool) $this->option('full');

        $this->components->info('Starting sync pipeline'.($isFull ? ' (full sync)' : ' (incremental)').'...');

        $steps = [
            ['shopify:sync-orders', 'Shopify order sync'],
            ['odoo:sync-products', 'Odoo product sync'],
            ['odoo:sync-shipping-costs', 'Odoo shipping cost sync'],
            ['klaviyo:sync-profiles', 'Klaviyo profile sync'],
            ['klaviyo:sync-campaigns', 'Klaviyo campaign sync'],
            ['orders:compute-margins', 'Margin computation'],
            ['customers:calculate-rfm', 'RFM scoring'],
            ['klaviyo:sync-engagement', 'Klaviyo engagement sync'],
            ['profiles:flag-suspects', 'Bot/spam detection'],
            ['profiles:link', 'Rider profile linking'],
            ['profiles:score-followers', 'Follower engagement scoring'],
            ['klaviyo:sync-segments', 'Klaviyo segment sync'],
            ['shopify:sync-segments', 'Shopify segment tag sync'],
        ];

        $failures = 0;

        foreach ($steps as [$command, $label]) {
            $this->runStep($command, $label, $isFull, $failures);
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
        Log::info('Sync pipeline completed', ['duration' => $duration, 'full' => $isFull]);

        return self::SUCCESS;
    }

    protected function runStep(string $command, string $label, bool $isFull, int &$failures): void
    {
        $this->newLine();
        $this->components->info("[{$label}]");

        $start = microtime(true);

        try {
            $arguments = [];
            if ($isFull && in_array($command, self::FULL_SYNC_COMMANDS)) {
                $arguments['--full'] = true;
            }

            $exitCode = $this->call($command, $arguments);
            $duration = round(microtime(true) - $start, 1);

            if ($exitCode !== self::SUCCESS) {
                $this->components->error("{$label} failed (exit code {$exitCode}) after {$duration}s.");
                Log::error("Sync step failed: {$label}", ['command' => $command, 'exit_code' => $exitCode]);
                $failures++;
            } else {
                SyncState::updateOrCreate(
                    ['step' => $command],
                    [
                        'last_synced_at' => now(),
                        'duration_seconds' => $duration,
                        'was_full_sync' => $isFull,
                    ],
                );

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
