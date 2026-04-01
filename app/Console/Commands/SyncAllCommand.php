<?php

namespace App\Console\Commands;

use App\Models\SyncState;
use App\Services\Analysis\DashboardService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Process\Pool;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

#[Signature('sync:all {--full : Force full sync for all steps, bypassing incremental logic} {--skip-enrichment : Skip Klaviyo campaign metrics enrichment}')]
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

    /** Steps that run in parallel as a single pipeline step */
    private const PARALLEL_GROUPS = [
        'odoo:parallel' => [
            ['odoo:sync-products', 'Odoo product sync'],
            ['odoo:sync-shipping-costs', 'Odoo shipping cost sync'],
            ['odoo:sync-boms', 'Odoo BOM sync'],
            ['odoo:sync-open-pos', 'Odoo open PO sync'],
        ],
    ];

    public function handle(): int
    {
        if (! $this->validateCredentials()) {
            return self::FAILURE;
        }

        $this->resetStaleSyncStates();

        $pipelineStart = microtime(true);
        $isFull = (bool) $this->option('full');

        $this->components->info('Starting sync pipeline'.($isFull ? ' (full sync)' : ' (incremental)').'...');

        $steps = [
            ['shopify:sync-orders', 'Shopify order sync'],
            ['odoo:parallel', 'Odoo parallel sync'],
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
            // Parallel groups: run simultaneously when resources allow, otherwise sequential
            if (isset(self::PARALLEL_GROUPS[$command])) {
                if ($this->canRunParallel()) {
                    $this->runParallelSteps(self::PARALLEL_GROUPS[$command], $label, $isFull, $failures);
                } else {
                    $this->components->info("[{$label}] Running sequentially (limited resources).");
                    foreach (self::PARALLEL_GROUPS[$command] as [$subCommand, $subLabel]) {
                        $this->runStep($subCommand, $subLabel, $isFull, $failures);
                        if ($failures > 0) {
                            break;
                        }
                    }
                }
                if ($failures > 0) {
                    break;
                }

                continue;
            }

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
        if ($this->option('skip-enrichment') && $command === 'klaviyo:sync-campaigns') {
            $artisanCommand .= ' --skip-enrichment';
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
     * Check if the environment has enough resources to run parallel processes.
     * Requires at least 2GB of system memory to safely spawn multiple PHP subprocesses.
     */
    protected function canRunParallel(): bool
    {
        if (PHP_OS_FAMILY !== 'Linux' && PHP_OS_FAMILY !== 'Darwin') {
            return false;
        }

        // On Linux (Cloud): read from /proc/meminfo
        if (PHP_OS_FAMILY === 'Linux' && is_readable('/proc/meminfo')) {
            $meminfo = file_get_contents('/proc/meminfo');
            if (preg_match('/MemTotal:\s+(\d+)\s+kB/', $meminfo, $matches)) {
                $totalMb = (int) $matches[1] / 1024;

                return $totalMb >= 2048;
            }
        }

        // On macOS (local dev): always allow parallel
        return PHP_OS_FAMILY === 'Darwin';
    }

    /**
     * Run multiple pipeline steps in parallel using a process pool.
     *
     * @param  array<int, array{0: string, 1: string}>  $commands
     */
    protected function runParallelSteps(array $commands, string $label, bool $isFull, int &$failures): void
    {
        $this->newLine();
        $this->components->info("[{$label}]");

        $start = microtime(true);

        $pool = Process::pool(function (Pool $pool) use ($commands, $isFull) {
            foreach ($commands as [$command, $sublabel]) {
                $artisanCommand = 'php artisan '.$command;
                if ($isFull && in_array($command, self::FULL_SYNC_COMMANDS)) {
                    $artisanCommand .= ' --full';
                }
                $pool->as($command)->timeout(900)->command($artisanCommand);
            }
        })->start()->wait();

        $duration = round(microtime(true) - $start, 1);

        foreach ($commands as [$command, $sublabel]) {
            $result = $pool[$command];

            $output = trim($result->output());
            if ($output !== '') {
                $this->line($output);
            }

            if (! $result->successful()) {
                $this->components->error("{$sublabel} failed (exit code {$result->exitCode()}).");

                $errorOutput = trim($result->errorOutput());
                if ($errorOutput !== '') {
                    $this->line($errorOutput);
                }

                Log::error("Sync step failed: {$sublabel}", [
                    'command' => $command,
                    'exit_code' => $result->exitCode(),
                    'error' => $errorOutput,
                ]);
                $failures++;
            } else {
                SyncState::markCompleted($command, $duration, 0, $isFull);
                $this->components->info("{$sublabel} completed.");
            }
        }

        $this->components->info("{$label} finished in {$duration}s.");

        DB::connection()->flushQueryLog();
        gc_collect_cycles();
    }

    /**
     * Verify that all required external service credentials are configured.
     */
    protected function validateCredentials(): bool
    {
        $missing = [];

        if (! config('shopify.store') || ! config('shopify.access_token')) {
            $missing[] = 'Shopify (SHOPIFY_STORE, SHOPIFY_ACCESS_TOKEN)';
        }

        if (! config('odoo.url') || ! config('odoo.api_key')) {
            $missing[] = 'Odoo (ODOO_URL, ODOO_API_KEY)';
        }

        if (! config('klaviyo.api_key')) {
            $missing[] = 'Klaviyo (KLAVIYO_API_KEY)';
        }

        if (! empty($missing)) {
            foreach ($missing as $service) {
                $this->components->error("Missing credentials: {$service}");
            }

            Log::error('Sync pipeline aborted: missing credentials', ['missing' => $missing]);

            return false;
        }

        return true;
    }

    /**
     * Reset sync states that are stuck in "running" beyond the stale threshold.
     */
    protected function resetStaleSyncStates(): void
    {
        foreach (self::CURSOR_AWARE_COMMANDS as $command) {
            if (SyncState::isStale($command)) {
                SyncState::updateOrCreate(['step' => $command], ['status' => 'idle', 'cursor' => null]);
                Log::warning("Reset stale sync state for {$command}");
                $this->components->warn("Reset stale state: {$command}");
            }
        }
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
