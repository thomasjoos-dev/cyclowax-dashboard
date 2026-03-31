<?php

use App\Enums\DemandEventType;
use App\Enums\ForecastGroup;
use App\Enums\ProductCategory;
use App\Models\Product;
use App\Models\Scenario;
use App\Models\ScenarioAssumption;
use App\Models\ScenarioProductMix;
use App\Models\SeasonalIndex;
use App\Models\ShopifyCustomer;
use App\Models\ShopifyLineItem;
use App\Models\ShopifyOrder;
use App\Services\Forecast\DemandEventService;
use App\Services\Forecast\DemandForecastService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function setupForecastScenario(): Scenario
{
    // Create a customer
    $customer = ShopifyCustomer::factory()->create();

    // Create products for two categories
    $waxProduct = Product::factory()->create(['product_category' => ProductCategory::WaxTablet->value]);
    $kitProduct = Product::factory()->create(['product_category' => ProductCategory::StarterKit->value]);

    // Create orders for baseline year (2025) — every month
    for ($month = 1; $month <= 12; $month++) {
        $m = str_pad($month, 2, '0', STR_PAD_LEFT);

        // Acquisition orders
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

        // Repeat orders
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

    // Create Q1 2026 actuals
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

    // Create scenario with assumptions
    $scenario = Scenario::factory()->create(['year' => 2026]);
    foreach (['Q2', 'Q3', 'Q4'] as $quarter) {
        ScenarioAssumption::factory()->create([
            'scenario_id' => $scenario->id,
            'quarter' => $quarter,
            'acq_rate' => 1.20,
            'repeat_rate' => 0.25,
            'repeat_aov' => 85.00,
        ]);
    }

    // Create product mixes
    ScenarioProductMix::create([
        'scenario_id' => $scenario->id,
        'product_category' => ProductCategory::StarterKit->value,
        'acq_share' => 0.60,
        'repeat_share' => 0.10,
        'avg_unit_price' => 200.00,
    ]);
    ScenarioProductMix::create([
        'scenario_id' => $scenario->id,
        'product_category' => ProductCategory::WaxTablet->value,
        'acq_share' => 0.10,
        'repeat_share' => 0.50,
        'avg_unit_price' => 30.00,
    ]);

    // Create seasonal indices (flat = 1.0 for simplicity)
    foreach ([ProductCategory::StarterKit, ProductCategory::WaxTablet] as $cat) {
        for ($m = 1; $m <= 12; $m++) {
            SeasonalIndex::create([
                'month' => $m,
                'region' => null,
                'product_category' => $cat->value,
                'forecast_group' => null,
                'index_value' => 1.0,
                'source' => 'test',
            ]);
        }
    }

    // Create group indices as fallback
    foreach (ForecastGroup::cases() as $group) {
        for ($m = 1; $m <= 12; $m++) {
            SeasonalIndex::create([
                'month' => $m,
                'region' => null,
                'product_category' => null,
                'forecast_group' => $group->value,
                'index_value' => 1.0,
                'source' => 'test',
            ]);
        }
    }

    return $scenario;
}

it('generates a full year forecast', function () {
    $scenario = setupForecastScenario();
    $service = app(DemandForecastService::class);

    $forecast = $service->forecastYear($scenario, 2026);

    expect($forecast)->toHaveCount(12);

    // Q1 months should have data (based on actuals)
    expect($forecast[1])->not->toBeEmpty();
    expect($forecast[2])->not->toBeEmpty();
    expect($forecast[3])->not->toBeEmpty();

    // Q2+ months should have forecasted values
    expect($forecast[4])->not->toBeEmpty();

    // Check that starter_kit and wax_tablet are both present
    $aprilData = $forecast[4];
    expect($aprilData)->toHaveKey(ProductCategory::StarterKit->value)
        ->and($aprilData)->toHaveKey(ProductCategory::WaxTablet->value);

    // StarterKit should have more revenue (higher acq_share)
    expect($aprilData[ProductCategory::StarterKit->value]['revenue'])
        ->toBeGreaterThan($aprilData[ProductCategory::WaxTablet->value]['revenue']);
});

it('generates total forecast with year summary', function () {
    $scenario = setupForecastScenario();
    $service = app(DemandForecastService::class);

    $total = $service->totalForecast($scenario, 2026);

    expect($total)->toHaveKey('months')
        ->and($total)->toHaveKey('year_total')
        ->and($total['months'])->toHaveCount(12)
        ->and($total['year_total']['units'])->toBeGreaterThan(0)
        ->and($total['year_total']['revenue'])->toBeGreaterThan(0);

    // Sum of months should equal year total
    $monthlySum = collect($total['months'])->sum('revenue');
    expect($monthlySum)->toBe($total['year_total']['revenue']);
});

it('applies pull-forward only to Getting Started categories', function () {
    $scenario = setupForecastScenario();

    // Create a planned event with pull-forward
    $eventService = app(DemandEventService::class);
    $eventService->createWithCategories(
        [
            'name' => 'Planned BF',
            'type' => DemandEventType::PromoCampaign,
            'start_date' => '2026-11-20',
            'end_date' => '2026-11-30',
            'is_historical' => false,
        ],
        [
            [
                'product_category' => ProductCategory::StarterKit->value,
                'expected_uplift_units' => 100,
                'pull_forward_pct' => 30,
            ],
            [
                'product_category' => ProductCategory::WaxTablet->value,
                'expected_uplift_units' => 200,
                'pull_forward_pct' => 0,
            ],
        ],
    );

    $service = app(DemandForecastService::class);
    $forecast = $service->forecastYear($scenario, 2026);

    // November StarterKit should have event boost
    $novKit = $forecast[11][ProductCategory::StarterKit->value] ?? null;
    expect($novKit)->not->toBeNull()
        ->and($novKit['event_boost'])->toBeGreaterThan(0);

    // December StarterKit should have pull-forward deduction
    $decKit = $forecast[12][ProductCategory::StarterKit->value] ?? null;
    expect($decKit)->not->toBeNull()
        ->and($decKit['pull_forward'])->toBeGreaterThan(0);

    // December WaxTablet should NOT have pull-forward (not Getting Started)
    $decWax = $forecast[12][ProductCategory::WaxTablet->value] ?? null;
    expect($decWax)->not->toBeNull()
        ->and($decWax['pull_forward'])->toBe(0.0);
});

it('handles scenario without product mixes gracefully', function () {
    $scenario = Scenario::factory()->create(['year' => 2026]);
    ScenarioAssumption::factory()->create([
        'scenario_id' => $scenario->id,
        'quarter' => 'Q2',
        'acq_rate' => 1.0,
        'repeat_rate' => 0.20,
        'repeat_aov' => 80.00,
    ]);

    // No product mixes created

    $service = app(DemandForecastService::class);
    $forecast = $service->forecastYear($scenario, 2026);

    expect($forecast)->toHaveCount(12);
    // All months should be empty arrays (no product mixes)
    foreach ($forecast as $monthData) {
        expect($monthData)->toBeEmpty();
    }
});
