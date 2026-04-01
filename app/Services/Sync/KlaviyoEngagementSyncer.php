<?php

namespace App\Services\Sync;

use App\Models\KlaviyoProfile;
use App\Services\Api\KlaviyoClient;
use App\Services\Sync\Concerns\HasTimeBudget;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class KlaviyoEngagementSyncer
{
    use HasTimeBudget;

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
    ) {
        $this->timeBudgetSeconds = config('klaviyo.time_budget.engagement', 210);
    }

    /**
     * Sync engagement event counts from Klaviyo.
     * Supports cursor-based resumption for time-budgeted execution.
     *
     * @param  array<string, mixed>|null  $cursor  Resume state from a previous incomplete run.
     * @return array{count: int, cursor: ?array<string, mixed>, complete: bool}
     */
    public function sync(?CarbonImmutable $since = null, ?array $cursor = null): array
    {
        $this->startTimeBudget();
        DB::connection()->disableQueryLog();

        // When resuming from cursor, always use incremental semantics to safely add counts
        $this->isIncremental = $since !== null || $cursor !== null;
        $eventsSince = $since?->subMinutes(5) ?? CarbonImmutable::now()->subMonths(6);

        Log::info('Klaviyo engagement sync starting', [
            'mode' => $this->isIncremental ? 'incremental' : 'full',
            'since' => $eventsSince->toDateTimeString(),
            'resuming' => $cursor !== null,
        ]);

        $metricIds = $cursor['metric_ids'] ?? $this->resolveMetricIds();

        if (empty($metricIds)) {
            Log::warning('Could not resolve any metric IDs — skipping engagement sync');

            return ['count' => 0, 'cursor' => null, 'complete' => true];
        }

        $followerKlaviyoIds = $this->getFollowerKlaviyoIds();

        Log::info('Engagement sync targeting followers', ['count' => count($followerKlaviyoIds)]);

        $completedMetrics = $cursor['completed_metrics'] ?? [];
        $totalUpdated = 0;

        foreach ($metricIds as $metricName => $metricId) {
            if (in_array($metricName, $completedMetrics)) {
                continue;
            }

            /** @var array<string, array<string, int>> $profileCounts [klaviyo_id => [column => count]] */
            $profileCounts = [];
            $column = self::TRACKED_EVENTS[$metricName];
            $eventCount = 0;
            $startUrl = ($cursor['current_metric'] ?? null) === $metricName
                ? ($cursor['next_url'] ?? null)
                : null;

            $nextUrl = $this->paginateEventsWithBudget(
                $metricId, $eventsSince, $startUrl,
                function (array $event) use ($followerKlaviyoIds, $column, &$profileCounts, &$eventCount) {
                    $profileId = $event['relationships']['profile']['data']['id'] ?? null;

                    if (! $profileId || ! isset($followerKlaviyoIds[$profileId])) {
                        return;
                    }

                    $profileCounts[$profileId][$column] = ($profileCounts[$profileId][$column] ?? 0) + 1;
                    $eventCount++;
                },
            );

            Log::info("Engagement metric fetched: {$metricName}", ['events' => $eventCount]);

            // Flush counts to DB after each metric to keep memory bounded
            $totalUpdated += $this->updateProfiles($profileCounts);
            unset($profileCounts);
            gc_collect_cycles();

            if ($nextUrl !== null) {
                Log::info('Klaviyo engagement sync paused (time budget)', [
                    'completed_metrics' => $completedMetrics,
                    'current_metric' => $metricName,
                ]);

                return [
                    'count' => $totalUpdated,
                    'cursor' => [
                        'metric_ids' => $metricIds,
                        'completed_metrics' => $completedMetrics,
                        'current_metric' => $metricName,
                        'next_url' => $nextUrl,
                        'since' => $eventsSince->toIso8601String(),
                        'was_full' => $since === null && $cursor === null,
                    ],
                    'complete' => false,
                ];
            }

            $completedMetrics[] = $metricName;
        }

        $updatedCount = $totalUpdated;

        // In full mode (no cursor resume): mark remaining follower profiles as synced
        if (! $this->isIncremental) {
            $updatedCount += $this->markUnsyncedFollowers();
        }

        Log::info('Klaviyo engagement sync completed', [
            'mode' => $this->isIncremental ? 'incremental' : 'full',
            'profiles_updated' => $updatedCount,
        ]);

        return ['count' => $updatedCount, 'cursor' => null, 'complete' => true];
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
     * Uses a DB subquery instead of loading all customer emails into PHP memory.
     *
     * @return array<string, true>
     */
    protected function getFollowerKlaviyoIds(): array
    {
        $followerIds = [];

        KlaviyoProfile::query()
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->where('is_suspect', false)
            ->whereNotNull('klaviyo_id')
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('shopify_customers')
                    ->whereNotNull('shopify_customers.email')
                    ->whereColumn(DB::raw('LOWER(shopify_customers.email)'), DB::raw('LOWER(klaviyo_profiles.email)'));
            })
            ->select(['id', 'klaviyo_id'])
            ->chunkById(1000, function ($profiles) use (&$followerIds) {
                foreach ($profiles as $profile) {
                    $followerIds[$profile->klaviyo_id] = true;
                }
            });

        return $followerIds;
    }

    /**
     * Paginate through events for a metric, calling $callback for each event.
     * Stops when the time budget runs out and returns the next URL as cursor.
     *
     * @return string|null The next URL if paused, null if completed.
     */
    protected function paginateEventsWithBudget(
        string $metricId,
        CarbonImmutable $since,
        ?string $startUrl,
        callable $callback,
    ): ?string {
        $sinceStr = $since->toIso8601String();

        $query = [
            'filter' => "equals(metric_id,\"{$metricId}\"),greater-or-equal(datetime,{$sinceStr})",
            'fields[event]' => 'datetime',
            'page[size]' => 100,
            'sort' => '-datetime',
        ];

        try {
            foreach ($this->klaviyo->paginatePages('events', $query, $startUrl) as $page) {
                foreach ($page['items'] as $event) {
                    $callback($event);
                }

                if (! $this->hasTimeRemaining() && $page['next_url']) {
                    return $page['next_url'];
                }
            }
        } catch (\Throwable $e) {
            Log::warning("Failed to fetch events for metric {$metricId}", ['error' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * Batch update KlaviyoProfile records with aggregated event counts.
     * In full mode: replaces counts. In incremental mode: adds to existing counts.
     *
     * @param  array<string, array<string, int>>  $profileCounts
     */
    /**
     * Batch update KlaviyoProfile records with aggregated event counts.
     * Only touches columns present in $profileCounts so per-metric flushes
     * don't overwrite previously written columns.
     *
     * @param  array<string, array<string, int>>  $profileCounts
     */
    protected function updateProfiles(array $profileCounts): int
    {
        if (empty($profileCounts)) {
            return 0;
        }

        $updatedCount = 0;

        foreach (array_chunk($profileCounts, 500, preserve_keys: true) as $chunk) {
            $klaviyoIds = array_keys($chunk);

            $profiles = KlaviyoProfile::query()
                ->whereIn('klaviyo_id', $klaviyoIds)
                ->get(['id', 'klaviyo_id'])
                ->keyBy('klaviyo_id');

            DB::transaction(function () use ($chunk, $profiles, &$updatedCount) {
                foreach ($chunk as $klaviyoId => $counts) {
                    $profile = $profiles[$klaviyoId] ?? null;

                    if (! $profile) {
                        continue;
                    }

                    $updateData = ['engagement_synced_at' => now()];

                    foreach ($counts as $column => $value) {
                        if ($this->isIncremental && $value > 0) {
                            $updateData[$column] = DB::raw("{$column} + {$value}");
                        } elseif (! $this->isIncremental) {
                            $updateData[$column] = $value;
                        }
                    }

                    KlaviyoProfile::query()->where('id', $profile->id)->update($updateData);
                    $updatedCount++;
                }
            });
        }

        return $updatedCount;
    }

    /**
     * In full mode: mark remaining follower profiles (with no events) as synced.
     * Uses a DB subquery to avoid loading all customer emails into PHP memory.
     */
    protected function markUnsyncedFollowers(): int
    {
        return KlaviyoProfile::query()
            ->whereNull('engagement_synced_at')
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('shopify_customers')
                    ->whereNotNull('shopify_customers.email')
                    ->whereColumn(DB::raw('LOWER(shopify_customers.email)'), DB::raw('LOWER(klaviyo_profiles.email)'));
            })
            ->update(['engagement_synced_at' => now()]);
    }
}
