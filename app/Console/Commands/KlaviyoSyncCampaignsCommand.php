<?php

namespace App\Console\Commands;

use App\Models\SyncState;
use App\Services\KlaviyoCampaignSyncer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('klaviyo:sync-campaigns {--full : Bypass incremental sync and fetch all campaigns}')]
#[Description('Sync email campaigns and metrics from the Klaviyo API')]
class KlaviyoSyncCampaignsCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(KlaviyoCampaignSyncer $syncer): int
    {
        $since = $this->option('full') ? null : SyncState::lastSyncedAt('klaviyo:sync-campaigns');

        $this->components->info('Syncing Klaviyo campaigns'.($since ? " (incremental since {$since->toDateTimeString()})" : ' (full)').'...');

        try {
            $count = $syncer->sync($since);

            $this->components->info("Synced {$count} campaigns.");

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->components->error("Sync failed: {$e->getMessage()}");

            return self::FAILURE;
        }
    }
}
