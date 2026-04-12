<?php

use App\Models\KlaviyoProfile;
use App\Services\Sync\KlaviyoProfileSyncer;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'klaviyo.api_key' => 'pk_test_123',
        'klaviyo.revision' => '2024-10-15',
    ]);
});

it('syncs profiles from Klaviyo API', function () {
    Http::fake([
        'a.klaviyo.com/api/profiles*' => Http::response([
            'data' => [
                [
                    'id' => 'profile_001',
                    'type' => 'profile',
                    'attributes' => [
                        'email' => 'jan@cyclowax.cc',
                        'first_name' => 'Jan',
                        'last_name' => 'De Vries',
                        'phone_number' => '+31612345678',
                        'external_id' => '12345',
                        'location' => [
                            'city' => 'Amsterdam',
                            'country' => 'NL',
                            'zip' => '1012AB',
                            'region' => 'Noord-Holland',
                        ],
                        'properties' => ['favorite_product' => 'Chain Wax'],
                        'predictive_analytics' => [
                            'historic_clv' => 125.50,
                            'predicted_clv' => 80.00,
                            'total_clv' => 205.50,
                            'historic_number_of_orders' => 3,
                            'predicted_number_of_orders' => 2,
                            'average_order_value' => 41.83,
                            'churn_probability' => 0.2345,
                        ],
                        'created' => '2025-01-15T10:00:00+00:00',
                        'updated' => '2026-03-20T14:30:00+00:00',
                    ],
                ],
                [
                    'id' => 'profile_002',
                    'type' => 'profile',
                    'attributes' => [
                        'email' => 'piet@example.com',
                        'first_name' => 'Piet',
                        'last_name' => null,
                        'location' => ['country' => 'BE'],
                        'properties' => [],
                        'predictive_analytics' => [],
                        'created' => '2025-06-01T08:00:00+00:00',
                        'updated' => '2026-02-10T12:00:00+00:00',
                    ],
                ],
            ],
            'links' => ['next' => null],
        ]),
    ]);

    $syncer = app(KlaviyoProfileSyncer::class);
    $result = $syncer->sync();

    expect($result['count'])->toBe(2)
        ->and($result['complete'])->toBeTrue()
        ->and($result['cursor'])->toBeNull()
        ->and(KlaviyoProfile::count())->toBe(2);

    $jan = KlaviyoProfile::query()->where('klaviyo_id', 'profile_001')->first();

    expect($jan->email)->toBe('jan@cyclowax.cc')
        ->and($jan->first_name)->toBe('Jan')
        ->and($jan->country)->toBe('NL')
        ->and($jan->city)->toBe('Amsterdam')
        ->and($jan->historic_clv)->toBe('125.50')
        ->and($jan->total_clv)->toBe('205.50')
        ->and($jan->historic_number_of_orders)->toBe('3.00')
        ->and($jan->churn_probability)->toBe('0.2345');
});

it('upserts existing profiles without duplicating', function () {
    KlaviyoProfile::factory()->create(['klaviyo_id' => 'profile_001', 'email' => 'old@test.com']);

    Http::fake([
        'a.klaviyo.com/api/profiles*' => Http::response([
            'data' => [
                [
                    'id' => 'profile_001',
                    'type' => 'profile',
                    'attributes' => [
                        'email' => 'new@test.com',
                        'location' => [],
                        'properties' => [],
                        'predictive_analytics' => [],
                        'created' => '2025-01-01T00:00:00+00:00',
                        'updated' => '2026-03-25T00:00:00+00:00',
                    ],
                ],
            ],
            'links' => ['next' => null],
        ]),
    ]);

    $syncer = app(KlaviyoProfileSyncer::class);
    $syncer->sync();

    expect(KlaviyoProfile::count())->toBe(1)
        ->and(KlaviyoProfile::first()->email)->toBe('new@test.com');
});
