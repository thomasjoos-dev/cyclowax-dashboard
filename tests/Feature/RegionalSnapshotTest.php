<?php

use App\Enums\ForecastGroup;
use App\Enums\ForecastRegion;
use App\Enums\ProductCategory;
use App\Models\ForecastSnapshot;
use App\Models\Product;
use App\Models\Scenario;
use App\Models\ScenarioAssumption;
use App\Models\ScenarioProductMix;
use App\Models\SeasonalIndex;
use App\Models\ShopifyCustomer;
use App\Models\ShopifyLineItem;
use App\Models\ShopifyOrder;
use App\Services\Forecast\Demand\CohortProjectionService;
use App\Services\Forecast\Tracking\ForecastTrackingService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function setupSnapshotScenario(): Scenario
{
    $waxProduct = Product::factory()->create(['product_category' => ProductCategory::WaxTablet->value]);

    // DE orders 2025 + Q1 2026
    for ($month = 1; $month <= 12; $month++) {
        $m = str_pad($month, 2, '0', STR_PAD_LEFT);
        $order = ShopifyOrder::factory()->create([
            'ordered_at' => "2025-{$m}-15", 'financial_status' => 'PAID',
            'is_first_order' => true, 'net_revenue' => 100,
            'shipping_country_code' => 'DE', 'billing_country_code' => 'DE',
            'customer_id' => ShopifyCustomer::factory()->create()->id,
        ]);
        ShopifyLineItem::factory()->create(['order_id' => $order->id, 'product_id' => $waxProduct->id, 'quantity' => 1, 'price' => 100]);
    }

    for ($month = 1; $month <= 3; $month++) {
        $m = str_pad($month, 2, '0', STR_PAD_LEFT);
        $order = ShopifyOrder::factory()->create([
            'ordered_at' => "2026-{$m}-15", 'financial_status' => 'PAID',
            'is_first_order' => true, 'net_revenue' => 110,
            'shipping_country_code' => 'DE', 'billing_country_code' => 'DE',
            'customer_id' => ShopifyCustomer::factory()->create()->id,
        ]);
        ShopifyLineItem::factory()->create(['order_id' => $order->id, 'product_id' => $waxProduct->id, 'quantity' => 1, 'price' => 110]);
    }

    // Seasonal indices
    for ($m = 1; $m <= 12; $m++) {
        foreach ([null, 'de'] as $region) {
            SeasonalIndex::create(['month' => $m, 'region' => $region, 'product_category' => ProductCategory::WaxTablet->value, 'index_value' => 1.0, 'source' => 'test']);
        }
        foreach (ForecastGroup::cases() as $group) {
            SeasonalIndex::create(['month' => $m, 'region' => null, 'product_category' => null, 'forecast_group' => $group->value, 'index_value' => 1.0, 'source' => 'test']);
        }
    }

    $scenario = Scenario::factory()->create(['year' => 2026]);
    foreach (['Q2', 'Q3', 'Q4'] as $q) {
        ScenarioAssumption::factory()->create([
            'scenario_id' => $scenario->id, 'quarter' => $q,
            'acq_rate' => 1.0, 'repeat_rate' => 0.20, 'repeat_aov' => 80.00,
        ]);
    }

    ScenarioProductMix::create([
        'scenario_id' => $scenario->id,
        'product_category' => ProductCategory::WaxTablet->value,
        'acq_share' => 1.0, 'repeat_share' => 1.0, 'avg_unit_price' => 27.50,
    ]);

    return $scenario;
}

it('saves regional snapshots separately from global', function () {
    $scenario = setupSnapshotScenario();

    $mock = Mockery::mock(CohortProjectionService::class);
    $mock->shouldReceive('retentionCurve')->andReturn(['months' => [], 'cohorts_used' => 0, 'avg_cohort_size' => 0, 'source' => 'global']);
    app()->instance(CohortProjectionService::class, $mock);

    $service = app(ForecastTrackingService::class);

    // Save global
    $globalCount = $service->recordSnapshot($scenario, 2026);

    // Save DE regional
    $deCount = $service->recordSnapshot($scenario, 2026, ForecastRegion::De);

    expect($globalCount)->toBeGreaterThan(0)
        ->and($deCount)->toBeGreaterThan(0);

    // Global snapshots: region IS NULL
    $globalSnapshots = ForecastSnapshot::where('scenario_id', $scenario->id)
        ->whereNull('region')
        ->count();

    // DE snapshots: region = 'de'
    $deSnapshots = ForecastSnapshot::where('scenario_id', $scenario->id)
        ->where('region', 'de')
        ->count();

    expect($globalSnapshots)->toBeGreaterThan(0)
        ->and($deSnapshots)->toBeGreaterThan(0)
        ->and($globalSnapshots)->toBe($deSnapshots); // same structure
});

it('does not create duplicate snapshots on re-run (NULL-safe upsert)', function () {
    $scenario = setupSnapshotScenario();

    $mock = Mockery::mock(CohortProjectionService::class);
    $mock->shouldReceive('retentionCurve')->andReturn(['months' => [], 'cohorts_used' => 0, 'avg_cohort_size' => 0, 'source' => 'global']);
    app()->instance(CohortProjectionService::class, $mock);

    $service = app(ForecastTrackingService::class);

    // Run twice — should NOT create duplicates
    $service->recordSnapshot($scenario, 2026);
    $countAfterFirst = ForecastSnapshot::where('scenario_id', $scenario->id)->whereNull('region')->count();

    $service->recordSnapshot($scenario, 2026);
    $countAfterSecond = ForecastSnapshot::where('scenario_id', $scenario->id)->whereNull('region')->count();

    expect($countAfterSecond)->toBe($countAfterFirst);
});

it('does not create duplicate regional snapshots on re-run', function () {
    $scenario = setupSnapshotScenario();

    $mock = Mockery::mock(CohortProjectionService::class);
    $mock->shouldReceive('retentionCurve')->andReturn(['months' => [], 'cohorts_used' => 0, 'avg_cohort_size' => 0, 'source' => 'global']);
    app()->instance(CohortProjectionService::class, $mock);

    $service = app(ForecastTrackingService::class);

    $service->recordSnapshot($scenario, 2026, ForecastRegion::De);
    $countAfterFirst = ForecastSnapshot::where('scenario_id', $scenario->id)->where('region', 'de')->count();

    $service->recordSnapshot($scenario, 2026, ForecastRegion::De);
    $countAfterSecond = ForecastSnapshot::where('scenario_id', $scenario->id)->where('region', 'de')->count();

    expect($countAfterSecond)->toBe($countAfterFirst);
});

it('keeps global and regional snapshots independent', function () {
    $scenario = setupSnapshotScenario();

    $mock = Mockery::mock(CohortProjectionService::class);
    $mock->shouldReceive('retentionCurve')->andReturn(['months' => [], 'cohorts_used' => 0, 'avg_cohort_size' => 0, 'source' => 'global']);
    app()->instance(CohortProjectionService::class, $mock);

    $service = app(ForecastTrackingService::class);

    $service->recordSnapshot($scenario, 2026);
    $service->recordSnapshot($scenario, 2026, ForecastRegion::De);

    // Total rows = global + DE, no cross-contamination
    $totalRows = ForecastSnapshot::where('scenario_id', $scenario->id)->count();
    $globalRows = ForecastSnapshot::where('scenario_id', $scenario->id)->whereNull('region')->count();
    $deRows = ForecastSnapshot::where('scenario_id', $scenario->id)->where('region', 'de')->count();

    expect($totalRows)->toBe($globalRows + $deRows);
});
