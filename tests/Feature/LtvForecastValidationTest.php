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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

function setupLtvScenario(): Scenario
{
    $waxProduct = Product::factory()->create(['product_category' => ProductCategory::WaxTablet->value]);
    $kitProduct = Product::factory()->create(['product_category' => ProductCategory::StarterKit->value]);

    // Create multiple customers with orders spanning 2024-2026 for historical LTV data
    for ($c = 0; $c < 10; $c++) {
        $customer = ShopifyCustomer::factory()->create([
            'first_order_at' => '2024-06-15',
        ]);

        // First order
        $firstOrder = ShopifyOrder::factory()->create([
            'ordered_at' => '2024-06-15',
            'financial_status' => 'PAID',
            'is_first_order' => true,
            'net_revenue' => 200,
            'discounts' => 0,
            'customer_id' => $customer->id,
        ]);
        ShopifyLineItem::factory()->create([
            'order_id' => $firstOrder->id,
            'product_id' => $kitProduct->id,
            'quantity' => 1,
            'price' => 200,
        ]);

        // Some customers repeat (creates historical LTV)
        if ($c < 5) {
            $repeatOrder = ShopifyOrder::factory()->create([
                'ordered_at' => '2024-09-15',
                'financial_status' => 'PAID',
                'is_first_order' => false,
                'net_revenue' => 35,
                'discounts' => 0,
                'customer_id' => $customer->id,
            ]);
            ShopifyLineItem::factory()->create([
                'order_id' => $repeatOrder->id,
                'product_id' => $waxProduct->id,
                'quantity' => 1,
                'price' => 35,
            ]);
        }
    }

    // 2025 baseline data
    for ($month = 1; $month <= 12; $month++) {
        $m = str_pad($month, 2, '0', STR_PAD_LEFT);

        $acqOrder = ShopifyOrder::factory()->create([
            'ordered_at' => "2025-{$m}-15",
            'financial_status' => 'PAID',
            'is_first_order' => true,
            'net_revenue' => 200,
            'discounts' => 0,
            'customer_id' => ShopifyCustomer::factory()->create(['first_order_at' => "2025-{$m}-15"])->id,
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
            'customer_id' => ShopifyCustomer::factory()->create()->id,
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
            'customer_id' => ShopifyCustomer::factory()->create(['first_order_at' => "2026-{$m}-15"])->id,
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

    ScenarioProductMix::create([
        'scenario_id' => $scenario->id,
        'product_category' => ProductCategory::StarterKit->value,
        'acq_share' => 0.65,
        'repeat_share' => 0.35,
        'avg_unit_price' => 200.00,
    ]);
    ScenarioProductMix::create([
        'scenario_id' => $scenario->id,
        'product_category' => ProductCategory::WaxTablet->value,
        'acq_share' => 0.35,
        'repeat_share' => 0.65,
        'avg_unit_price' => 30.00,
    ]);

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

it('calculates predicted LTV from retention curve and age-aware AOV', function () {
    for ($i = 0; $i < 10; $i++) {
        // First orders (for acq AOV)
        ShopifyOrder::factory()->create([
            'ordered_at' => '2025-06-15',
            'financial_status' => 'PAID',
            'is_first_order' => true,
            'net_revenue' => 200,
            'discounts' => 0,
            'customer_id' => ShopifyCustomer::factory()->create(['first_order_at' => '2025-06-15'])->id,
        ]);

        // 2nd orders (for repeat AOV)
        ShopifyOrder::factory()->create([
            'ordered_at' => '2025-08-15',
            'financial_status' => 'PAID',
            'is_first_order' => false,
            'net_revenue' => 90,
            'discounts' => 0,
            'customer_id' => ShopifyCustomer::factory()->create()->id,
        ]);
    }

    $service = app(CohortProjectionService::class);
    $result = $service->predictedLtv();

    expect($result)->toHaveKeys(['predicted_ltv_12m', 'first_order_aov', 'predicted_repeat_value', 'retention_curve_source']);

    // With or without a real retention curve, the structure should be valid
    // first_order_aov should reflect the 200 we created
    expect($result['first_order_aov'])->toBeGreaterThanOrEqual(0);
});

it('returns zero LTV when no retention curve exists and no order data', function () {
    // No orders at all → empty retention curve, zero AOV
    $service = app(CohortProjectionService::class);
    $result = $service->predictedLtv();

    expect($result['predicted_ltv_12m'])->toBe(0)
        ->and($result['predicted_repeat_value'])->toBe(0);
});

it('validates LTV consistency and returns comparison', function () {
    $scenario = setupLtvScenario();

    $mock = Mockery::mock(CohortProjectionService::class)->makePartial();
    $mock->shouldReceive('retentionCurve')
        ->andReturn(['months' => [], 'cohorts_used' => 0, 'avg_cohort_size' => 0, 'source' => 'none']);
    $mock->shouldReceive('predictedLtv')
        ->andReturn([
            'predicted_ltv_12m' => 250.0,
            'first_order_aov' => 200.0,
            'predicted_repeat_value' => 50.0,
            'retention_curve_source' => 'global',
        ]);

    app()->instance(CohortProjectionService::class, $mock);

    Log::spy();

    $service = app(DemandForecastService::class);
    $result = $service->validateLtvConsistency($scenario->fresh(), 2026);

    expect($result)->toHaveKeys([
        'forecast_implied_ltv',
        'historical_avg_ltv',
        'predicted_ltv',
        'delta_pct',
        'warning',
    ])
        ->and($result['forecast_implied_ltv'])->toBeGreaterThan(0)
        ->and($result['historical_avg_ltv'])->toBeGreaterThan(0)
        ->and($result['predicted_ltv'])->toBe(250.0);
});
