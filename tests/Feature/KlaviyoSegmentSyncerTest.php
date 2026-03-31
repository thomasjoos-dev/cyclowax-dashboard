<?php

use App\Enums\LifecycleStage;
use App\Models\KlaviyoProfile;
use App\Models\RiderProfile;
use App\Services\Api\KlaviyoClient;
use App\Services\Sync\KlaviyoSegmentSyncer;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createRiderWithKlaviyo(string $lifecycle, ?string $segment, array $overrides = []): RiderProfile
{
    $klaviyo = KlaviyoProfile::factory()->create();

    return RiderProfile::factory()->create(array_merge([
        'email' => $klaviyo->email,
        'lifecycle_stage' => $lifecycle,
        'segment' => $segment,
        'klaviyo_profile_id' => $klaviyo->id,
    ], $overrides));
}

it('syncs profiles with segments to Klaviyo', function () {
    createRiderWithKlaviyo('follower', 'hot_lead');
    createRiderWithKlaviyo('customer', 'champion');

    $client = Mockery::mock(KlaviyoClient::class);
    $client->shouldReceive('post')
        ->once()
        ->with('profile-bulk-import-jobs', Mockery::on(function ($payload) {
            $profiles = $payload['data']['attributes']['profiles']['data'];

            return count($profiles) === 2
                && $profiles[0]['attributes']['properties']['cyclowax_lifecycle'] !== null
                && $profiles[0]['attributes']['properties']['cyclowax_segment'] !== null;
        }))
        ->andReturn(['data' => ['id' => 'job-123']]);

    $syncer = new KlaviyoSegmentSyncer($client);
    $count = $syncer->syncFull();

    expect($count)->toBe(2)
        ->and($syncer->batchCount())->toBe(1);

    // klaviyo_synced_at should be set
    expect(RiderProfile::whereNull('klaviyo_synced_at')->count())->toBe(0);
});

it('skips profiles without a segment', function () {
    createRiderWithKlaviyo('follower', null);
    createRiderWithKlaviyo('follower', 'engaged');

    $client = Mockery::mock(KlaviyoClient::class);
    $client->shouldReceive('post')
        ->once()
        ->with('profile-bulk-import-jobs', Mockery::on(function ($payload) {
            return count($payload['data']['attributes']['profiles']['data']) === 1;
        }))
        ->andReturn(['data' => ['id' => 'job-123']]);

    $syncer = new KlaviyoSegmentSyncer($client);
    $count = $syncer->syncFull();

    expect($count)->toBe(1);
});

it('skips profiles without a klaviyo_profile_id', function () {
    RiderProfile::factory()->create([
        'lifecycle_stage' => LifecycleStage::Follower,
        'segment' => 'engaged',
        'klaviyo_profile_id' => null,
    ]);

    $client = Mockery::mock(KlaviyoClient::class);
    $client->shouldNotReceive('post');

    $syncer = new KlaviyoSegmentSyncer($client);
    $count = $syncer->syncFull();

    expect($count)->toBe(0);
});

it('only syncs changed profiles in incremental mode', function () {
    // Already synced — should be skipped
    createRiderWithKlaviyo('follower', 'engaged', [
        'klaviyo_synced_at' => now(),
        'updated_at' => now()->subHour(),
    ]);

    // Changed since last sync — should be included
    createRiderWithKlaviyo('follower', 'hot_lead', [
        'klaviyo_synced_at' => now()->subDay(),
        'updated_at' => now(),
    ]);

    // Never synced — should be included
    createRiderWithKlaviyo('customer', 'champion', [
        'klaviyo_synced_at' => null,
    ]);

    $client = Mockery::mock(KlaviyoClient::class);
    $client->shouldReceive('post')
        ->once()
        ->with('profile-bulk-import-jobs', Mockery::on(function ($payload) {
            return count($payload['data']['attributes']['profiles']['data']) === 2;
        }))
        ->andReturn(['data' => ['id' => 'job-123']]);

    $syncer = new KlaviyoSegmentSyncer($client);
    $count = $syncer->syncIncremental();

    expect($count)->toBe(2);
});

it('builds correct Klaviyo payload format', function () {
    createRiderWithKlaviyo('follower', 'hot_lead');

    $capturedPayload = null;

    $client = Mockery::mock(KlaviyoClient::class);
    $client->shouldReceive('post')
        ->once()
        ->withArgs(function ($endpoint, $payload) use (&$capturedPayload) {
            $capturedPayload = $payload;

            return true;
        })
        ->andReturn(['data' => ['id' => 'job-123']]);

    $syncer = new KlaviyoSegmentSyncer($client);
    $syncer->syncFull();

    $profile = $capturedPayload['data']['attributes']['profiles']['data'][0];

    expect($capturedPayload['data']['type'])->toBe('profile-bulk-import-job')
        ->and($profile['type'])->toBe('profile')
        ->and($profile['attributes']['email'])->not->toBeNull()
        ->and($profile['attributes']['properties']['cyclowax_lifecycle'])->toBe('follower')
        ->and($profile['attributes']['properties']['cyclowax_segment'])->toBe('hot_lead');
});

it('does nothing when no profiles need syncing', function () {
    $client = Mockery::mock(KlaviyoClient::class);
    $client->shouldNotReceive('post');

    $syncer = new KlaviyoSegmentSyncer($client);
    $count = $syncer->syncIncremental();

    expect($count)->toBe(0)
        ->and($syncer->batchCount())->toBe(0);
});
