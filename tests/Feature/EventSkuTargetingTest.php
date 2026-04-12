<?php

use App\Enums\DemandEventType;
use App\Enums\ProductCategory;
use App\Models\DemandEvent;
use App\Models\DemandEventCategory;
use App\Models\Product;
use App\Services\Forecast\Demand\DemandEventService;

it('extracts SKU earmarks from product-targeted events', function () {
    $product = Product::factory()->create([
        'product_category' => ProductCategory::WaxKit->value,
    ]);

    $event = DemandEvent::factory()->create([
        'name' => 'PWK Campaign',
        'type' => DemandEventType::PromoCampaign,
        'start_date' => '2026-06-01',
        'end_date' => '2026-08-31',
        'is_historical' => false,
    ]);

    DemandEventCategory::factory()->create([
        'demand_event_id' => $event->id,
        'product_category' => ProductCategory::WaxKit,
        'product_id' => $product->id,
        'expected_uplift_units' => 150,
        'pull_forward_pct' => 0,
    ]);

    $service = app(DemandEventService::class);
    $earmarks = $service->skuEarmarksForYear(2026);

    // 150 units across 3 months = 50 per month
    expect($earmarks)->toHaveKey(6);
    expect($earmarks)->toHaveKey(7);
    expect($earmarks)->toHaveKey(8);

    expect($earmarks[6][ProductCategory::WaxKit->value][$product->id])->toBe(50);
    expect($earmarks[7][ProductCategory::WaxKit->value][$product->id])->toBe(50);
    expect($earmarks[8][ProductCategory::WaxKit->value][$product->id])->toBe(50);
});

it('ignores category-level events without product_id', function () {
    $event = DemandEvent::factory()->create([
        'name' => 'Black Friday',
        'type' => DemandEventType::PromoCampaign,
        'start_date' => '2026-11-16',
        'end_date' => '2026-12-01',
        'is_historical' => false,
    ]);
    DemandEventCategory::factory()->create([
        'demand_event_id' => $event->id,
        'product_category' => ProductCategory::Chain,
        'expected_uplift_units' => 60,
        'pull_forward_pct' => 0,
    ]);

    $service = app(DemandEventService::class);
    $earmarks = $service->skuEarmarksForYear(2026);

    expect($earmarks)->toBeEmpty();
});

it('handles mixed category-level and product-targeted rows in one event', function () {
    $perfHeater = Product::factory()->create([
        'product_category' => ProductCategory::Heater->value,
    ]);

    $event = DemandEvent::factory()->create([
        'name' => 'Summer Campaign',
        'type' => DemandEventType::PromoCampaign,
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
        'is_historical' => false,
    ]);

    // Category-level chain boost (no product_id)
    DemandEventCategory::factory()->create([
        'demand_event_id' => $event->id,
        'product_category' => ProductCategory::Chain,
        'expected_uplift_units' => 60,
        'pull_forward_pct' => 0,
    ]);

    // Product-targeted heater boost
    DemandEventCategory::factory()->create([
        'demand_event_id' => $event->id,
        'product_category' => ProductCategory::Heater,
        'product_id' => $perfHeater->id,
        'expected_uplift_units' => 50,
        'pull_forward_pct' => 0,
    ]);

    $service = app(DemandEventService::class);
    $earmarks = $service->skuEarmarksForYear(2026);

    // Only the heater earmark should appear
    expect($earmarks[7])->toHaveKey(ProductCategory::Heater->value);
    expect($earmarks[7])->not->toHaveKey(ProductCategory::Chain->value);
    expect($earmarks[7][ProductCategory::Heater->value][$perfHeater->id])->toBe(50);
});

it('ignores historical events', function () {
    $product = Product::factory()->create([
        'product_category' => ProductCategory::WaxKit->value,
    ]);

    $event = DemandEvent::factory()->create([
        'name' => 'Past Launch',
        'type' => DemandEventType::ProductLaunch,
        'start_date' => '2026-01-13',
        'end_date' => '2026-03-15',
        'is_historical' => true,
    ]);
    DemandEventCategory::factory()->create([
        'demand_event_id' => $event->id,
        'product_category' => ProductCategory::WaxKit,
        'product_id' => $product->id,
        'expected_uplift_units' => 100,
        'pull_forward_pct' => 0,
    ]);

    $service = app(DemandEventService::class);
    $earmarks = $service->skuEarmarksForYear(2026);

    expect($earmarks)->toBeEmpty();
});

it('reports product targeting correctly on model', function () {
    $product = Product::factory()->create();

    $event = DemandEvent::factory()->create([
        'name' => 'Test Event',
        'type' => DemandEventType::PromoCampaign,
        'start_date' => '2026-06-01',
        'end_date' => '2026-06-30',
        'is_historical' => false,
    ]);

    $categoryLevel = DemandEventCategory::factory()->create([
        'demand_event_id' => $event->id,
        'product_category' => ProductCategory::Chain,
        'expected_uplift_units' => 60,
        'pull_forward_pct' => 0,
    ]);

    $productLevel = DemandEventCategory::factory()->create([
        'demand_event_id' => $event->id,
        'product_category' => ProductCategory::Heater,
        'product_id' => $product->id,
        'expected_uplift_units' => 50,
        'pull_forward_pct' => 0,
    ]);

    expect($categoryLevel->isProductTargeted())->toBeFalse();
    expect($productLevel->isProductTargeted())->toBeTrue();
    expect($productLevel->product->id)->toBe($product->id);
});
