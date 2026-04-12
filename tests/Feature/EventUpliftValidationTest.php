<?php

use App\Enums\DemandEventType;
use App\Enums\ForecastGroup;
use App\Enums\ProductCategory;
use App\Models\DemandEventCategory;
use App\Models\Product;
use App\Models\Scenario;
use App\Models\ScenarioAssumption;
use App\Models\ScenarioProductMix;
use App\Models\SeasonalIndex;
use App\Models\ShopifyCustomer;
use App\Models\ShopifyLineItem;
use App\Models\ShopifyOrder;
use App\Services\Forecast\Demand\CohortProjectionService;
use App\Services\Forecast\Demand\DemandEventService;
use App\Services\Forecast\Demand\DemandForecastService;
use Illuminate\Support\Facades\Log;

function setupEventUpliftScenario(): Scenario
{
    $customer = ShopifyCustomer::factory()->create();
    $waxProduct = Product::factory()->create(['product_category' => ProductCategory::WaxTablet->value]);
    $kitProduct = Product::factory()->create(['product_category' => ProductCategory::StarterKit->value]);

    for ($month = 1; $month <= 12; $month++) {
        $m = str_pad($month, 2, '0', STR_PAD_LEFT);

        $acqOrder = ShopifyOrder::factory()->create([
            'ordered_at' => "2025-{$m}-15",
            'financial_status' => 'PAID',
            'is_first_order' => true,
            'net_revenue' => 200,
            'customer_id' => ShopifyCustomer::factory()->create()->id,
        ]);
        ShopifyLineItem::factory()->create([
            'order_id' => $acqOrder->id,
            'product_id' => $kitProduct->id,
            'quantity' => 1,
            'price' => 200,
        ]);

        $repOrder = ShopifyOrder::factory()->create([
            'ordered_at' => "2025-{$m}-20",
            'financial_status' => 'PAID',
            'is_first_order' => false,
            'net_revenue' => 30,
            'customer_id' => $customer->id,
        ]);
        ShopifyLineItem::factory()->create([
            'order_id' => $repOrder->id,
            'product_id' => $waxProduct->id,
            'quantity' => 1,
            'price' => 30,
        ]);
    }

    for ($month = 1; $month <= 3; $month++) {
        $m = str_pad($month, 2, '0', STR_PAD_LEFT);
        $acqOrder = ShopifyOrder::factory()->create([
            'ordered_at' => "2026-{$m}-15",
            'financial_status' => 'PAID',
            'is_first_order' => true,
            'net_revenue' => 220,
            'customer_id' => ShopifyCustomer::factory()->create()->id,
        ]);
        ShopifyLineItem::factory()->create([
            'order_id' => $acqOrder->id,
            'product_id' => $kitProduct->id,
            'quantity' => 1,
            'price' => 220,
        ]);
    }

    $scenario = Scenario::factory()->create(['year' => 2026]);
    foreach (['Q2', 'Q3', 'Q4'] as $quarter) {
        ScenarioAssumption::factory()->create([
            'scenario_id' => $scenario->id,
            'quarter' => $quarter,
            'acq_rate' => 1.00,
            'repeat_rate' => 0.25,
            'repeat_aov' => 85.00,
        ]);
    }

    ScenarioProductMix::factory()->starterKit()->create(['scenario_id' => $scenario->id]);
    ScenarioProductMix::factory()->waxTablet()->create(['scenario_id' => $scenario->id]);

    foreach ([ProductCategory::StarterKit, ProductCategory::WaxTablet] as $cat) {
        for ($m = 1; $m <= 12; $m++) {
            SeasonalIndex::factory()->flat()->forCategory($cat->value)->create(['month' => $m]);
        }
    }

    foreach (ForecastGroup::cases() as $group) {
        for ($m = 1; $m <= 12; $m++) {
            SeasonalIndex::factory()->flat()->forGroup($group->value)->create(['month' => $m]);
        }
    }

    return $scenario;
}

it('logs warning when event uplift exceeds 50% of seasonal baseline', function () {
    $scenario = setupEventUpliftScenario();

    // Create event with very high uplift (will exceed 50% of baseline)
    $eventService = app(DemandEventService::class);
    $eventService->createWithCategories(
        [
            'name' => 'Massive Promo',
            'type' => DemandEventType::PromoCampaign,
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-30',
            'is_historical' => false,
        ],
        [
            [
                'product_category' => ProductCategory::StarterKit->value,
                'expected_uplift_units' => 500,
                'pull_forward_pct' => 0,
            ],
        ],
    );

    $mock = Mockery::mock(CohortProjectionService::class);
    $mock->shouldReceive('retentionCurve')
        ->andReturn(['months' => [], 'cohorts_used' => 0, 'avg_cohort_size' => 0]);
    app()->instance(CohortProjectionService::class, $mock);

    Log::shouldReceive('warning')
        ->atLeast()->once()
        ->withArgs(fn (string $msg) => str_contains($msg, 'Event uplift may include organic'));

    Log::shouldReceive('warning')
        ->zeroOrMoreTimes()
        ->withArgs(fn (string $msg) => ! str_contains($msg, 'Event uplift may include organic'));

    $service = app(DemandForecastService::class);
    $service->forecastYear($scenario->fresh(), 2026);
});

it('does not log warning when event uplift is small relative to baseline', function () {
    $scenario = setupEventUpliftScenario();

    // Create event with small uplift
    $eventService = app(DemandEventService::class);
    $eventService->createWithCategories(
        [
            'name' => 'Small Promo',
            'type' => DemandEventType::PromoCampaign,
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-30',
            'is_historical' => false,
        ],
        [
            [
                'product_category' => ProductCategory::WaxTablet->value,
                'expected_uplift_units' => 1,
                'pull_forward_pct' => 0,
            ],
        ],
    );

    $mock = Mockery::mock(CohortProjectionService::class);
    $mock->shouldReceive('retentionCurve')
        ->andReturn(['months' => [], 'cohorts_used' => 0, 'avg_cohort_size' => 0]);
    app()->instance(CohortProjectionService::class, $mock);

    Log::shouldReceive('warning')
        ->zeroOrMoreTimes()
        ->withArgs(fn (string $msg) => ! str_contains($msg, 'Event uplift may include organic'));

    $service = app(DemandForecastService::class);
    $service->forecastYear($scenario->fresh(), 2026);
});

it('persists and reads is_incremental flag on demand event categories', function () {
    $eventService = app(DemandEventService::class);
    $event = $eventService->createWithCategories(
        [
            'name' => 'Test Event',
            'type' => DemandEventType::PromoCampaign,
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-30',
            'is_historical' => false,
        ],
        [
            [
                'product_category' => ProductCategory::StarterKit->value,
                'expected_uplift_units' => 100,
                'pull_forward_pct' => 0,
            ],
        ],
    );

    // Default should be true
    $category = DemandEventCategory::first();
    expect($category->is_incremental)->toBeTrue();

    // Update to false
    $category->update(['is_incremental' => false]);
    $category->refresh();
    expect($category->is_incremental)->toBeFalse();
});
