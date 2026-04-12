<?php

use App\Enums\CustomerSegment;
use App\Enums\FollowerSegment;
use App\Enums\LifecycleStage;
use App\Models\KlaviyoProfile;
use App\Models\RiderProfile;
use App\Models\SegmentTransition;
use App\Models\ShopifyCustomer;
use App\Models\ShopifyOrder;
use App\Services\Scoring\FollowerScorer;
use App\Services\Sync\RiderProfileLinker;

it('logs a transition when follower segment changes', function () {
    $klaviyo = KlaviyoProfile::factory()->create([
        'emails_received' => 40,
        'emails_opened' => 30,
        'emails_clicked' => 3,
        'site_visits' => 2,
        'product_views' => 0,
        'cart_adds' => 0,
        'checkouts_started' => 0,
        'last_event_date' => now()->subDays(10),
        'klaviyo_created_at' => now()->subMonths(6),
    ]);

    $profile = RiderProfile::factory()->create([
        'email' => $klaviyo->email,
        'lifecycle_stage' => LifecycleStage::Follower,
        'klaviyo_profile_id' => $klaviyo->id,
        'segment' => FollowerSegment::Inactive->value,
    ]);

    $scorer = app(FollowerScorer::class);
    $scorer->score();

    $profile->refresh();

    expect($profile->segment)->toBe(FollowerSegment::Engaged->value)
        ->and($profile->previous_segment)->toBe(FollowerSegment::Inactive->value);

    $transition = SegmentTransition::where('rider_profile_id', $profile->id)->first();

    expect($transition)->not->toBeNull()
        ->and($transition->type)->toBe('segment_change')
        ->and($transition->from_segment)->toBe('inactive')
        ->and($transition->to_segment)->toBe('engaged')
        ->and($transition->occurred_at)->not->toBeNull();
});

it('does not log a transition when segment stays the same', function () {
    $klaviyo = KlaviyoProfile::factory()->create([
        'emails_received' => 5,
        'emails_opened' => 0,
        'emails_clicked' => 0,
        'site_visits' => 0,
        'product_views' => 0,
        'cart_adds' => 0,
        'checkouts_started' => 0,
        'last_event_date' => now()->subDays(200),
        'klaviyo_created_at' => now()->subYear(),
    ]);

    $profile = RiderProfile::factory()->create([
        'email' => $klaviyo->email,
        'lifecycle_stage' => LifecycleStage::Follower,
        'klaviyo_profile_id' => $klaviyo->id,
        'segment' => FollowerSegment::Inactive->value,
    ]);

    $scorer = app(FollowerScorer::class);
    $scorer->score();

    expect(SegmentTransition::count())->toBe(0);
});

it('logs a transition when follower scores for the first time', function () {
    $klaviyo = KlaviyoProfile::factory()->create([
        'emails_received' => 20,
        'emails_opened' => 10,
        'emails_clicked' => 2,
        'site_visits' => 3,
        'product_views' => 0,
        'cart_adds' => 0,
        'checkouts_started' => 0,
        'last_event_date' => now()->subDays(5),
        'klaviyo_created_at' => now()->subMonths(6),
    ]);

    $profile = RiderProfile::factory()->create([
        'email' => $klaviyo->email,
        'lifecycle_stage' => LifecycleStage::Follower,
        'klaviyo_profile_id' => $klaviyo->id,
        'segment' => null,
    ]);

    $scorer = app(FollowerScorer::class);
    $scorer->score();

    $transition = SegmentTransition::first();

    expect($transition)->not->toBeNull()
        ->and($transition->from_segment)->toBeNull()
        ->and($transition->to_segment)->not->toBeNull();
});

it('logs a lifecycle transition when follower becomes customer', function () {
    $klaviyo = KlaviyoProfile::factory()->create(['email' => 'convert@test.com']);

    RiderProfile::factory()->create([
        'email' => 'convert@test.com',
        'lifecycle_stage' => LifecycleStage::Follower,
        'klaviyo_profile_id' => $klaviyo->id,
        'segment' => FollowerSegment::HotLead->value,
    ]);

    ShopifyCustomer::factory()->create(['email' => 'convert@test.com']);

    $linker = app(RiderProfileLinker::class);
    $linker->link();

    $transition = SegmentTransition::where('type', 'lifecycle_change')->first();

    expect($transition)->not->toBeNull()
        ->and($transition->from_lifecycle)->toBe('follower')
        ->and($transition->to_lifecycle)->toBe('customer')
        ->and($transition->from_segment)->toBe('hot_lead');
});

it('does not log lifecycle transition for new customers without follower history', function () {
    ShopifyCustomer::factory()->create(['email' => 'new@test.com']);

    $linker = app(RiderProfileLinker::class);
    $linker->link();

    expect(SegmentTransition::count())->toBe(0);
});

it('logs RFM segment transitions', function () {
    $customer = ShopifyCustomer::factory()->create([
        'rfm_segment' => CustomerSegment::OneTimer,
    ]);

    RiderProfile::factory()->customer()->create([
        'email' => $customer->email,
        'shopify_customer_id' => $customer->id,
    ]);

    // Create enough orders to push into a higher segment
    for ($i = 0; $i < 5; $i++) {
        ShopifyOrder::factory()->create([
            'customer_id' => $customer->id,
            'financial_status' => 'PAID',
            'total_price' => 300,
            'tax' => 63,
            'refunded' => 0,
            'net_revenue' => 237,
            'ordered_at' => now()->subDays($i * 30),
        ]);
    }

    $this->artisan('customers:calculate-rfm')->assertSuccessful();

    $customer->refresh();

    expect($customer->previous_rfm_segment)->toBe(CustomerSegment::OneTimer);

    $transition = SegmentTransition::where('type', 'segment_change')->first();

    expect($transition)->not->toBeNull()
        ->and($transition->from_segment)->toBe('one_timer')
        ->and($transition->to_segment)->toBe($customer->rfm_segment->value);
});
