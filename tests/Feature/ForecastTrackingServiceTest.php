<?php

use App\Enums\ForecastGroup;
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
use App\Services\Forecast\Tracking\ForecastTrackingService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function setupTrackingScenario(): Scenario
{
    $customer = ShopifyCustomer::factory()->create();
    $waxProduct = Product::factory()->create(['product_category' => ProductCategory::WaxTablet->value]);
    $kitProduct = Product::factory()->create(['product_category' => ProductCategory::StarterKit->value]);

    // Baseline 2025 orders
    for ($month = 1; $month <= 12; $month++) {
        $m = str_pad($month, 2, '0', STR_PAD_LEFT);
        $order = ShopifyOrder::factory()->create([
            'ordered_at' => "2025-{$m}-15", 'financial_status' => 'PAID',
            'is_first_order' => true, 'net_revenue' => 200,
            'customer_id' => ShopifyCustomer::factory()->create()->id,
        ]);
        ShopifyLineItem::factory()->create(['order_id' => $order->id, 'product_id' => $kitProduct->id, 'quantity' => 1, 'price' => 200]);

        $repOrder = ShopifyOrder::factory()->create([
            'ordered_at' => "2025-{$m}-20", 'financial_status' => 'PAID',
            'is_first_order' => false, 'net_revenue' => 30, 'customer_id' => $customer->id,
        ]);
        ShopifyLineItem::factory()->create(['order_id' => $repOrder->id, 'product_id' => $waxProduct->id, 'quantity' => 1, 'price' => 30]);
    }

    // Q1 2026 actuals
    for ($month = 1; $month <= 3; $month++) {
        $m = str_pad($month, 2, '0', STR_PAD_LEFT);
        $order = ShopifyOrder::factory()->create([
            'ordered_at' => "2026-{$m}-15", 'financial_status' => 'PAID',
            'is_first_order' => true, 'net_revenue' => 220,
            'customer_id' => ShopifyCustomer::factory()->create()->id,
        ]);
        ShopifyLineItem::factory()->create(['order_id' => $order->id, 'product_id' => $kitProduct->id, 'quantity' => 1, 'price' => 220]);

        $repOrder = ShopifyOrder::factory()->create([
            'ordered_at' => "2026-{$m}-20", 'financial_status' => 'PAID',
            'is_first_order' => false, 'net_revenue' => 35, 'customer_id' => $customer->id,
        ]);
        ShopifyLineItem::factory()->create(['order_id' => $repOrder->id, 'product_id' => $waxProduct->id, 'quantity' => 1, 'price' => 35]);
    }

    $scenario = Scenario::factory()->create(['year' => 2026]);
    foreach (['Q2', 'Q3', 'Q4'] as $q) {
        ScenarioAssumption::factory()->create([
            'scenario_id' => $scenario->id, 'quarter' => $q,
            'acq_rate' => 1.0, 'repeat_rate' => 0.20, 'repeat_aov' => 80.00,
        ]);
    }

    ScenarioProductMix::create(['scenario_id' => $scenario->id, 'product_category' => ProductCategory::StarterKit->value, 'acq_share' => 0.65, 'repeat_share' => 0.35, 'avg_unit_price' => 200.00]);
    ScenarioProductMix::create(['scenario_id' => $scenario->id, 'product_category' => ProductCategory::WaxTablet->value, 'acq_share' => 0.35, 'repeat_share' => 0.65, 'avg_unit_price' => 30.00]);

    foreach ([ProductCategory::StarterKit, ProductCategory::WaxTablet] as $cat) {
        for ($m = 1; $m <= 12; $m++) {
            SeasonalIndex::create(['month' => $m, 'region' => null, 'product_category' => $cat->value, 'forecast_group' => null, 'index_value' => 1.0, 'source' => 'test']);
        }
    }
    foreach (ForecastGroup::cases() as $group) {
        for ($m = 1; $m <= 12; $m++) {
            SeasonalIndex::create(['month' => $m, 'region' => null, 'product_category' => null, 'forecast_group' => $group->value, 'index_value' => 1.0, 'source' => 'test']);
        }
    }

    return $scenario;
}

it('records forecast snapshots', function () {
    $scenario = setupTrackingScenario();
    $service = app(ForecastTrackingService::class);

    $count = $service->recordSnapshot($scenario, 2026);

    expect($count)->toBeGreaterThan(0);

    // Should have 12 total rows (one per month) + category rows
    $totals = ForecastSnapshot::where('scenario_id', $scenario->id)->totals()->count();
    expect($totals)->toBe(12);

    $withCategory = ForecastSnapshot::where('scenario_id', $scenario->id)->whereNotNull('product_category')->count();
    expect($withCategory)->toBeGreaterThan(0);
});

it('updates actuals for a completed month', function () {
    $scenario = setupTrackingScenario();
    $service = app(ForecastTrackingService::class);

    $service->recordSnapshot($scenario, 2026);
    $updated = $service->updateActuals('2026-01');

    expect($updated)->toBeGreaterThan(0);

    $janTotal = ForecastSnapshot::where('scenario_id', $scenario->id)
        ->forMonth('2026-01')
        ->totals()
        ->first();

    expect($janTotal->actual_units)->not->toBeNull()
        ->and($janTotal->actual_revenue)->not->toBeNull();
});

it('calculates monthly variance', function () {
    $scenario = setupTrackingScenario();
    $service = app(ForecastTrackingService::class);

    $service->recordSnapshot($scenario, 2026);
    $service->updateActuals('2026-01');

    $variance = $service->monthlyVariance($scenario, 2026);

    expect($variance)->toHaveKey('2026-01')
        ->and($variance['2026-01']['variance_pct'])->toBeFloat()
        ->and($variance['2026-01']['actual_revenue'])->not->toBeNull();

    // Months without actuals should have null variance
    expect($variance['2026-06']['variance_pct'])->toBeNull();
});

it('calculates pace projection', function () {
    $scenario = setupTrackingScenario();
    $service = app(ForecastTrackingService::class);

    $service->recordSnapshot($scenario, 2026);
    $service->updateActuals('2026-01');
    $service->updateActuals('2026-02');

    $pace = $service->paceProjection($scenario, '2026-02');

    expect($pace)->toHaveKey('pace_factor')
        ->and($pace)->toHaveKey('projected_year')
        ->and($pace)->toHaveKey('original_year')
        ->and($pace['pace_factor'])->toBeGreaterThan(0)
        ->and($pace['projected_year'])->toBeGreaterThan(0);
});
