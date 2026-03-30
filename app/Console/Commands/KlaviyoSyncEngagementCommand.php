<?php

namespace App\Console\Commands;

use App\Models\SyncState;
use App\Services\KlaviyoEngagementSyncer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('klaviyo:sync-engagement {--full : Bypass incremental sync and fetch full 6-month window}')]
#[Description('Sync email engagement event counts from the Klaviyo Events API')]
class KlaviyoSyncEngagementCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(KlaviyoEngagementSyncer $syncer): int
    {
        $since = $this->option('full') ? null : SyncState::lastSyncedAt('klaviyo:sync-engagement');

        $this->components->info('Syncing Klaviyo engagement data'.($since ? " (incremental since {$since->toDateTimeString()})" : ' (full)').'...');

        try {
            $count = $syncer->sync($since);

            $this->components->info("Synced engagement for {$count} profiles.");

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->components->error("Sync failed: {$e->getMessage()}");

            return self::FAILURE;
        }
    }
}
