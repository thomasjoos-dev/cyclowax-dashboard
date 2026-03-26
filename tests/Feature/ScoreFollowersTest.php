<?php

use App\Models\CustomerProfile;
use App\Models\KlaviyoProfile;
use App\Models\ShopifyCustomer;
use App\Services\FollowerScorer;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createFollowerProfile(array $klaviyoOverrides = [], array $profileOverrides = []): CustomerProfile
{
    $klaviyo = KlaviyoProfile::factory()->create(array_merge([
        'emails_received' => 20,
        'emails_opened' => 10,
        'emails_clicked' => 2,
        'site_visits' => 3,
        'product_views' => 0,
        'cart_adds' => 0,
        'checkouts_started' => 0,
        'last_event_date' => now()->subDays(5),
        'klaviyo_created_at' => now()->subMonths(6),
    ], $klaviyoOverrides));

    return CustomerProfile::factory()->create(array_merge([
        'email' => $klaviyo->email,
        'lifecycle_stage' => 'follower',
        'klaviyo_profile_id' => $klaviyo->id,
    ], $profileOverrides));
}

it('assigns new segment for recent signups', function () {
    createFollowerProfile([
        'klaviyo_created_at' => now()->subDays(10),
        'last_event_date' => now()->subDays(2),
    ]);

    $scorer = new FollowerScorer;
    $scorer->score();

    expect(CustomerProfile::first()->follower_segment)->toBe('new');
});

it('assigns hot_lead for abandoned cart with recent activity', function () {
    createFollowerProfile([
        'emails_received' => 30,
        'emails_opened' => 20,
        'emails_clicked' => 5,
        'site_visits' => 8,
        'product_views' => 10,
        'cart_adds' => 2,
        'checkouts_started' => 1,
        'last_event_date' => now()->subDays(3),
        'klaviyo_created_at' => now()->subMonths(3),
    ]);

    $scorer = new FollowerScorer;
    $scorer->score();

    $cp = CustomerProfile::first();

    expect($cp->follower_segment)->toBe('hot_lead')
        ->and($cp->intent_score)->toBe(4);
});

it('assigns high_potential for product viewers with engagement', function () {
    createFollowerProfile([
        'emails_received' => 30,
        'emails_opened' => 20,
        'emails_clicked' => 5,
        'site_visits' => 5,
        'product_views' => 8,
        'cart_adds' => 0,
        'checkouts_started' => 0,
        'last_event_date' => now()->subDays(5),
        'klaviyo_created_at' => now()->subMonths(6),
    ]);

    $scorer = new FollowerScorer;
    $scorer->score();

    $cp = CustomerProfile::first();

    expect($cp->follower_segment)->toBe('high_potential')
        ->and($cp->intent_score)->toBe(2);
});

it('assigns engaged for active email readers without product interest', function () {
    createFollowerProfile([
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

    $scorer = new FollowerScorer;
    $scorer->score();

    $cp = CustomerProfile::first();

    expect($cp->follower_segment)->toBe('engaged')
        ->and($cp->intent_score)->toBeLessThanOrEqual(1);
});

it('assigns fading after 30 days of inactivity', function () {
    createFollowerProfile([
        'emails_received' => 30,
        'emails_opened' => 15,
        'emails_clicked' => 2,
        'site_visits' => 4,
        'product_views' => 3,
        'last_event_date' => now()->subDays(45),
        'klaviyo_created_at' => now()->subYear(),
    ]);

    $scorer = new FollowerScorer;
    $scorer->score();

    expect(CustomerProfile::first()->follower_segment)->toBe('fading');
});

it('assigns inactive after 90 days', function () {
    createFollowerProfile([
        'emails_received' => 20,
        'emails_opened' => 5,
        'emails_clicked' => 0,
        'site_visits' => 0,
        'product_views' => 0,
        'last_event_date' => now()->subDays(100),
        'klaviyo_created_at' => now()->subYears(2),
    ]);

    $scorer = new FollowerScorer;
    $scorer->score();

    $cp = CustomerProfile::first();

    expect($cp->follower_segment)->toBe('inactive')
        ->and($cp->engagement_score)->toBeLessThanOrEqual(2);
});

it('halves intent score when last event is older than 30 days', function () {
    createFollowerProfile([
        'checkouts_started' => 1,
        'product_views' => 5,
        'last_event_date' => now()->subDays(45),
        'klaviyo_created_at' => now()->subYear(),
    ]);

    $scorer = new FollowerScorer;
    $scorer->score();

    // Base intent = 4 (checkout started), halved to 2 because > 30 days
    expect(CustomerProfile::first()->intent_score)->toBe(2);
});

it('does not score customer profiles', function () {
    $klaviyo = KlaviyoProfile::factory()->create([
        'emails_received' => 50,
        'emails_opened' => 40,
        'emails_clicked' => 15,
        'site_visits' => 10,
        'product_views' => 20,
        'checkouts_started' => 3,
        'last_event_date' => now()->subDay(),
    ]);

    $customer = ShopifyCustomer::factory()->create(['email' => $klaviyo->email]);

    CustomerProfile::factory()->create([
        'email' => $klaviyo->email,
        'lifecycle_stage' => 'customer',
        'shopify_customer_id' => $customer->id,
        'klaviyo_profile_id' => $klaviyo->id,
    ]);

    $scorer = new FollowerScorer;
    $scorer->score();

    expect(CustomerProfile::first()->follower_segment)->toBeNull()
        ->and(CustomerProfile::first()->intent_score)->toBeNull();
});
