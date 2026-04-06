<?php

use App\Enums\ProductCategory;
use App\Models\Product;
use App\Models\ShopifyCustomer;
use App\Models\ShopifyLineItem;
use App\Models\ShopifyOrder;
use App\Services\Forecast\Demand\SalesBaselineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

it('calculates return rate per quarter', function () {
    $customer = ShopifyCustomer::factory()->create();

    // Q1: 10 orders, 3 with partial refunds
    for ($i = 0; $i < 7; $i++) {
        ShopifyOrder::factory()->create([
            'ordered_at' => '2026-02-15',
            'financial_status' => 'PAID',
            'refunded' => 0,
            'customer_id' => $customer->id,
        ]);
    }
    for ($i = 0; $i < 3; $i++) {
        ShopifyOrder::factory()->create([
            'ordered_at' => '2026-02-15',
            'financial_status' => 'PARTIALLY_REFUNDED',
            'refunded' => 25.00,
            'customer_id' => $customer->id,
        ]);
    }

    $service = app(SalesBaselineService::class);
    $result = $service->returnRateByQuarter(2026);

    expect($result)->toHaveKey('Q1')
        ->and($result['Q1']['return_rate'])->toBe(30.0)
        ->and($result['Q1']['avg_refund'])->toBe(25.0)
        ->and($result['Q1']['order_count'])->toBe(10)
        ->and($result['Q1']['refunded_count'])->toBe(3);
});

it('logs warning when return rate deviates from trailing average', function () {
    $customer = ShopifyCustomer::factory()->create();

    // 2025 trailing data: 20 orders, 2 with refund = 10% rate
    for ($i = 0; $i < 18; $i++) {
        ShopifyOrder::factory()->create([
            'ordered_at' => '2025-06-15',
            'financial_status' => 'PAID',
            'refunded' => 0,
            'customer_id' => $customer->id,
        ]);
    }
    for ($i = 0; $i < 2; $i++) {
        ShopifyOrder::factory()->create([
            'ordered_at' => '2025-06-15',
            'financial_status' => 'PARTIALLY_REFUNDED',
            'refunded' => 20.00,
            'customer_id' => $customer->id,
        ]);
    }

    // 2026 Q1: 10 orders, 5 with refund = 50% rate (>5pp deviation from 10%)
    for ($i = 0; $i < 5; $i++) {
        ShopifyOrder::factory()->create([
            'ordered_at' => '2026-02-15',
            'financial_status' => 'PAID',
            'refunded' => 0,
            'customer_id' => $customer->id,
        ]);
    }
    for ($i = 0; $i < 5; $i++) {
        ShopifyOrder::factory()->create([
            'ordered_at' => '2026-02-15',
            'financial_status' => 'PARTIALLY_REFUNDED',
            'refunded' => 30.00,
            'customer_id' => $customer->id,
        ]);
    }

    Log::spy();

    $service = app(SalesBaselineService::class);
    $service->returnRateByQuarter(2026);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $msg) => str_contains($msg, 'Return rate deviation'))
        ->atLeast()->once();
});

it('calculates return rate by category', function () {
    $customer = ShopifyCustomer::factory()->create();
    $waxProduct = Product::factory()->create(['product_category' => ProductCategory::WaxTablet->value]);

    // 5 orders with wax, 2 with partial refund
    for ($i = 0; $i < 3; $i++) {
        $order = ShopifyOrder::factory()->create([
            'ordered_at' => '2026-03-15',
            'financial_status' => 'PAID',
            'refunded' => 0,
            'customer_id' => $customer->id,
        ]);
        ShopifyLineItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $waxProduct->id,
            'quantity' => 1,
            'price' => 30,
        ]);
    }
    for ($i = 0; $i < 2; $i++) {
        $order = ShopifyOrder::factory()->create([
            'ordered_at' => '2026-03-15',
            'financial_status' => 'PARTIALLY_REFUNDED',
            'refunded' => 15.00,
            'customer_id' => $customer->id,
        ]);
        ShopifyLineItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $waxProduct->id,
            'quantity' => 1,
            'price' => 30,
        ]);
    }

    $service = app(SalesBaselineService::class);
    $result = $service->returnRateByCategory(2026);

    expect($result)->toHaveKey(ProductCategory::WaxTablet->value);

    $wax = $result[ProductCategory::WaxTablet->value];
    expect($wax['return_rate'])->toBe(40.0)
        ->and($wax['avg_refund'])->toBe(15.0)
        ->and($wax['order_count'])->toBe(5);
});

it('calculates gross vs net AOV per quarter', function () {
    $customer = ShopifyCustomer::factory()->create();

    // Q2 repeat orders: net_revenue=80, refunded=10 → gross=90
    for ($i = 0; $i < 10; $i++) {
        ShopifyOrder::factory()->create([
            'ordered_at' => '2026-05-15',
            'financial_status' => 'PARTIALLY_REFUNDED',
            'is_first_order' => false,
            'net_revenue' => 80.00,
            'refunded' => 10.00,
            'customer_id' => $customer->id,
        ]);
    }

    $service = app(SalesBaselineService::class);
    $result = $service->grossAovByQuarter(2026);

    expect($result)->toHaveKey('Q2');
    expect($result['Q2']['net_aov'])->toBe(80.0)
        ->and($result['Q2']['gross_aov'])->toBe(90.0)
        ->and($result['Q2']['refund_impact'])->toBe(10.0);
});
