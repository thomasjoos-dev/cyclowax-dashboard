<?php

namespace App\Console\Commands;

use App\Models\SyncState;
use App\Services\Sync\KlaviyoProfileSyncer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('klaviyo:sync-profiles {--full : Bypass incremental sync and fetch all profiles}')]
#[Description('Sync customer profiles from the Klaviyo API')]
class KlaviyoSyncProfilesCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(KlaviyoProfileSyncer $syncer): int
    {
        $since = $this->option('full') ? null : SyncState::lastSyncedAt('klaviyo:sync-profiles');

        $this->components->info('Syncing Klaviyo profiles'.($since ? " (incremental since {$since->toDateTimeString()})" : ' (full)').'...');

        try {
            $count = $syncer->sync($since);

            $this->components->info("Synced {$count} profiles.");

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->components->error("Sync failed: {$e->getMessage()}");

            return self::FAILURE;
        }
    }
}
