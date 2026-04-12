<?php

use App\Enums\LifecycleStage;
use App\Models\KlaviyoProfile;
use App\Models\RiderProfile;
use App\Models\ShopifyCustomer;
use App\Services\Sync\RiderProfileLinker;

it('links Shopify customers with matching Klaviyo profiles', function () {
    $customer = ShopifyCustomer::factory()->create(['email' => 'jan@cyclowax.cc']);
    $profile = KlaviyoProfile::factory()->create(['email' => 'jan@cyclowax.cc']);

    $linker = app(RiderProfileLinker::class);
    $result = $linker->link();

    expect($result['customers'])->toBe(1);

    $cp = RiderProfile::first();

    expect($cp->email)->toBe('jan@cyclowax.cc')
        ->and($cp->lifecycle_stage)->toBe(LifecycleStage::Customer)
        ->and($cp->shopify_customer_id)->toBe($customer->id)
        ->and($cp->klaviyo_profile_id)->toBe($profile->id);
});

it('creates follower profiles for Klaviyo-only subscribers', function () {
    KlaviyoProfile::factory()->create(['email' => 'follower@test.com']);

    $linker = app(RiderProfileLinker::class);
    $result = $linker->link();

    expect($result['followers'])->toBe(1);

    $cp = RiderProfile::where('email', 'follower@test.com')->first();

    expect($cp->lifecycle_stage)->toBe(LifecycleStage::Follower)
        ->and($cp->shopify_customer_id)->toBeNull()
        ->and($cp->klaviyo_profile_id)->not->toBeNull();
});

it('matches emails case-insensitively', function () {
    ShopifyCustomer::factory()->create(['email' => 'Jan@Cyclowax.CC']);
    KlaviyoProfile::factory()->create(['email' => 'jan@cyclowax.cc']);

    $linker = app(RiderProfileLinker::class);
    $linker->link();

    expect(RiderProfile::count())->toBe(1);

    $cp = RiderProfile::first();

    expect($cp->lifecycle_stage)->toBe(LifecycleStage::Customer)
        ->and($cp->shopify_customer_id)->not->toBeNull()
        ->and($cp->klaviyo_profile_id)->not->toBeNull();
});

it('is idempotent when run multiple times', function () {
    ShopifyCustomer::factory()->create(['email' => 'repeat@test.com']);
    KlaviyoProfile::factory()->create(['email' => 'repeat@test.com']);
    KlaviyoProfile::factory()->create(['email' => 'follower@test.com']);

    $linker = app(RiderProfileLinker::class);
    $linker->link();
    $linker->link();

    expect(RiderProfile::count())->toBe(2);
});

it('skips profiles without email', function () {
    KlaviyoProfile::factory()->create(['email' => null]);
    KlaviyoProfile::factory()->create(['email' => '']);

    $linker = app(RiderProfileLinker::class);
    $result = $linker->link();

    expect($result['followers'])->toBe(0)
        ->and(RiderProfile::count())->toBe(0);
});

it('handles Shopify-only customers without Klaviyo profile', function () {
    ShopifyCustomer::factory()->create(['email' => 'shopify-only@test.com']);

    $linker = app(RiderProfileLinker::class);
    $linker->link();

    $cp = RiderProfile::first();

    expect($cp->lifecycle_stage)->toBe(LifecycleStage::Customer)
        ->and($cp->shopify_customer_id)->not->toBeNull()
        ->and($cp->klaviyo_profile_id)->toBeNull();
});
