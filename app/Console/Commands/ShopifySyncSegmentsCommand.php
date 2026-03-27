<?php

namespace App\Console\Commands;

use App\Services\ShopifySegmentSyncer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('shopify:sync-segments {--full : Sync all customers, not just changed ones}')]
#[Description('Sync rider segment tags (cw:*) to Shopify customers')]
class ShopifySyncSegmentsCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(ShopifySegmentSyncer $syncer): int
    {
        $full = $this->option('full');
        $mode = $full ? 'full' : 'incremental';

        $this->components->info("Syncing segment tags to Shopify ({$mode})...");

        try {
            $count = $full ? $syncer->syncFull() : $syncer->syncIncremental();

            $this->components->info("Synced {$count} customer tags to Shopify.");

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->components->error("Shopify segment sync failed: {$e->getMessage()}");

            return self::FAILURE;
        }
    }
}
