<?php

use App\Enums\ProductCategory;
use App\Models\Product;
use App\Models\Scenario;
use App\Models\ScenarioProductMix;
use App\Models\ShopifyCustomer;
use App\Models\ShopifyLineItem;
use App\Models\ShopifyOrder;
use App\Services\Forecast\SkuMixService;

function createSkuOverride(Scenario $scenario, ProductCategory $category, int $productId, float $skuShare): ScenarioProductMix
{
    return ScenarioProductMix::create([
        'scenario_id' => $scenario->id,
        'product_category' => $category->value,
        'product_id' => $productId,
        'sku_share' => $skuShare,
        'acq_share' => 0,
        'repeat_share' => 0,
        'avg_unit_price' => 0,
    ]);
}

function setupSkuMixProducts(): array
{
    $productA = Product::factory()->create([
        'product_category' => ProductCategory::Chain->value,
        'name' => 'Classic Chain',
        'is_active' => true,
    ]);
    $productB = Product::factory()->create([
        'product_category' => ProductCategory::Chain->value,
        'name' => 'Racing Chain',
        'is_active' => true,
    ]);
    $productC = Product::factory()->create([
        'product_category' => ProductCategory::Chain->value,
        'name' => 'Pro Chain',
        'is_active' => true,
    ]);

    return [$productA, $productB, $productC];
}

function seedHistoricalSales(array $products, array $quantities): void
{
    $customer = ShopifyCustomer::factory()->create();

    foreach ($products as $i => $product) {
        $qty = $quantities[$i] ?? 0;

        if ($qty <= 0) {
            continue;
        }

        $order = ShopifyOrder::factory()->create([
            'ordered_at' => now()->subMonths(3)->toDateString(),
            'financial_status' => 'PAID',
            'customer_id' => $customer->id,
        ]);

        ShopifyLineItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'quantity' => $qty,
            'price' => 90,
        ]);
    }
}

it('distributes units using historical mix when no scenario is given', function () {
    [$a, $b, $c] = setupSkuMixProducts();
    seedHistoricalSales([$a, $b, $c], [100, 60, 40]); // 50%, 30%, 20%

    $service = app(SkuMixService::class);
    $result = $service->distribute(ProductCategory::Chain, 200);

    expect($result[$a->id])->toBe(100);
    expect($result[$b->id])->toBe(60);
    expect($result[$c->id])->toBe(40);
});

it('uses scenario SKU overrides when available', function () {
    [$a, $b, $c] = setupSkuMixProducts();
    seedHistoricalSales([$a, $b, $c], [100, 60, 40]); // historisch: 50%, 30%, 20%

    $scenario = Scenario::factory()->create(['year' => 2026]);

    // Override: push Pro Chain to 60%, Classic 30%, Racing 10%
    createSkuOverride($scenario, ProductCategory::Chain, $c->id, 0.6000);
    createSkuOverride($scenario, ProductCategory::Chain, $a->id, 0.3000);
    createSkuOverride($scenario, ProductCategory::Chain, $b->id, 0.1000);

    $service = app(SkuMixService::class);
    $result = $service->distribute(ProductCategory::Chain, 200, scenario: $scenario);

    expect($result[$c->id])->toBe(120); // 60%
    expect($result[$a->id])->toBe(60);  // 30%
    expect($result[$b->id])->toBe(20);  // 10%
});

it('falls back to historical mix when scenario has no SKU overrides', function () {
    [$a, $b, $c] = setupSkuMixProducts();
    seedHistoricalSales([$a, $b, $c], [100, 60, 40]);

    $scenario = Scenario::factory()->create(['year' => 2026]);

    // Only category-level mix, no product_id
    ScenarioProductMix::create([
        'scenario_id' => $scenario->id,
        'product_category' => ProductCategory::Chain->value,
        'acq_share' => 0.20,
        'repeat_share' => 0.40,
        'avg_unit_price' => 90.00,
    ]);

    $service = app(SkuMixService::class);
    $result = $service->distribute(ProductCategory::Chain, 200, scenario: $scenario);

    // Should use historical: 50%, 30%, 20%
    expect($result[$a->id])->toBe(100);
    expect($result[$b->id])->toBe(60);
    expect($result[$c->id])->toBe(40);
});

it('earmarks units for specific products', function () {
    [$a, $b, $c] = setupSkuMixProducts();
    seedHistoricalSales([$a, $b, $c], [100, 60, 40]); // 50%, 30%, 20%

    $service = app(SkuMixService::class);
    $result = $service->distribute(
        ProductCategory::Chain,
        300,
        earmarkedUnits: [$c->id => 100],
    );

    // 100 earmarked for Pro Chain, 200 remaining distributed: 100, 60, 40
    expect($result[$a->id])->toBe(100); // 50% of 200
    expect($result[$b->id])->toBe(60);  // 30% of 200
    expect($result[$c->id])->toBe(140); // 20% of 200 + 100 earmarked
});

it('caps earmarked units when exceeding total', function () {
    [$a, $b, $c] = setupSkuMixProducts();
    seedHistoricalSales([$a, $b, $c], [100, 60, 40]);

    $service = app(SkuMixService::class);
    $result = $service->distribute(
        ProductCategory::Chain,
        100,
        earmarkedUnits: [$c->id => 200], // more than total
    );

    // Earmarked is capped, all units go to Pro Chain
    expect(array_sum($result))->toBeLessThanOrEqual(100);
    expect($result[$c->id])->toBeGreaterThan(0);
});

it('handles earmarked product not in historical mix', function () {
    [$a, $b, $_] = setupSkuMixProducts();
    seedHistoricalSales([$a, $b], [100, 100]); // Only A and B have sales

    $newProduct = Product::factory()->create([
        'product_category' => ProductCategory::Chain->value,
        'name' => 'Brand New Chain',
        'is_active' => true,
    ]);

    $service = app(SkuMixService::class);
    $result = $service->distribute(
        ProductCategory::Chain,
        200,
        earmarkedUnits: [$newProduct->id => 50],
    );

    // 50 earmarked for new product, 150 remaining split 50/50 between A and B
    expect($result[$newProduct->id])->toBe(50);
    expect($result[$a->id])->toBe(75);
    expect($result[$b->id])->toBe(75);
});

it('combines scenario overrides with earmarked units', function () {
    [$a, $b, $c] = setupSkuMixProducts();
    seedHistoricalSales([$a, $b, $c], [100, 60, 40]);

    $scenario = Scenario::factory()->create(['year' => 2026]);

    // Override: A=50%, B=30%, C=20%
    createSkuOverride($scenario, ProductCategory::Chain, $a->id, 0.5000);
    createSkuOverride($scenario, ProductCategory::Chain, $b->id, 0.3000);
    createSkuOverride($scenario, ProductCategory::Chain, $c->id, 0.2000);

    $service = app(SkuMixService::class);
    $result = $service->distribute(
        ProductCategory::Chain,
        300,
        scenario: $scenario,
        earmarkedUnits: [$c->id => 60],
    );

    // 60 earmarked for C, 240 remaining via overrides: A=120, B=72, C=48
    expect($result[$a->id])->toBe(120); // 50% of 240
    expect($result[$b->id])->toBe(72);  // 30% of 240
    expect($result[$c->id])->toBe(108); // 20% of 240 + 60 earmarked
});
