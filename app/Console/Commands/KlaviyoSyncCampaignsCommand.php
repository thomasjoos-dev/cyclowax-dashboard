<?php

namespace App\Console\Commands;

use App\Services\KlaviyoCampaignSyncer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('klaviyo:sync-campaigns')]
#[Description('Sync email campaigns and metrics from the Klaviyo API')]
class KlaviyoSyncCampaignsCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(KlaviyoCampaignSyncer $syncer): int
    {
        $this->components->info('Syncing Klaviyo campaigns...');

        try {
            $count = $syncer->sync();

            $this->components->info("Synced {$count} campaigns.");

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->components->error("Sync failed: {$e->getMessage()}");

            return self::FAILURE;
        }
    }
}
