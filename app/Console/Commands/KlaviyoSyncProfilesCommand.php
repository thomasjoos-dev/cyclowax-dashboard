<?php

namespace App\Console\Commands;

use App\Services\KlaviyoProfileSyncer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('klaviyo:sync-profiles')]
#[Description('Sync customer profiles from the Klaviyo API')]
class KlaviyoSyncProfilesCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(KlaviyoProfileSyncer $syncer): int
    {
        $this->components->info('Syncing Klaviyo profiles...');

        try {
            $count = $syncer->sync();

            $this->components->info("Synced {$count} profiles.");

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->components->error("Sync failed: {$e->getMessage()}");

            return self::FAILURE;
        }
    }
}
