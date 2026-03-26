<?php

use App\Models\KlaviyoProfile;
use App\Models\ShopifyCustomer;
use App\Services\KlaviyoEngagementSyncer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'klaviyo.api_key' => 'pk_test_123',
        'klaviyo.revision' => '2024-10-15',
    ]);
});

function fakeMetricsResponse(): array
{
    return [
        'data' => [
            ['id' => 'm_recv', 'type' => 'metric', 'attributes' => ['name' => 'Received Email']],
            ['id' => 'm_open', 'type' => 'metric', 'attributes' => ['name' => 'Opened Email']],
            ['id' => 'm_click', 'type' => 'metric', 'attributes' => ['name' => 'Clicked Email']],
            ['id' => 'm_site', 'type' => 'metric', 'attributes' => ['name' => 'Active on Site']],
            ['id' => 'm_view', 'type' => 'metric', 'attributes' => ['name' => 'Viewed Product']],
            ['id' => 'm_cart', 'type' => 'metric', 'attributes' => ['name' => 'Added to Cart']],
            ['id' => 'm_checkout', 'type' => 'metric', 'attributes' => ['name' => 'Checkout Started']],
            ['id' => 'm_order', 'type' => 'metric', 'attributes' => ['name' => 'Placed Order']],
        ],
        'links' => ['next' => null],
    ];
}

function fakeBulkEventsResponse(array $events): array
{
    $data = [];

    foreach ($events as [$profileId, $datetime]) {
        $data[] = [
            'type' => 'event',
            'id' => fake()->uuid(),
            'attributes' => ['datetime' => $datetime],
            'relationships' => [
                'profile' => ['data' => ['type' => 'profile', 'id' => $profileId]],
            ],
        ];
    }

    return [
        'data' => $data,
        'links' => ['next' => null],
    ];
}

it('syncs engagement counts using bulk metric approach', function () {
    $profile = KlaviyoProfile::factory()->create([
        'klaviyo_id' => 'prof_001',
        'email' => 'test@cyclowax.cc',
        'last_event_date' => now()->subDay(),
    ]);

    Http::fake([
        'a.klaviyo.com/api/metrics' => Http::response(fakeMetricsResponse()),
        // Each metric type returns events filtered by that metric
        'a.klaviyo.com/api/events*metric_id*m_recv*' => Http::response(fakeBulkEventsResponse([
            ['prof_001', '2026-03-20T10:00:00+00:00'],
            ['prof_001', '2026-03-15T10:00:00+00:00'],
            ['prof_001', '2026-03-10T10:00:00+00:00'],
        ])),
        'a.klaviyo.com/api/events*metric_id*m_open*' => Http::response(fakeBulkEventsResponse([
            ['prof_001', '2026-03-20T10:05:00+00:00'],
            ['prof_001', '2026-03-15T10:05:00+00:00'],
        ])),
        'a.klaviyo.com/api/events*metric_id*m_click*' => Http::response(fakeBulkEventsResponse([
            ['prof_001', '2026-03-20T10:06:00+00:00'],
        ])),
        'a.klaviyo.com/api/events*metric_id*m_site*' => Http::response(fakeBulkEventsResponse([
            ['prof_001', '2026-03-20T10:07:00+00:00'],
            ['prof_001', '2026-03-18T10:07:00+00:00'],
        ])),
        'a.klaviyo.com/api/events*metric_id*m_view*' => Http::response(fakeBulkEventsResponse([
            ['prof_001', '2026-03-20T10:08:00+00:00'],
        ])),
        'a.klaviyo.com/api/events*metric_id*m_cart*' => Http::response(fakeBulkEventsResponse([
            ['prof_001', '2026-03-20T10:09:00+00:00'],
        ])),
        'a.klaviyo.com/api/events*metric_id*m_checkout*' => Http::response(fakeBulkEventsResponse([
            ['prof_001', '2026-03-20T10:10:00+00:00'],
        ])),
    ]);

    $syncer = app(KlaviyoEngagementSyncer::class);
    $count = $syncer->sync();

    $profile->refresh();

    expect($profile->emails_received)->toBe(3)
        ->and($profile->emails_opened)->toBe(2)
        ->and($profile->emails_clicked)->toBe(1)
        ->and($profile->site_visits)->toBe(2)
        ->and($profile->product_views)->toBe(1)
        ->and($profile->cart_adds)->toBe(1)
        ->and($profile->checkouts_started)->toBe(1)
        ->and($profile->engagement_synced_at)->not->toBeNull();
});

it('ignores events from Shopify customers', function () {
    $follower = KlaviyoProfile::factory()->create([
        'klaviyo_id' => 'prof_follower',
        'email' => 'follower@test.com',
        'last_event_date' => now()->subDay(),
    ]);

    $customer = KlaviyoProfile::factory()->create([
        'klaviyo_id' => 'prof_customer',
        'email' => 'customer@test.com',
        'last_event_date' => now()->subDay(),
    ]);

    ShopifyCustomer::factory()->create(['email' => 'customer@test.com']);

    Http::fake([
        'a.klaviyo.com/api/metrics' => Http::response(fakeMetricsResponse()),
        'a.klaviyo.com/api/events*' => Http::response(fakeBulkEventsResponse([
            ['prof_follower', '2026-03-20T10:00:00+00:00'],
            ['prof_customer', '2026-03-20T10:00:00+00:00'],
        ])),
    ]);

    $syncer = app(KlaviyoEngagementSyncer::class);
    $syncer->sync();

    $follower->refresh();
    $customer->refresh();

    expect($follower->engagement_synced_at)->not->toBeNull()
        ->and($customer->engagement_synced_at)->toBeNull();
});

it('marks profiles with zero events as synced', function () {
    KlaviyoProfile::factory()->create([
        'klaviyo_id' => 'prof_quiet',
        'email' => 'quiet@test.com',
        'last_event_date' => now()->subDay(),
    ]);

    Http::fake([
        'a.klaviyo.com/api/metrics' => Http::response(fakeMetricsResponse()),
        'a.klaviyo.com/api/events*' => Http::response(['data' => [], 'links' => ['next' => null]]),
    ]);

    $syncer = app(KlaviyoEngagementSyncer::class);
    $syncer->sync();

    $profile = KlaviyoProfile::where('klaviyo_id', 'prof_quiet')->first();

    expect($profile->emails_received)->toBe(0)
        ->and($profile->site_visits)->toBe(0)
        ->and($profile->engagement_synced_at)->not->toBeNull();
});
