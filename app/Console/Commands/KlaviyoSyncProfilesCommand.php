<?php

namespace App\Console\Commands;

use App\Models\SyncState;
use App\Services\Sync\KlaviyoProfileSyncer;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('klaviyo:sync-profiles {--full : Bypass incremental sync and fetch all profiles}')]
#[Description('Sync customer profiles from the Klaviyo API')]
class KlaviyoSyncProfilesCommand extends Command
{
    private const string STEP = 'klaviyo:sync-profiles';

    /**
     * Execute the console command.
     */
    public function handle(KlaviyoProfileSyncer $syncer): int
    {
        if (SyncState::isStale(self::STEP)) {
            SyncState::updateOrCreate(['step' => self::STEP], ['status' => 'idle']);
        }

        $cursor = SyncState::getCursor(self::STEP);
        $isFull = (bool) $this->option('full');

        if ($cursor && ($cursor['was_full'] ?? false)) {
            $since = null;
        } elseif ($cursor && isset($cursor['since'])) {
            $since = CarbonImmutable::parse($cursor['since']);
        } else {
            $since = $isFull ? null : SyncState::lastSyncedAt(self::STEP);
        }

        $label = match (true) {
            $cursor !== null => 'resuming',
            $since !== null => "incremental since {$since->toDateTimeString()}",
            default => 'full',
        };

        $this->components->info("Syncing Klaviyo profiles ({$label})...");

        SyncState::markRunning(self::STEP);
        $start = microtime(true);

        try {
            $result = $syncer->sync($since, $cursor);
            $duration = round(microtime(true) - $start, 1);

            if ($result['complete']) {
                SyncState::markCompleted(self::STEP, $duration, $result['count'], $since === null);
                $this->components->info("Synced {$result['count']} profiles in {$duration}s.");
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
