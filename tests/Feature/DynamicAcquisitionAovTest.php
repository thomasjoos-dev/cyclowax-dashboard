<?php

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
use App\Services\Forecast\Demand\CohortProjectionService;
use App\Services\Forecast\Demand\DemandForecastService;
use App\Services\Forecast\Demand\QuarterlyAovCalculator;

it('calculates acquisition AOV per quarter from rolling actuals', function () {
    $waxProduct = Product::factory()->create(['product_category' => ProductCategory::WaxTablet->value]);

    // Create first orders with different AOV per quarter
    for ($i = 0; $i < 10; $i++) {
        // Q2: AOV = 150
        ShopifyOrder::factory()->create([
            'ordered_at' => '2025-05-15',
            'financial_status' => 'PAID',
            'is_first_order' => true,
            'net_revenue' => 150.00,
            'discounts' => 0,
            'customer_id' => ShopifyCustomer::factory()->create()->id,
        ]);

        // Q3: AOV = 120 (with discount 30)
        ShopifyOrder::factory()->create([
            'ordered_at' => '2025-08-15',
            'financial_status' => 'PAID',
            'is_first_order' => true,
            'net_revenue' => 120.00,
            'discounts' => 30.00,
            'customer_id' => ShopifyCustomer::factory()->create()->id,
        ]);
    }

    $service = app(QuarterlyAovCalculator::class);
    $result = $service->acqAovByQuarter(2025);

    // Q2: no discount → actual = normalized = 150
    expect($result['Q2']['actual'])->toBe(150.0)
        ->and($result['Q2']['normalized'])->toBe(150.0);

    // Q3: discount applied → actual = 120, normalized = 150
    expect($result['Q3']['actual'])->toBe(120.0)
        ->and($result['Q3']['normalized'])->toBe(150.0);
});

it('uses dynamic acquisition AOV in Q2-Q4 forecast', function () {
    $customer = ShopifyCustomer::factory()->create();
    $kitProduct = Product::factory()->create(['product_category' => ProductCategory::StarterKit->value]);
    $waxProduct = Product::factory()->create(['product_category' => ProductCategory::WaxTablet->value]);

    // 2025 baseline with consistent data
    for ($month = 1; $month <= 12; $month++) {
        $m = str_pad($month, 2, '0', STR_PAD_LEFT);
        $acqOrder = ShopifyOrder::factory()->create([
            'ordered_at' => "2025-{$m}-15",
            'financial_status' => 'PAID',
            'is_first_order' => true,
            'net_revenue' => 200,
            'discounts' => 0,
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
            'discounts' => 0,
            'customer_id' => $customer->id,
        ]);
        ShopifyLineItem::factory()->create([
            'order_id' => $repOrder->id,
            'product_id' => $waxProduct->id,
            'quantity' => 1,
            'price' => 30,
        ]);
    }

    // Q1 2026 actuals
    for ($month = 1; $month <= 3; $month++) {
        $m = str_pad($month, 2, '0', STR_PAD_LEFT);
        $acqOrder = ShopifyOrder::factory()->create([
            'ordered_at' => "2026-{$m}-15",
            'financial_status' => 'PAID',
            'is_first_order' => true,
            'net_revenue' => 220,
            'discounts' => 0,
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
            'acq_rate' => 1.20,
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

    $mock = Mockery::mock(CohortProjectionService::class);
    $mock->shouldReceive('retentionCurve')
        ->andReturn(['months' => [], 'cohorts_used' => 0, 'avg_cohort_size' => 0]);
    app()->instance(CohortProjectionService::class, $mock);

    $service = app(DemandForecastService::class);
    $forecast = $service->forecastYear($scenario->fresh(), 2026);

    // Q2+ should produce forecast data
    expect($forecast[4])->not->toBeEmpty();

    // Total forecast should be positive
    $totalRevenue = 0;
    for ($month = 4; $month <= 12; $month++) {
        $totalRevenue += collect($forecast[$month])->sum('revenue');
    }
    expect($totalRevenue)->toBeGreaterThan(0);
});
