<?php

namespace App\Console\Commands;

use App\Models\SyncState;
use App\Services\Sync\KlaviyoCampaignSyncer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('klaviyo:sync-campaigns {--full : Bypass incremental sync and fetch all campaigns} {--skip-enrichment : Skip metrics enrichment for sent campaigns}')]
#[Description('Sync email campaigns and metrics from the Klaviyo API')]
class KlaviyoSyncCampaignsCommand extends Command
{
    private const string STEP = 'klaviyo:sync-campaigns';

    /**
     * Execute the console command.
     */
    public function handle(KlaviyoCampaignSyncer $syncer): int
    {
        if (SyncState::isStale(self::STEP)) {
            SyncState::updateOrCreate(['step' => self::STEP], ['status' => 'idle']);
        }

        $since = $this->option('full') ? null : SyncState::lastSyncedAt(self::STEP);

        $this->components->info('Syncing Klaviyo campaigns'.($since ? " (incremental since {$since->toDateTimeString()})" : ' (full)').'...');

        SyncState::markRunning(self::STEP);
        $start = microtime(true);

        try {
            $result = $syncer->sync($since, skipEnrichment: (bool) $this->option('skip-enrichment'));
            $duration = round(microtime(true) - $start, 1);

            if ($result['complete']) {
                SyncState::markCompleted(self::STEP, $duration, $result['count'], $since === null);
                $this->components->info("Synced {$result['count']} campaigns in {$duration}s.");
            } else {
                SyncState::saveCursor(self::STEP, ['enrichment_pending' => true], $result['count']);
                $this->components->info("Synced {$result['count']} campaigns, enrichment paused ({$duration}s). Will resume on next run.");
            }

            return self::SUCCESS;
        } catch (Throwable $e) {
            SyncState::updateOrCreate(['step' => self::STEP], ['status' => 'idle']);
            $this->components->error("Sync failed: {$e->getMessage()}");

            return self::FAILURE;
        }
    }
}
