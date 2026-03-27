<?php

namespace App\Services;

use App\Enums\CustomerSegment;
use App\Enums\FollowerSegment;
use App\Enums\LifecycleStage;
use App\Models\RiderProfile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class ShopifySegmentSyncer
{
    protected int $syncedCount = 0;

    public function __construct(
        protected ShopifyClient $client,
    ) {}

    /**
     * Sync segment tags to Shopify for customers that changed since last sync.
     */
    public function syncIncremental(): int
    {
        return $this->sync(onlyChanged: true);
    }

    /**
     * Sync all customer segment tags to Shopify (full resync).
     */
    public function syncFull(): int
    {
        return $this->sync(onlyChanged: false);
    }

    protected function sync(bool $onlyChanged): int
    {
        $this->syncedCount = 0;

        $mode = $onlyChanged ? 'incremental' : 'full';
        Log::info("Shopify segment sync starting ({$mode})");

        $query = RiderProfile::query()
            ->where('lifecycle_stage', LifecycleStage::Customer)
            ->whereNotNull('segment')
            ->whereNotNull('shopify_customer_id')
            ->whereHas('shopifyCustomer', fn ($q) => $q->whereNotNull('shopify_id'));

        if ($onlyChanged) {
            $query->where(function ($q) {
                $q->whereNull('shopify_synced_at')
                    ->orWhereColumn('updated_at', '>', 'shopify_synced_at');
            });
        }

        $profiles = $query->with('shopifyCustomer:id,shopify_id')
            ->select(['id', 'segment', 'shopify_customer_id'])
            ->get();

        if ($profiles->isEmpty()) {
            Log::info("Shopify segment sync: no profiles to sync ({$mode})");

            return 0;
        }

        $this->removeOldTags($profiles);
        $this->addNewTags($profiles);

        $now = Carbon::now();
        RiderProfile::whereIn('id', $profiles->pluck('id'))
            ->update(['shopify_synced_at' => $now]);

        $this->syncedCount = $profiles->count();

        Log::info("Shopify segment sync completed ({$mode})", [
            'customers' => $this->syncedCount,
        ]);

        return $this->syncedCount;
    }

    /**
     * Remove all existing cw: tags from customers via bulk mutation.
     */
    protected function removeOldTags($profiles): void
    {
        $allTags = $this->getAllSegmentTags();

        $jsonlLines = [];

        foreach ($profiles as $profile) {
            $gid = "gid://shopify/Customer/{$profile->shopifyCustomer->shopify_id}";
            $jsonlLines[] = json_encode([
                'input' => [
                    'id' => $gid,
                    'tags' => $allTags,
                ],
            ]);
        }

        $jsonl = implode("\n", $jsonlLines);

        Log::info('Shopify: removing old cw: tags', ['customers' => count($jsonlLines)]);

        $this->client->bulkMutation(
            'mutation tagsRemove($id: ID!, $tags: [String!]!) { tagsRemove(id: $id, tags: $tags) { node { id } userErrors { field message } } }',
            $jsonl,
        );

        $this->waitForBulkOperation();
    }

    /**
     * Add new segment tags via bulk mutation.
     */
    protected function addNewTags($profiles): void
    {
        $jsonlLines = [];

        foreach ($profiles as $profile) {
            $gid = "gid://shopify/Customer/{$profile->shopifyCustomer->shopify_id}";
            $tag = "cw:{$profile->segment}";

            $jsonlLines[] = json_encode([
                'input' => [
                    'id' => $gid,
                    'tags' => [$tag],
                ],
            ]);
        }

        $jsonl = implode("\n", $jsonlLines);

        Log::info('Shopify: adding new cw: tags', ['customers' => count($jsonlLines)]);

        $this->client->bulkMutation(
            'mutation tagsAdd($id: ID!, $tags: [String!]!) { tagsAdd(id: $id, tags: $tags) { node { id } userErrors { field message } } }',
            $jsonl,
        );

        $this->waitForBulkOperation();
    }

    /**
     * Build the complete list of all possible cw: segment tags for removal.
     *
     * @return list<string>
     */
    protected function getAllSegmentTags(): array
    {
        $tags = [];

        foreach (CustomerSegment::cases() as $segment) {
            $tags[] = "cw:{$segment->value}";
        }

        foreach (FollowerSegment::cases() as $segment) {
            $tags[] = "cw:{$segment->value}";
        }

        return $tags;
    }

    /**
     * Wait for the current bulk operation to complete.
     */
    protected function waitForBulkOperation(): void
    {
        $maxWait = 300; // 5 minutes
        $elapsed = 0;

        do {
            sleep(2);
            $elapsed += 2;

            $status = $this->client->bulkOperationStatus();
            $state = $status['status'] ?? 'COMPLETED';

            if (in_array($state, ['COMPLETED', 'FAILED', 'CANCELED'])) {
                if ($state === 'FAILED') {
                    Log::error('Shopify bulk operation failed', $status);
                }

                return;
            }
        } while ($elapsed < $maxWait);

        Log::warning('Shopify bulk operation timed out after 5 minutes');
    }

    public function syncedCount(): int
    {
        return $this->syncedCount;
    }
}
