<?php

use App\Models\Product;
use App\Models\ProductStockSnapshot;
use App\Models\ShopifyLineItem;
use App\Models\ShopifyOrder;
use App\Services\Forecast\Supply\InventoryHealthService;

it('calculates burn rate from sales history', function () {
    $product = Product::factory()->create();

    // 30 units sold over 90 days → 0.33/day
    $order = ShopifyOrder::factory()->create([
        'ordered_at' => now()->subDays(45),
        'financial_status' => 'paid',
    ]);

    ShopifyLineItem::factory()->create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'quantity' => 30,
    ]);

    $service = app(InventoryHealthService::class);
    $burnRate = $service->burnRate($product->id, 90);

    expect($burnRate)->toBe(0.33);
});

it('calculates stock runway', function () {
    $product = Product::factory()->create();

    ProductStockSnapshot::factory()->create([
        'product_id' => $product->id,
        'qty_free' => 100,
        'recorded_at' => now(),
    ]);

    // 10 units sold in 90 days → 0.11/day → 100 / 0.11 = ~909 days
    $order = ShopifyOrder::factory()->create([
        'ordered_at' => now()->subDays(45),
        'financial_status' => 'paid',
    ]);

    ShopifyLineItem::factory()->create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'quantity' => 10,
    ]);

    $service = app(InventoryHealthService::class);
    $result = $service->stockRunway($product->id, 90);

    expect($result['qty_free'])->toBe(100.00)
        ->and($result['burn_rate'])->toBe(0.11)
        ->and($result['runway_days'])->toBe(909);
});

it('returns null runway when no sales', function () {
    $product = Product::factory()->create();

    ProductStockSnapshot::factory()->create([
        'product_id' => $product->id,
        'qty_free' => 50,
        'recorded_at' => now(),
    ]);

    $service = app(InventoryHealthService::class);
    $result = $service->stockRunway($product->id, 90);

    expect($result['runway_days'])->toBeNull()
        ->and($result['burn_rate'])->toBe(0.00);
});

it('flags reorder alert when runway below threshold', function () {
    $product = Product::factory()->create();

    ProductStockSnapshot::factory()->create([
        'product_id' => $product->id,
        'qty_free' => 10,
        'recorded_at' => now(),
    ]);

    // 1 unit/day burn rate
    $order = ShopifyOrder::factory()->create([
        'ordered_at' => now()->subDays(45),
        'financial_status' => 'paid',
    ]);

    ShopifyLineItem::factory()->create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'quantity' => 90,
    ]);

    $service = app(InventoryHealthService::class);
    $result = $service->reorderAlert($product->id, leadTimeDays: 21, bufferDays: 7);

    // 10 qty / 1.0/day = 10 days runway, threshold = 21 + 7 = 28 → needs reorder
    expect($result['needs_reorder'])->toBeTrue()
        ->and($result['runway_days'])->toBe(10);
});
