<?php

use App\Models\KlaviyoCampaign;
use App\Services\Sync\KlaviyoCampaignSyncer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'klaviyo.api_key' => 'pk_test_123',
        'klaviyo.revision' => '2024-10-15',
    ]);
});

it('syncs campaigns from Klaviyo API', function () {
    Http::fake([
        'a.klaviyo.com/api/campaigns*' => Http::response([
            'data' => [
                [
                    'id' => 'campaign_001',
                    'type' => 'campaign',
                    'attributes' => [
                        'name' => 'Spring Sale 2026',
                        'status' => 'sent',
                        'archived' => false,
                        'send_strategy' => ['method' => 'immediate'],
                        'tracking_options' => [
                            'is_tracking_opens' => true,
                            'is_tracking_clicks' => true,
                        ],
                        'scheduled_at' => '2026-03-15T09:00:00+00:00',
                        'send_time' => '2026-03-15T09:05:00+00:00',
                        'created_at' => '2026-03-10T14:00:00+00:00',
                        'updated_at' => '2026-03-15T09:05:00+00:00',
                    ],
                ],
                [
                    'id' => 'campaign_002',
                    'type' => 'campaign',
                    'attributes' => [
                        'name' => 'Newsletter March',
                        'status' => 'draft',
                        'archived' => false,
                        'send_strategy' => ['method' => 'static'],
                        'tracking_options' => [
                            'is_tracking_opens' => true,
                            'is_tracking_clicks' => false,
                        ],
                        'created_at' => '2026-03-20T10:00:00+00:00',
                        'updated_at' => '2026-03-20T10:00:00+00:00',
                    ],
                ],
            ],
            'links' => ['next' => null],
        ]),
        'a.klaviyo.com/api/metrics*' => Http::response([
            'data' => [
                ['id' => 'X75hjs', 'type' => 'metric', 'attributes' => ['name' => 'Placed Order']],
            ],
            'links' => ['next' => null],
        ]),
        'a.klaviyo.com/api/campaign-values-reports*' => Http::response([
            'data' => [
                'type' => 'campaign-values-report',
                'attributes' => [
                    'results' => [
                        [
                            'statistics' => [
                                'recipients' => 5000,
                                'delivered' => 4850,
                                'bounced' => 150,
                                'opens' => 2100,
                                'opens_unique' => 1800,
                                'clicks' => 450,
                                'clicks_unique' => 380,
                                'unsubscribes' => 12,
                                'conversions' => 35,
                                'conversion_value' => 1575.00,
                                'revenue_per_recipient' => 0.3150,
                            ],
                        ],
                    ],
                ],
            ],
        ]),
    ]);

    $syncer = app(KlaviyoCampaignSyncer::class);
    $result = $syncer->sync();

    expect($result['count'])->toBe(2)
        ->and(KlaviyoCampaign::count())->toBe(2);

    $campaign = KlaviyoCampaign::query()->where('klaviyo_id', 'campaign_001')->first();

    expect($campaign->name)->toBe('Spring Sale 2026')
        ->and($campaign->status)->toBe('sent')
        ->and($campaign->channel)->toBe('email')
        ->and($campaign->is_tracking_opens)->toBeTrue()
        ->and($campaign->recipients)->toBe(5000)
        ->and($campaign->delivered)->toBe(4850)
        ->and($campaign->clicks)->toBe(450)
        ->and($campaign->conversions)->toBe(35)
        ->and($campaign->conversion_value)->toBe('1575.00');
});

it('upserts existing campaigns without duplicating', function () {
    KlaviyoCampaign::factory()->create(['klaviyo_id' => 'campaign_001', 'name' => 'Old Name']);

    Http::fake([
        'a.klaviyo.com/api/campaigns*' => Http::response([
            'data' => [
                [
                    'id' => 'campaign_001',
                    'type' => 'campaign',
                    'attributes' => [
                        'name' => 'Updated Name',
                        'status' => 'draft',
                        'archived' => false,
                        'send_strategy' => ['method' => 'immediate'],
                        'tracking_options' => [],
                        'created_at' => '2026-01-01T00:00:00+00:00',
                        'updated_at' => '2026-03-25T00:00:00+00:00',
                    ],
                ],
            ],
            'links' => ['next' => null],
        ]),
    ]);

    $syncer = app(KlaviyoCampaignSyncer::class);
    $syncer->sync();

    expect(KlaviyoCampaign::count())->toBe(1)
        ->and(KlaviyoCampaign::first()->name)->toBe('Updated Name');
});

it('handles metrics API failure gracefully without crashing sync', function () {
    Http::fake([
        'a.klaviyo.com/api/campaigns*' => Http::response([
            'data' => [
                [
                    'id' => 'campaign_fail',
                    'type' => 'campaign',
                    'attributes' => [
                        'name' => 'Failing Campaign',
                        'status' => 'sent',
                        'archived' => false,
                        'send_strategy' => [],
                        'tracking_options' => [],
                        'created_at' => '2026-01-01T00:00:00+00:00',
                        'updated_at' => '2026-03-25T00:00:00+00:00',
                    ],
                ],
            ],
            'links' => ['next' => null],
        ]),
        'a.klaviyo.com/api/metrics*' => Http::response([
            'data' => [
                ['id' => 'X75hjs', 'type' => 'metric', 'attributes' => ['name' => 'Placed Order']],
            ],
            'links' => ['next' => null],
        ]),
        'a.klaviyo.com/api/campaign-values-reports*' => Http::response(['errors' => [['detail' => 'Server Error']]], 500),
    ]);

    $syncer = app(KlaviyoCampaignSyncer::class);
    $result = $syncer->sync();

    expect($result['count'])->toBe(1)
        ->and(KlaviyoCampaign::first()->recipients)->toBe(0);
});
