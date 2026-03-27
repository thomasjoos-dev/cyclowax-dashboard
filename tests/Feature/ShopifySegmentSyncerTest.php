<?php

use App\Enums\LifecycleStage;
use App\Models\RiderProfile;
use App\Models\ShopifyCustomer;
use App\Services\ShopifyClient;
use App\Services\ShopifySegmentSyncer;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createCustomerRider(string $segment, array $overrides = []): RiderProfile
{
    $customer = ShopifyCustomer::factory()->create();

    return RiderProfile::factory()->create(array_merge([
        'email' => $customer->email,
        'lifecycle_stage' => LifecycleStage::Customer,
        'segment' => $segment,
        'shopify_customer_id' => $customer->id,
    ], $overrides));
}

it('syncs customer segment tags to Shopify', function () {
    createCustomerRider('champion');
    createCustomerRider('at_risk');

    $client = Mockery::mock(ShopifyClient::class);

    // tagsRemove bulk mutation
    $client->shouldReceive('bulkMutation')
        ->once()
        ->with(Mockery::on(fn ($m) => str_contains($m, 'tagsRemove')), Mockery::type('string'))
        ->andReturn(['id' => 'op-1', 'status' => 'CREATED']);

    // tagsAdd bulk mutation
    $client->shouldReceive('bulkMutation')
        ->once()
        ->with(Mockery::on(fn ($m) => str_contains($m, 'tagsAdd')), Mockery::on(function ($jsonl) {
            $lines = explode("\n", $jsonl);

            return count($lines) === 2
                && str_contains($jsonl, 'cw:champion')
                && str_contains($jsonl, 'cw:at_risk');
        }))
        ->andReturn(['id' => 'op-2', 'status' => 'CREATED']);

    // Status polling returns COMPLETED immediately
    $client->shouldReceive('bulkMutationStatus')
        ->andReturn(['status' => 'COMPLETED']);

    $syncer = new ShopifySegmentSyncer($client);
    $count = $syncer->syncFull();

    expect($count)->toBe(2);

    // shopify_synced_at should be set
    expect(RiderProfile::whereNull('shopify_synced_at')->count())->toBe(0);
});

it('skips follower profiles', function () {
    RiderProfile::factory()->create([
        'lifecycle_stage' => LifecycleStage::Follower,
        'segment' => 'hot_lead',
    ]);

    $client = Mockery::mock(ShopifyClient::class);
    $client->shouldNotReceive('bulkMutation');

    $syncer = new ShopifySegmentSyncer($client);
    $count = $syncer->syncFull();

    expect($count)->toBe(0);
});

it('only syncs changed customers in incremental mode', function () {
    // Already synced — skip
    createCustomerRider('champion', [
        'shopify_synced_at' => now(),
        'updated_at' => now()->subHour(),
    ]);

    // Changed — include
    createCustomerRider('at_risk', [
        'shopify_synced_at' => now()->subDay(),
        'updated_at' => now(),
    ]);

    $client = Mockery::mock(ShopifyClient::class);

    $client->shouldReceive('bulkMutation')
        ->twice()
        ->andReturn(['id' => 'op-1', 'status' => 'CREATED']);

    $client->shouldReceive('bulkMutationStatus')
        ->andReturn(['status' => 'COMPLETED']);

    $syncer = new ShopifySegmentSyncer($client);
    $count = $syncer->syncIncremental();

    expect($count)->toBe(1);
});

it('does nothing when no customers need syncing', function () {
    $client = Mockery::mock(ShopifyClient::class);
    $client->shouldNotReceive('bulkMutation');

    $syncer = new ShopifySegmentSyncer($client);
    $count = $syncer->syncIncremental();

    expect($count)->toBe(0);
});

it('uses cw: prefix for tags', function () {
    createCustomerRider('rising');

    $capturedJsonl = null;

    $client = Mockery::mock(ShopifyClient::class);

    $client->shouldReceive('bulkMutation')
        ->with(Mockery::on(fn ($m) => str_contains($m, 'tagsRemove')), Mockery::type('string'))
        ->andReturn(['id' => 'op-1', 'status' => 'CREATED']);

    $client->shouldReceive('bulkMutation')
        ->with(Mockery::on(fn ($m) => str_contains($m, 'tagsAdd')), Mockery::on(function ($jsonl) use (&$capturedJsonl) {
            $capturedJsonl = $jsonl;

            return true;
        }))
        ->andReturn(['id' => 'op-2', 'status' => 'CREATED']);

    $client->shouldReceive('bulkMutationStatus')
        ->andReturn(['status' => 'COMPLETED']);

    $syncer = new ShopifySegmentSyncer($client);
    $syncer->syncFull();

    $line = json_decode($capturedJsonl, true);

    expect($line['input']['tags'])->toBe(['cw:rising']);
});
