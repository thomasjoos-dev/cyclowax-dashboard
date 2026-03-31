<?php

namespace App\Console\Commands;

use App\Models\SyncState;
use App\Services\Analysis\DashboardService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

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

    /** Steps that manage their own SyncState via cursor protocol */
    private const CURSOR_AWARE_COMMANDS = [
        'klaviyo:sync-profiles',
        'klaviyo:sync-campaigns',
        'klaviyo:sync-engagement',
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
            ['odoo:sync-boms', 'Odoo BOM sync'],
            ['odoo:sync-open-pos', 'Odoo open PO sync'],
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
        $needsMoreRuns = false;

        foreach ($steps as [$command, $label]) {
            // Skip steps that completed during this pipeline cycle
            if ($this->isRecentlyCompleted($command, $pipelineStart)) {
                $this->components->info("[{$label}] Already completed this cycle, skipping.");

                continue;
            }

            $this->runStep($command, $label, $isFull, $failures);

            // For cursor-aware commands: check if the step needs more runs
            if (in_array($command, self::CURSOR_AWARE_COMMANDS) && SyncState::isIncomplete($command)) {
                $needsMoreRuns = true;
                $this->components->warn("Pipeline paused: {$label} needs more runs. Scheduler will resume.");
                break;
            }

            if ($failures > 0) {
                break;
            }
        }

        if (! $needsMoreRuns) {
            app(DashboardService::class)->flushCache();
            $this->components->info('Dashboard cache flushed.');
        }

        $duration = round(microtime(true) - $pipelineStart, 1);

        $this->newLine();

        if ($failures > 0) {
            $this->components->warn("Pipeline stopped with {$failures} failure(s) in {$duration}s.");
            Log::warning('Sync pipeline stopped with failures', ['failures' => $failures, 'duration' => $duration]);

            return self::FAILURE;
        }

        if ($needsMoreRuns) {
            $this->components->info("Pipeline paused after {$duration}s. Will resume on next scheduler run.");
            Log::info('Sync pipeline paused for continuation', ['duration' => $duration]);

            return self::SUCCESS;
        }

        $this->components->info("Pipeline completed successfully in {$duration}s.");
        Log::info('Sync pipeline completed', ['duration' => $duration, 'full' => $isFull]);

        return self::SUCCESS;
    }

    /**
     * Run a pipeline step as an isolated PHP process to prevent memory accumulation.
     * Each sub-process gets its own memory space that is fully released by the OS on exit.
     */
    protected function runStep(string $command, string $label, bool $isFull, int &$failures): void
    {
        $this->newLine();
        $this->components->info("[{$label}]");

        $start = microtime(true);

        $artisanCommand = 'php artisan '.$command;
        if ($isFull && in_array($command, self::FULL_SYNC_COMMANDS)) {
            $artisanCommand .= ' --full';
        }

        $result = Process::timeout(900)->run($artisanCommand);
        $duration = round(microtime(true) - $start, 1);

        // Forward sub-process output so the pipeline log stays complete
        $output = trim($result->output());
        if ($output !== '') {
            $this->line($output);
        }

        if (! $result->successful()) {
            $this->components->error("{$label} failed (exit code {$result->exitCode()}) after {$duration}s.");

            $errorOutput = trim($result->errorOutput());
            if ($errorOutput !== '') {
                $this->line($errorOutput);
            }

            Log::error("Sync step failed: {$label}", [
                'command' => $command,
                'exit_code' => $result->exitCode(),
                'error' => $errorOutput,
            ]);
            $failures++;
        } else {
            // Cursor-aware commands manage their own SyncState — skip for those
            if (! in_array($command, self::CURSOR_AWARE_COMMANDS)) {
                SyncState::markCompleted($command, $duration, 0, $isFull);
            }

            $this->components->info("{$label} completed in {$duration}s.");
        }

        // Clean up parent process memory between steps
        DB::connection()->flushQueryLog();
        gc_collect_cycles();
    }

    /**
     * Check if a step completed recently (during this pipeline cycle).
     */
    protected function isRecentlyCompleted(string $step, float $pipelineStart): bool
    {
        $state = SyncState::where('step', $step)->first();

        if (! $state || $state->status !== 'completed' || ! $state->last_synced_at) {
            return false;
        }

        $pipelineStartTime = CarbonImmutable::createFromTimestamp($pipelineStart);

        return $state->last_synced_at->greaterThanOrEqualTo($pipelineStartTime);
    }
}
