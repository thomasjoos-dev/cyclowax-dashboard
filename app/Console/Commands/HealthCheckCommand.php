<?php

namespace App\Console\Commands;

use App\Models\SyncState;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

#[Signature('health:check')]
#[Description('Check application health: database, API credentials, sync freshness')]
class HealthCheckCommand extends Command
{
    public function handle(): int
    {
        try {
            $failures = 0;

            // Database connectivity
            $this->components->info('Checking database...');
            try {
                $driver = DB::connection()->getDriverName();
                $database = DB::connection()->getDatabaseName();
                $this->components->info("Database OK: {$driver} ({$database})");
            } catch (\Throwable $e) {
                $this->components->error("Database FAILED: {$e->getMessage()}");
                $failures++;
            }

            // API credentials
            $this->components->info('Checking API credentials...');
            $credentials = [
                'Shopify' => config('shopify.store') && config('shopify.access_token'),
                'Odoo' => config('odoo.url') && config('odoo.api_key'),
                'Klaviyo' => (bool) config('klaviyo.api_key'),
            ];

            foreach ($credentials as $service => $configured) {
                if ($configured) {
                    $this->components->info("{$service}: configured");
                } else {
                    $this->components->error("{$service}: NOT configured");
                    $failures++;
                }
            }

            // Sync freshness
            $this->components->info('Checking sync freshness...');
            $states = SyncState::all();

            if ($states->isEmpty()) {
                $this->components->warn('No sync data — run sync:all first.');
            } else {
                $staleThreshold = now()->subHours(25);
                $staleSteps = $states->filter(fn (SyncState $s) => ! $s->last_synced_at || $s->last_synced_at->lt($staleThreshold));

                if ($staleSteps->isEmpty()) {
                    $this->components->info('All sync steps are fresh (<25h).');
                } else {
                    foreach ($staleSteps as $step) {
                        $age = $step->last_synced_at?->diffForHumans() ?? 'never synced';
                        $this->components->warn("Stale: {$step->step} ({$age})");
                    }
                    $failures++;
                }
            }

            $this->newLine();
            if ($failures > 0) {
                $this->components->error("Health check completed with {$failures} issue(s).");

                return self::FAILURE;
            }

            $this->components->info('Health check passed.');

            return self::SUCCESS;
        } catch (\Throwable $e) {
            Log::error('HealthCheckCommand failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
