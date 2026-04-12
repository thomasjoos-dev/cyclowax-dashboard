<?php

use App\Enums\ForecastRegion;
use App\Enums\ProductCategory;
use App\Models\Product;
use App\Models\ShopifyCustomer;
use App\Models\ShopifyLineItem;
use App\Models\ShopifyOrder;
use App\Services\Forecast\Demand\RegionalCostService;

function createCostOrder(string $countryCode, float $netRevenue, float $shippingCost, Product $product, int $qty = 1): void
{
    $order = ShopifyOrder::factory()->create([
        'ordered_at' => '2025-06-15',
        'financial_status' => 'PAID',
        'shipping_country_code' => $countryCode,
        'billing_country_code' => $countryCode,
        'net_revenue' => $netRevenue,
        'shipping_cost' => $shippingCost,
        'customer_id' => ShopifyCustomer::factory()->create()->id,
    ]);

    ShopifyLineItem::factory()->create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'quantity' => $qty,
        'price' => $netRevenue / $qty,
        'cost_price' => $product->cost_price,
    ]);
}

it('uses current Odoo cost_price for COGS per category', function () {
    $wax = Product::factory()->create([
        'product_category' => ProductCategory::WaxTablet->value,
        'cost_price' => 8.50,
    ]);
    $kit = Product::factory()->create([
        'product_category' => ProductCategory::StarterKit->value,
        'cost_price' => 45.00,
    ]);

    // Create orders to provide sales mix weighting
    createCostOrder('DE', 27.50, 5.00, $wax, 1);
    createCostOrder('DE', 220.00, 5.00, $kit, 1);

    $service = app(RegionalCostService::class);
    $profile = $service->costProfile(ForecastRegion::De);

    // COGS comes from products.cost_price, not historical line item costs
    expect($profile['cogs_per_unit'][ProductCategory::WaxTablet->value])->toBe(8.50)
        ->and($profile['cogs_per_unit'][ProductCategory::StarterKit->value])->toBe(45.0);
});

it('calculates regional shipping cost differences', function () {
    $product = Product::factory()->create([
        'product_category' => ProductCategory::WaxTablet->value,
        'cost_price' => 8.50,
    ]);

    // DE: low shipping
    createCostOrder('DE', 120, 5.50, $product, 2);
    createCostOrder('DE', 200, 5.50, $product, 3);

    // GB: high shipping (customs)
    createCostOrder('GB', 160, 25.00, $product, 2);

    $service = app(RegionalCostService::class);

    $deProfile = $service->costProfile(ForecastRegion::De);
    $gbProfile = $service->costProfile(ForecastRegion::Gb);

    expect($deProfile['avg_shipping_per_order'])->toBe(5.50)
        ->and($gbProfile['avg_shipping_per_order'])->toBe(25.00)
        ->and($deProfile['orders_analysed'])->toBe(2)
        ->and($gbProfile['orders_analysed'])->toBe(1);
});

it('calculates CM1 from forecasted data', function () {
    $product = Product::factory()->create([
        'product_category' => ProductCategory::WaxTablet->value,
        'cost_price' => 8.50,
    ]);

    createCostOrder('DE', 27.50, 5.50, $product, 1);

    $service = app(RegionalCostService::class);

    $cm1 = $service->calculateCm1([
        ProductCategory::WaxTablet->value => ['units' => 100, 'revenue' => 2750.0],
    ], ForecastRegion::De);

    expect($cm1)->toHaveKeys(['net_revenue', 'cogs', 'shipping_cost', 'payment_fee', 'cm1', 'cm1_pct'])
        ->and($cm1['net_revenue'])->toBe(2750.0)
        ->and($cm1['cogs'])->toBe(850.0) // 100 units × €8.50
        ->and($cm1['shipping_cost'])->toBeGreaterThan(0.0)
        ->and($cm1['cm1'])->toBeLessThan($cm1['net_revenue'])
        ->and($cm1['cm1_pct'])->toBeGreaterThan(0.0);
});

it('COGS is region-independent (same product costs everywhere)', function () {
    $product = Product::factory()->create([
        'product_category' => ProductCategory::WaxTablet->value,
        'cost_price' => 8.50,
    ]);

    createCostOrder('DE', 27.50, 5.00, $product);
    createCostOrder('GB', 27.50, 25.00, $product);

    $service = app(RegionalCostService::class);

    $deProfile = $service->costProfile(ForecastRegion::De);
    $gbProfile = $service->costProfile(ForecastRegion::Gb);

    // Same COGS regardless of region
    expect($deProfile['cogs_per_unit'][ProductCategory::WaxTablet->value])
        ->toBe($gbProfile['cogs_per_unit'][ProductCategory::WaxTablet->value]);
});

it('returns global cost profile when no region specified', function () {
    $product = Product::factory()->create([
        'product_category' => ProductCategory::WaxTablet->value,
        'cost_price' => 8.50,
    ]);

    createCostOrder('DE', 100, 5.00, $product);
    createCostOrder('GB', 100, 25.00, $product);

    $service = app(RegionalCostService::class);

    $globalProfile = $service->costProfile();

    expect($globalProfile['orders_analysed'])->toBe(2)
        ->and($globalProfile['avg_shipping_per_order'])->toBe(15.00); // (5 + 25) / 2
});

it('handles region with no shipping data gracefully', function () {
    $service = app(RegionalCostService::class);

    $cm1 = $service->calculateCm1([
        ProductCategory::WaxTablet->value => ['units' => 50, 'revenue' => 1000.0],
    ], ForecastRegion::Row);

    // No historical data → COGS = 0, shipping = 0, only payment fee
    expect($cm1['cogs'])->toBe(0.0)
        ->and($cm1['shipping_cost'])->toBe(0.0)
        ->and($cm1['cm1'])->toBeLessThanOrEqual($cm1['net_revenue']);
});
