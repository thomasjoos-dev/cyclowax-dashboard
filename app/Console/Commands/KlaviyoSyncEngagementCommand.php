<?php

namespace App\Console\Commands;

use App\Models\SyncState;
use App\Services\Sync\KlaviyoEngagementSyncer;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('klaviyo:sync-engagement {--full : Bypass incremental sync and fetch full 6-month window}')]
#[Description('Sync email engagement event counts from the Klaviyo Events API')]
class KlaviyoSyncEngagementCommand extends Command
{
    private const string STEP = 'klaviyo:sync-engagement';

    /**
     * Execute the console command.
     */
    public function handle(KlaviyoEngagementSyncer $syncer): int
    {
        if (SyncState::isStale(self::STEP)) {
            SyncState::updateOrCreate(['step' => self::STEP], ['status' => 'idle']);
        }

        $cursor = SyncState::getCursor(self::STEP);
        $isFull = (bool) $this->option('full');

        if ($cursor) {
            $since = isset($cursor['since']) ? CarbonImmutable::parse($cursor['since']) : null;
        } else {
            $since = $isFull ? null : SyncState::lastSyncedAt(self::STEP);
        }

        $label = match (true) {
            $cursor !== null => 'resuming',
            $since !== null => "incremental since {$since->toDateTimeString()}",
            default => 'full',
        };

        $this->components->info("Syncing Klaviyo engagement data ({$label})...");

        SyncState::markRunning(self::STEP);
        $start = microtime(true);

        try {
            $result = $syncer->sync($since, $cursor);
            $duration = round(microtime(true) - $start, 1);

            if ($result['complete']) {
                SyncState::markCompleted(self::STEP, $duration, $result['count'], $since === null && $cursor === null);
                $this->components->info("Synced engagement for {$result['count']} profiles in {$duration}s.");
            } else {
                SyncState::saveCursor(self::STEP, $result['cursor'], $result['count']);
                $this->components->info("Paused after {$result['count']} profiles ({$duration}s). Will resume on next run.");
            }

            return self::SUCCESS;
        } catch (Throwable $e) {
            SyncState::updateOrCreate(['step' => self::STEP], ['status' => 'idle']);
            $this->components->error("Sync failed: {$e->getMessage()}");

            return self::FAILURE;
        }
    }
}
