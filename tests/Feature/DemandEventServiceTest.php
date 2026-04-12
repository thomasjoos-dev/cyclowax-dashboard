<?php

use App\Enums\DemandEventType;
use App\Enums\ProductCategory;
use App\Models\DemandEvent;
use App\Services\Forecast\Demand\DemandEventService;

it('creates a demand event with categories', function () {
    $service = app(DemandEventService::class);

    $event = $service->createWithCategories(
        [
            'name' => 'Test BF',
            'type' => DemandEventType::PromoCampaign,
            'start_date' => '2025-11-20',
            'end_date' => '2025-12-01',
            'is_historical' => true,
        ],
        [
            ['product_category' => ProductCategory::StarterKit->value, 'pull_forward_pct' => 30],
            ['product_category' => ProductCategory::WaxTablet->value],
        ],
    );

    expect($event)->toBeInstanceOf(DemandEvent::class)
        ->and($event->categories)->toHaveCount(2)
        ->and($event->type)->toBe(DemandEventType::PromoCampaign)
        ->and($event->is_historical)->toBeTrue();
});

it('retrieves events for a period', function () {
    $service = app(DemandEventService::class);

    $service->createWithCategories(
        ['name' => 'E1', 'type' => DemandEventType::PromoCampaign, 'start_date' => '2025-06-01', 'end_date' => '2025-06-15', 'is_historical' => true],
        [['product_category' => ProductCategory::WaxTablet->value]],
    );
    $service->createWithCategories(
        ['name' => 'E2', 'type' => DemandEventType::PromoCampaign, 'start_date' => '2025-11-20', 'end_date' => '2025-12-01', 'is_historical' => true],
        [['product_category' => ProductCategory::StarterKit->value]],
    );

    $juneEvents = $service->forPeriod('2025-06-01', '2025-06-30');
    $novEvents = $service->forPeriod('2025-11-01', '2025-11-30');
    $allEvents = $service->forPeriod('2025-01-01', '2025-12-31');

    expect($juneEvents)->toHaveCount(1)
        ->and($novEvents)->toHaveCount(1)
        ->and($allEvents)->toHaveCount(2);
});

it('retrieves historical events for a category', function () {
    $service = app(DemandEventService::class);

    $service->createWithCategories(
        ['name' => 'BF', 'type' => DemandEventType::PromoCampaign, 'start_date' => '2025-11-20', 'end_date' => '2025-12-01', 'is_historical' => true],
        [
            ['product_category' => ProductCategory::StarterKit->value],
            ['product_category' => ProductCategory::WaxTablet->value],
        ],
    );
    $service->createWithCategories(
        ['name' => 'PWK Launch', 'type' => DemandEventType::ProductLaunch, 'start_date' => '2026-01-13', 'end_date' => '2026-03-15', 'is_historical' => true],
        [['product_category' => ProductCategory::WaxKit->value]],
    );

    $starterKitEvents = $service->historicalForCategory(ProductCategory::StarterKit);
    $waxKitEvents = $service->historicalForCategory(ProductCategory::WaxKit);
    $chainEvents = $service->historicalForCategory(ProductCategory::Chain);

    expect($starterKitEvents)->toHaveCount(1)
        ->and($waxKitEvents)->toHaveCount(1)
        ->and($chainEvents)->toHaveCount(0);
});

it('retrieves planned events for a year', function () {
    $service = app(DemandEventService::class);

    $service->createWithCategories(
        ['name' => 'Historical', 'type' => DemandEventType::PromoCampaign, 'start_date' => '2026-11-20', 'end_date' => '2026-12-01', 'is_historical' => true],
        [['product_category' => ProductCategory::StarterKit->value]],
    );
    $service->createWithCategories(
        ['name' => 'Planned BF', 'type' => DemandEventType::PromoCampaign, 'start_date' => '2026-11-20', 'end_date' => '2026-12-01', 'is_historical' => false],
        [['product_category' => ProductCategory::StarterKit->value, 'expected_uplift_units' => 200, 'pull_forward_pct' => 30]],
    );

    $planned = $service->plannedForYear(2026);

    expect($planned)->toHaveCount(1)
        ->and($planned->first()->name)->toBe('Planned BF')
        ->and($planned->first()->categories->first()->expected_uplift_units)->toBe(200)
        ->and((float) $planned->first()->categories->first()->pull_forward_pct)->toBe(30.0);
});

it('detects overlapping events for a category', function () {
    $service = app(DemandEventService::class);

    $service->createWithCategories(
        ['name' => 'BF', 'type' => DemandEventType::PromoCampaign, 'start_date' => '2025-11-20', 'end_date' => '2025-12-01', 'is_historical' => true],
        [['product_category' => ProductCategory::StarterKit->value]],
    );

    expect($service->overlapsWithCategory('2025-11-25', '2025-11-30', ProductCategory::StarterKit))->toBeTrue()
        ->and($service->overlapsWithCategory('2025-11-25', '2025-11-30', ProductCategory::Chain))->toBeFalse()
        ->and($service->overlapsWithCategory('2025-12-10', '2025-12-20', ProductCategory::StarterKit))->toBeFalse();
});
