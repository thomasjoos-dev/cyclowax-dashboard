<?php

use App\Enums\CustomerSegment;
use App\Enums\FollowerSegment;
use App\Enums\LifecycleStage;
use App\Models\RiderProfile;
use App\Models\ShopifyCustomer;
use App\Services\SegmentMovementService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns current segment distribution', function () {
    // Create followers
    RiderProfile::factory()->count(3)->create([
        'lifecycle_stage' => LifecycleStage::Follower,
        'segment' => FollowerSegment::Engaged->value,
    ]);
    RiderProfile::factory()->count(2)->create([
        'lifecycle_stage' => LifecycleStage::Follower,
        'segment' => FollowerSegment::Inactive->value,
    ]);

    // Create customers with RFM segments
    ShopifyCustomer::factory()->count(4)->create(['rfm_segment' => CustomerSegment::Champion]);
    ShopifyCustomer::factory()->count(2)->create(['rfm_segment' => CustomerSegment::AtRisk]);

    $service = app(SegmentMovementService::class);
    $result = $service->currentDistribution();

    expect($result)->toHaveKeys(['followers', 'customers'])
        ->and($result['followers'])->toHaveCount(2)
        ->and($result['customers'])->toHaveCount(2);
});

it('calculates risk indicators', function () {
    ShopifyCustomer::factory()->count(3)->create(['rfm_segment' => CustomerSegment::AtRisk]);
    ShopifyCustomer::factory()->count(7)->create(['rfm_segment' => CustomerSegment::Champion]);

    RiderProfile::factory()->count(4)->create([
        'lifecycle_stage' => LifecycleStage::Follower,
        'segment' => FollowerSegment::Inactive->value,
    ]);
    RiderProfile::factory()->count(6)->create([
        'lifecycle_stage' => LifecycleStage::Follower,
        'segment' => FollowerSegment::Engaged->value,
    ]);

    $service = app(SegmentMovementService::class);
    $result = $service->riskIndicators();

    expect($result['at_risk_customers'])->toBe(3)
        ->and($result['at_risk_pct'])->toBe(30.0)
        ->and($result['inactive_followers'])->toBe(4)
        ->and($result['inactive_followers_pct'])->toBe(40.0);
});
