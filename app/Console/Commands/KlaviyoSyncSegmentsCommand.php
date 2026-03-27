<?php

namespace App\Console\Commands;

use App\Services\KlaviyoSegmentSyncer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('klaviyo:sync-segments {--full : Sync all profiles, not just changed ones}')]
#[Description('Sync rider segment data to Klaviyo as custom properties')]
class KlaviyoSyncSegmentsCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(KlaviyoSegmentSyncer $syncer): int
    {
        $full = $this->option('full');
        $mode = $full ? 'full' : 'incremental';

        $this->components->info("Syncing segments to Klaviyo ({$mode})...");

        try {
            $count = $full ? $syncer->syncFull() : $syncer->syncIncremental();

            $this->components->info("Synced {$count} profiles to Klaviyo in {$syncer->batchCount()} batch(es).");

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->components->error("Segment sync failed: {$e->getMessage()}");

            return self::FAILURE;
        }
    }
}
