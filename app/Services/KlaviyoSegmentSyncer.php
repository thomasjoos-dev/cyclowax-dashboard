<?php

namespace App\Services;

use App\Models\RiderProfile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class KlaviyoSegmentSyncer
{
    protected const int BATCH_SIZE = 10_000;

    protected int $syncedCount = 0;

    protected int $batchCount = 0;

    public function __construct(
        protected KlaviyoClient $client,
    ) {}

    /**
     * Sync segment data to Klaviyo for profiles that have changed since last sync.
     */
    public function syncIncremental(): int
    {
        return $this->sync(onlyChanged: true);
    }

    /**
     * Sync all profiles with a segment to Klaviyo (full resync).
     */
    public function syncFull(): int
    {
        return $this->sync(onlyChanged: false);
    }

    /**
     * Core sync logic: collect profiles, batch them, push to Klaviyo.
     */
    protected function sync(bool $onlyChanged): int
    {
        $this->syncedCount = 0;
        $this->batchCount = 0;

        $mode = $onlyChanged ? 'incremental' : 'full';
        Log::info("Klaviyo segment sync starting ({$mode})");

        $query = RiderProfile::query()
            ->whereNotNull('segment')
            ->whereNotNull('klaviyo_profile_id');

        if ($onlyChanged) {
            $query->where(function ($q) {
                $q->whereNull('klaviyo_synced_at')
                    ->orWhereColumn('updated_at', '>', 'klaviyo_synced_at');
            });
        }

        $now = Carbon::now();
        $profileIds = [];

        $query->select(['id', 'email', 'lifecycle_stage', 'segment'])
            ->chunkById(self::BATCH_SIZE, function ($profiles) use (&$profileIds) {
                $payload = $this->buildBatchPayload($profiles);
                $this->pushBatch($payload);

                $profileIds = array_merge($profileIds, $profiles->pluck('id')->toArray());
                $this->syncedCount += $profiles->count();
            });

        if (! empty($profileIds)) {
            $this->markSynced($profileIds, $now);
        }

        Log::info("Klaviyo segment sync completed ({$mode})", [
            'profiles' => $this->syncedCount,
            'batches' => $this->batchCount,
        ]);

        return $this->syncedCount;
    }

    /**
     * Build the Klaviyo Bulk Import payload for a batch of profiles.
     *
     * @param  Collection<int, RiderProfile>  $profiles
     * @return array<string, mixed>
     */
    protected function buildBatchPayload($profiles): array
    {
        $profileData = [];

        foreach ($profiles as $profile) {
            $profileData[] = [
                'type' => 'profile',
                'attributes' => [
                    'email' => $profile->email,
                    'properties' => [
                        'cyclowax_lifecycle' => $profile->lifecycle_stage->value,
                        'cyclowax_segment' => $profile->segment,
                    ],
                ],
            ];
        }

        return [
            'data' => [
                'type' => 'profile-bulk-import-job',
                'attributes' => [
                    'profiles' => [
                        'data' => $profileData,
                    ],
                ],
            ],
        ];
    }

    /**
     * Push a batch to the Klaviyo Bulk Import API.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function pushBatch(array $payload): void
    {
        $count = count($payload['data']['attributes']['profiles']['data']);

        Log::info('Pushing batch to Klaviyo', ['profiles' => $count]);

        $this->client->post('profile-bulk-import-jobs', $payload);
        $this->batchCount++;
    }

    /**
     * Mark profiles as synced after successful push.
     *
     * @param  array<int>  $profileIds
     */
    protected function markSynced(array $profileIds, Carbon $now): void
    {
        RiderProfile::whereIn('id', $profileIds)
            ->update(['klaviyo_synced_at' => $now]);
    }

    public function syncedCount(): int
    {
        return $this->syncedCount;
    }

    public function batchCount(): int
    {
        return $this->batchCount;
    }
}
