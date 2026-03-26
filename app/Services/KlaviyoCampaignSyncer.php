<?php

namespace App\Services;

use App\Models\KlaviyoCampaign;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class KlaviyoCampaignSyncer
{
    protected int $syncedCount = 0;

    protected ?string $placedOrderMetricId = null;

    public function __construct(
        protected KlaviyoClient $klaviyo,
    ) {}

    /**
     * Sync all email campaigns from Klaviyo, then enrich sent campaigns with metrics.
     */
    public function sync(): int
    {
        $this->syncedCount = 0;

        Log::info('Klaviyo campaign sync starting');

        $this->syncCampaigns();
        $this->enrichSentCampaignsWithMetrics();

        Log::info('Klaviyo campaign sync completed', ['synced' => $this->syncedCount]);

        return $this->syncedCount;
    }

    /**
     * Fetch and upsert all email campaigns.
     */
    protected function syncCampaigns(): void
    {
        $campaigns = $this->klaviyo->paginate('campaigns', [
            'filter' => "equals(messages.channel,'email')",
        ]);

        $batch = [];

        foreach ($campaigns as $campaign) {
            $batch[] = $campaign;

            if (count($batch) >= 50) {
                $this->upsertBatch($batch);
                $batch = [];
            }
        }

        if (count($batch) > 0) {
            $this->upsertBatch($batch);
        }
    }

    /**
     * Fetch performance metrics for all sent campaigns via the Reporting API.
     * The reporting endpoint has strict rate limits (1/s, 2/min) so we process one at a time.
     */
    protected function enrichSentCampaignsWithMetrics(): void
    {
        $sentCampaigns = KlaviyoCampaign::query()
            ->whereRaw('LOWER(status) = ?', ['sent'])
            ->where('recipients', 0)
            ->get();

        if ($sentCampaigns->isEmpty()) {
            return;
        }

        $this->placedOrderMetricId = $this->resolvePlacedOrderMetricId();

        if (! $this->placedOrderMetricId) {
            Log::warning('Could not find Placed Order metric ID — skipping campaign metrics enrichment');

            return;
        }

        Log::info('Enriching sent campaigns with metrics', [
            'count' => $sentCampaigns->count(),
            'conversion_metric_id' => $this->placedOrderMetricId,
        ]);

        foreach ($sentCampaigns as $campaign) {
            $this->fetchAndStoreMetrics($campaign);

            // Reporting API rate limit: 2 requests/min steady state
            sleep(31);
        }
    }

    /**
     * Look up the Klaviyo metric ID for "Placed Order" from the Shopify integration.
     */
    protected function resolvePlacedOrderMetricId(): ?string
    {
        try {
            $metrics = $this->klaviyo->paginate('metrics', [
                'filter' => "equals(integration.name,'Shopify')",
            ]);

            foreach ($metrics as $metric) {
                if (($metric['attributes']['name'] ?? '') === 'Placed Order') {
                    return $metric['id'];
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to resolve Placed Order metric ID', ['error' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * Fetch metrics for a single campaign from the Reporting API and update the record.
     */
    protected function fetchAndStoreMetrics(KlaviyoCampaign $campaign): void
    {
        try {
            $response = $this->klaviyo->post('campaign-values-reports', [
                'data' => [
                    'type' => 'campaign-values-report',
                    'attributes' => [
                        'statistics' => [
                            'recipients',
                            'delivered',
                            'bounced',
                            'opens',
                            'opens_unique',
                            'clicks',
                            'clicks_unique',
                            'unsubscribes',
                            'conversions',
                            'conversion_value',
                            'revenue_per_recipient',
                        ],
                        'timeframe' => ['key' => 'last_12_months'],
                        'conversion_metric_id' => $this->placedOrderMetricId,
                        'filter' => "equals(campaign_id,\"{$campaign->klaviyo_id}\")",
                    ],
                ],
            ]);

            $results = $response['data']['attributes']['results'] ?? [];

            if (empty($results)) {
                return;
            }

            $stats = $results[0]['statistics'] ?? [];

            $campaign->update([
                'recipients' => (int) ($stats['recipients'] ?? 0),
                'delivered' => (int) ($stats['delivered'] ?? 0),
                'bounced' => (int) ($stats['bounced'] ?? 0),
                'opens' => (int) ($stats['opens'] ?? 0),
                'opens_unique' => (int) ($stats['opens_unique'] ?? 0),
                'clicks' => (int) ($stats['clicks'] ?? 0),
                'clicks_unique' => (int) ($stats['clicks_unique'] ?? 0),
                'unsubscribes' => (int) ($stats['unsubscribes'] ?? 0),
                'conversions' => (int) ($stats['conversions'] ?? 0),
                'conversion_value' => (float) ($stats['conversion_value'] ?? 0),
                'revenue_per_recipient' => (float) ($stats['revenue_per_recipient'] ?? 0),
            ]);

            Log::info('Campaign metrics enriched', ['campaign' => $campaign->name, 'id' => $campaign->klaviyo_id]);
        } catch (\Throwable $e) {
            Log::warning('Failed to fetch metrics for campaign', [
                'campaign' => $campaign->name,
                'id' => $campaign->klaviyo_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Upsert a batch of campaigns into the database.
     *
     * @param  array<int, array<string, mixed>>  $batch
     */
    protected function upsertBatch(array $batch): void
    {
        $rows = array_map(fn (array $campaign) => $this->mapCampaign($campaign), $batch);

        DB::transaction(function () use ($rows) {
            KlaviyoCampaign::query()->upsert($rows, ['klaviyo_id'], [
                'name', 'channel', 'status', 'archived', 'send_strategy',
                'is_tracking_opens', 'is_tracking_clicks',
                'scheduled_at', 'send_time',
                'klaviyo_created_at', 'klaviyo_updated_at', 'updated_at',
            ]);
        });

        $this->syncedCount += count($rows);

        Log::info('Klaviyo campaigns batch upserted', ['batch_size' => count($rows), 'total' => $this->syncedCount]);
    }

    /**
     * Map a Klaviyo API campaign to a database row.
     *
     * @param  array<string, mixed>  $campaign
     * @return array<string, mixed>
     */
    protected function mapCampaign(array $campaign): array
    {
        $attrs = $campaign['attributes'] ?? [];
        $sendStrategy = $attrs['send_strategy'] ?? [];
        $trackingOptions = $attrs['tracking_options'] ?? [];

        return [
            'klaviyo_id' => $campaign['id'],
            'name' => $attrs['name'] ?? 'Untitled',
            'channel' => 'email',
            'status' => $attrs['status'] ?? 'unknown',
            'archived' => $attrs['archived'] ?? false,
            'send_strategy' => $sendStrategy['method'] ?? null,
            'is_tracking_opens' => $trackingOptions['is_tracking_opens'] ?? false,
            'is_tracking_clicks' => $trackingOptions['is_tracking_clicks'] ?? false,
            'recipients' => 0,
            'delivered' => 0,
            'bounced' => 0,
            'opens' => 0,
            'opens_unique' => 0,
            'clicks' => 0,
            'clicks_unique' => 0,
            'unsubscribes' => 0,
            'conversions' => 0,
            'conversion_value' => 0,
            'revenue_per_recipient' => 0,
            'scheduled_at' => $attrs['scheduled_at'] ?? null,
            'send_time' => $attrs['send_time'] ?? null,
            'klaviyo_created_at' => $attrs['created_at'] ?? null,
            'klaviyo_updated_at' => $attrs['updated_at'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
