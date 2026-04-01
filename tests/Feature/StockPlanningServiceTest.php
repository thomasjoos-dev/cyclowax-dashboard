<?php

use App\Enums\ForecastGroup;
use App\Enums\ProductCategory;
use App\Models\Product;
use App\Models\ProductStockSnapshot;
use App\Models\Scenario;
use App\Models\ScenarioAssumption;
use App\Models\ScenarioProductMix;
use App\Models\SeasonalIndex;
use App\Models\ShopifyCustomer;
use App\Models\ShopifyLineItem;
use App\Models\ShopifyOrder;
use App\Models\SupplyProfile;
use App\Services\Forecast\StockPlanningService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function setupStockPlanningScenario(): Scenario
{
    $waxProduct = Product::factory()->create([
        'product_category' => ProductCategory::WaxTablet->value,
        'sku' => 'WX-TEST',
    ]);

    // Low stock: 200 units — will deplete within a few months at realistic demand
    ProductStockSnapshot::factory()->create([
        'product_id' => $waxProduct->id,
        'qty_free' => 200,
        'qty_on_hand' => 200,
        'qty_forecasted' => 200,
        'recorded_at' => now(),
    ]);

    SupplyProfile::create([
        'product_category' => ProductCategory::WaxTablet->value,
        'procurement_lead_time_days' => 45,
        'moq' => 500,
        'buffer_days' => 14,
    ]);

    // Realistic baseline 2025: 10 orders/month with significant revenue
    // This creates enough baseline for the forecast to project meaningful demand
    for ($month = 1; $month <= 12; $month++) {
        $m = str_pad($month, 2, '0', STR_PAD_LEFT);

        for ($i = 0; $i < 10; $i++) {
            // Acquisition orders: high-value kit purchases
            $acqOrder = ShopifyOrder::factory()->create([
                'ordered_at' => "2025-{$m}-".str_pad($i + 1, 2, '0', STR_PAD_LEFT),
                'financial_status' => 'PAID',
                'is_first_order' => true,
                'net_revenue' => 2000,
                'customer_id' => ShopifyCustomer::factory()->create()->id,
            ]);
            ShopifyLineItem::factory()->create([
                'order_id' => $acqOrder->id,
                'product_id' => $waxProduct->id,
                'quantity' => 20,
                'price' => 25,
            ]);
        }

        for ($i = 0; $i < 10; $i++) {
            // Repeat orders: wax tablet refills
            $repOrder = ShopifyOrder::factory()->create([
                'ordered_at' => "2025-{$m}-".str_pad($i + 15, 2, '0', STR_PAD_LEFT),
                'financial_status' => 'PAID',
                'is_first_order' => false,
                'net_revenue' => 500,
                'customer_id' => ShopifyCustomer::factory()->create()->id,
            ]);
            ShopifyLineItem::factory()->create([
                'order_id' => $repOrder->id,
                'product_id' => $waxProduct->id,
                'quantity' => 10,
                'price' => 25,
            ]);
        }
    }

    // Q1 2026 actuals
    for ($month = 1; $month <= 3; $month++) {
        $m = str_pad($month, 2, '0', STR_PAD_LEFT);

        for ($i = 0; $i < 10; $i++) {
            $order = ShopifyOrder::factory()->create([
                'ordered_at' => "2026-{$m}-".str_pad($i + 1, 2, '0', STR_PAD_LEFT),
                'financial_status' => 'PAID',
                'is_first_order' => true,
                'net_revenue' => 2000,
                'customer_id' => ShopifyCustomer::factory()->create()->id,
            ]);
            ShopifyLineItem::factory()->create([
                'order_id' => $order->id,
                'product_id' => $waxProduct->id,
                'quantity' => 20,
                'price' => 25,
            ]);
        }
    }

    $scenario = Scenario::factory()->create(['year' => 2026]);
    foreach (['Q2', 'Q3', 'Q4'] as $q) {
        ScenarioAssumption::factory()->create([
            'scenario_id' => $scenario->id,
            'quarter' => $q,
            'acq_rate' => 1.0,
            'repeat_rate' => 0.25,
            'repeat_aov' => 80.00,
        ]);
    }

    // Single category: shares must be ~1.0 each (only category in scenario)
    ScenarioProductMix::create([
        'scenario_id' => $scenario->id,
        'product_category' => ProductCategory::WaxTablet->value,
        'acq_share' => 1.00,
        'repeat_share' => 1.00,
        'avg_unit_price' => 25.00,
    ]);

    // Seasonal indices
    for ($m = 1; $m <= 12; $m++) {
        SeasonalIndex::create([
            'month' => $m, 'region' => null,
            'product_category' => ProductCategory::WaxTablet->value,
            'forecast_group' => null, 'index_value' => 1.0, 'source' => 'test',
        ]);
    }
    foreach (ForecastGroup::cases() as $group) {
        for ($m = 1; $m <= 12; $m++) {
            SeasonalIndex::create([
                'month' => $m, 'region' => null, 'product_category' => null,
                'forecast_group' => $group->value, 'index_value' => 1.0, 'source' => 'test',
            ]);
        }
    }

    return $scenario;
}

it('generates purchase orders when demand exceeds stock', function () {
    $scenario = setupStockPlanningScenario();
    $service = app(StockPlanningService::class);

    $schedule = $service->purchaseSchedule($scenario, 2026);

    expect($schedule)->not->toBeEmpty()
        ->and($schedule)->toHaveKey(ProductCategory::WaxTablet->value);

    $orders = $schedule[ProductCategory::WaxTablet->value];
    expect($orders)->not->toBeEmpty();

    foreach ($orders as $order) {
        expect($order)->toHaveKeys(['order_date', 'order_quantity', 'reason', 'stock_at_order', 'demand_until_next'])
            ->and($order['order_quantity'])->toBeGreaterThanOrEqual(1);
    }
});

it('respects MOQ in order quantities', function () {
    $scenario = setupStockPlanningScenario();
    $service = app(StockPlanningService::class);

    $schedule = $service->purchaseSchedule($scenario, 2026);
    $moq = 500;

    expect($schedule)->not->toBeEmpty();

    foreach ($schedule as $orders) {
        foreach ($orders as $order) {
            expect($order['order_quantity'])->toBeGreaterThanOrEqual($moq)
                ->and($order['order_quantity'] % $moq)->toBe(0);
        }
    }
});

it('calculates category runway with depletion', function () {
    $scenario = setupStockPlanningScenario();
    $service = app(StockPlanningService::class);

    $runway = $service->categoryRunway(ProductCategory::WaxTablet, $scenario, 2026);

    expect($runway)->toHaveKeys(['current_stock', 'monthly_demand', 'depletion_month', 'runway_days'])
        ->and($runway['current_stock'])->toBe(200)
        ->and($runway['monthly_demand'])->toHaveCount(12)
        ->and($runway['depletion_month'])->not->toBeNull()
        ->and($runway['runway_days'])->toBeGreaterThan(0);

    // With 200 stock and high monthly demand, depletion should happen early
    expect($runway['depletion_month'])->toBeLessThanOrEqual(6);
});

it('generates chronological reorder timeline', function () {
    $scenario = setupStockPlanningScenario();
    $service = app(StockPlanningService::class);

    $timeline = $service->reorderTimeline($scenario, 2026);

    expect($timeline)->not->toBeEmpty();

    // Should be sorted by order_date
    $dates = array_column($timeline, 'order_date');
    $sorted = $dates;
    sort($sorted);
    expect($dates)->toBe($sorted);

    foreach ($timeline as $entry) {
        expect($entry)->toHaveKeys(['order_date', 'delivery_month', 'category', 'quantity', 'reason']);
    }
});
