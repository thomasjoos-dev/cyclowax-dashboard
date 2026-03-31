<?php

namespace App\Services\Sync;

use App\Models\KlaviyoProfile;
use App\Models\ShopifyCustomer;
use App\Services\Api\KlaviyoClient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class KlaviyoEngagementSyncer
{
    /** Event types we track, mapped to their database column and Klaviyo metric ID */
    protected const array TRACKED_EVENTS = [
        'Received Email' => 'emails_received',
        'Opened Email' => 'emails_opened',
        'Clicked Email' => 'emails_clicked',
        'Active on Site' => 'site_visits',
        'Viewed Product' => 'product_views',
        'Added to Cart' => 'cart_adds',
        'Checkout Started' => 'checkouts_started',
    ];

    protected bool $isIncremental = false;

    public function __construct(
        protected KlaviyoClient $klaviyo,
    ) {}

    /**
     * Sync engagement and intent event counts from Klaviyo using bulk metric fetching.
     * When $since is provided, only events after that timestamp are fetched and counts
     * are added to existing values (incremental). When null, the full 6-month window
     * is used and counts are replaced (full sync).
     */
    public function sync(?CarbonImmutable $since = null): int
    {
        $this->isIncremental = $since !== null;
        $eventsSince = $since?->subMinutes(5) ?? CarbonImmutable::now()->subMonths(6);

        Log::info('Klaviyo engagement sync starting', [
            'mode' => $this->isIncremental ? 'incremental' : 'full',
            'since' => $eventsSince->toDateTimeString(),
        ]);

        $metricIds = $this->resolveMetricIds();

        if (empty($metricIds)) {
            Log::warning('Could not resolve any metric IDs — skipping engagement sync');

            return 0;
        }

        // Build a set of follower klaviyo_ids to filter against
        $followerKlaviyoIds = $this->getFollowerKlaviyoIds();

        Log::info('Engagement sync targeting followers', ['count' => count($followerKlaviyoIds)]);

        // Aggregate counts per profile across all tracked metrics
        /** @var array<string, array<string, int>> $profileCounts [klaviyo_id => [column => count]] */
        $profileCounts = [];

        foreach ($metricIds as $metricName => $metricId) {
            $column = self::TRACKED_EVENTS[$metricName];
            $eventCount = 0;

            $this->paginateEvents($metricId, $eventsSince, function (array $event) use ($followerKlaviyoIds, $column, &$profileCounts, &$eventCount) {
                $profileId = $event['relationships']['profile']['data']['id'] ?? null;

                if (! $profileId || ! isset($followerKlaviyoIds[$profileId])) {
                    return;
                }

                $profileCounts[$profileId][$column] = ($profileCounts[$profileId][$column] ?? 0) + 1;
                $eventCount++;
            });

            Log::info("Engagement metric fetched: {$metricName}", ['events' => $eventCount]);
        }

        // Batch update profiles
        $updatedCount = $this->updateProfiles($profileCounts);

        Log::info('Klaviyo engagement sync completed', [
            'mode' => $this->isIncremental ? 'incremental' : 'full',
            'profiles_updated' => $updatedCount,
        ]);

        return $updatedCount;
    }

    /**
     * Resolve Klaviyo metric IDs for our tracked event names.
     *
     * @return array<string, string> [metric name => metric ID]
     */
    protected function resolveMetricIds(): array
    {
        $metricIds = [];

        try {
            foreach ($this->klaviyo->paginate('metrics') as $metric) {
                $name = $metric['attributes']['name'] ?? '';

                if (isset(self::TRACKED_EVENTS[$name]) && ! isset($metricIds[$name])) {
                    $metricIds[$name] = $metric['id'];
                }

                if (count($metricIds) === count(self::TRACKED_EVENTS)) {
                    break;
                }
            }
        } catch (\Throwable $e) {
            Log::error('Failed to resolve Klaviyo metric IDs', ['error' => $e->getMessage()]);
        }

        Log::info('Resolved metric IDs', ['found' => count($metricIds), 'expected' => count(self::TRACKED_EVENTS)]);

        return $metricIds;
    }

    /**
     * Get a lookup set of klaviyo_ids for follower profiles (not Shopify customers).
     *
     * @return array<string, true>
     */
    protected function getFollowerKlaviyoIds(): array
    {
        $customerEmails = ShopifyCustomer::query()
            ->whereNotNull('email')
            ->pluck('email')
            ->map(fn (string $email) => strtolower($email))
            ->flip()
            ->all();

        $followerIds = [];

        KlaviyoProfile::query()
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->where('is_suspect', false)
            ->whereNotNull('klaviyo_id')
            ->select(['id', 'klaviyo_id', 'email'])
            ->chunkById(1000, function ($profiles) use ($customerEmails, &$followerIds) {
                foreach ($profiles as $profile) {
                    if (! isset($customerEmails[strtolower($profile->email)])) {
                        $followerIds[$profile->klaviyo_id] = true;
                    }
                }
            });

        return $followerIds;
    }

    /**
     * Paginate through all events for a metric within a time window,
     * calling $callback for each event.
     */
    protected function paginateEvents(string $metricId, CarbonImmutable $since, callable $callback): void
    {
        $sinceStr = $since->toIso8601String();
        $endpoint = 'events';
        $query = [
            'filter' => "equals(metric_id,\"{$metricId}\"),greater-or-equal(datetime,{$sinceStr})",
            'fields[event]' => 'datetime',
            'page[size]' => 100,
            'sort' => '-datetime',
        ];

        try {
            do {
                $response = $this->klaviyo->get($endpoint, $query);

                foreach ($response['data'] ?? [] as $event) {
                    $callback($event);
                }

                $nextUrl = $response['links']['next'] ?? null;

                if ($nextUrl) {
                    $parsed = parse_url($nextUrl);
                    $endpoint = ltrim(str_replace('/api/', '', $parsed['path'] ?? ''), '/');
                    parse_str($parsed['query'] ?? '', $query);
                }
            } while ($nextUrl);
        } catch (\Throwable $e) {
            Log::warning("Failed to fetch events for metric {$metricId}", ['error' => $e->getMessage()]);
        }
    }

    /**
     * Batch update KlaviyoProfile records with aggregated event counts.
     * In full mode: replaces counts. In incremental mode: adds to existing counts.
     *
     * @param  array<string, array<string, int>>  $profileCounts
     */
    protected function updateProfiles(array $profileCounts): int
    {
        $columns = array_values(self::TRACKED_EVENTS);
        $updatedCount = 0;

        foreach (array_chunk($profileCounts, 500, preserve_keys: true) as $chunk) {
            $klaviyoIds = array_keys($chunk);

            $profiles = KlaviyoProfile::query()
                ->whereIn('klaviyo_id', $klaviyoIds)
                ->get(['id', 'klaviyo_id'])
                ->keyBy('klaviyo_id');

            DB::transaction(function () use ($chunk, $profiles, $columns, &$updatedCount) {
                foreach ($chunk as $klaviyoId => $counts) {
                    $profile = $profiles[$klaviyoId] ?? null;

                    if (! $profile) {
                        continue;
                    }

                    $updateData = ['engagement_synced_at' => now()];

                    foreach ($columns as $column) {
                        $increment = $counts[$column] ?? 0;

                        if ($this->isIncremental && $increment > 0) {
                            $updateData[$column] = DB::raw("{$column} + {$increment}");
                        } elseif (! $this->isIncremental) {
                            $updateData[$column] = $increment;
                        }
                    }

                    KlaviyoProfile::query()->where('id', $profile->id)->update($updateData);
                    $updatedCount++;
                }
            });
        }

        // In full mode: mark remaining follower profiles (with no events) as synced
        if (! $this->isIncremental) {
            $customerEmails = ShopifyCustomer::query()
                ->whereNotNull('email')
                ->pluck('email')
                ->map(fn (string $email) => strtolower($email));

            $zeroUpdated = KlaviyoProfile::query()
                ->whereNull('engagement_synced_at')
                ->whereNotNull('email')
                ->where('email', '!=', '')
                ->whereNotIn(DB::raw('LOWER(email)'), $customerEmails)
                ->update(['engagement_synced_at' => now()]);

            $updatedCount += $zeroUpdated;
        }

        return $updatedCount;
    }
}
