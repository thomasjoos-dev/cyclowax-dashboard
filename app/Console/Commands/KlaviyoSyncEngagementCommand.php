<?php

namespace App\Console\Commands;

use App\Services\KlaviyoEngagementSyncer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('klaviyo:sync-engagement')]
#[Description('Sync email engagement event counts from the Klaviyo Events API')]
class KlaviyoSyncEngagementCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(KlaviyoEngagementSyncer $syncer): int
    {
        $this->components->info('Syncing Klaviyo engagement data...');

        try {
            $count = $syncer->sync();

            $this->components->info("Synced engagement for {$count} profiles.");

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->components->error("Sync failed: {$e->getMessage()}");

            return self::FAILURE;
        }
    }
}
