<?php

use App\Models\ShopifyCustomer;
use App\Models\ShopifyOrder;
use App\Services\Forecast\Demand\QuarterlyAovCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns both actual and normalized AOV per quarter', function () {
    $customer = ShopifyCustomer::factory()->create();

    // Create repeat orders in Q2 (Apr-Jun) with known discounts
    for ($i = 0; $i < 10; $i++) {
        ShopifyOrder::factory()->create([
            'ordered_at' => '2025-05-15',
            'financial_status' => 'PAID',
            'is_first_order' => false,
            'net_revenue' => 80.00,     // After discount
            'discounts' => 20.00,       // €20 discount applied
            'customer_id' => $customer->id,
        ]);
    }

    $service = app(QuarterlyAovCalculator::class);
    $result = $service->repeatAovByQuarter(2025);

    // Q2 should have data
    expect($result)->toHaveKey('Q2');

    // Actual AOV = 80 (net_revenue)
    expect($result['Q2']['actual'])->toBe(80.0);

    // Normalized AOV = 80 + 20 = 100 (discount-adjusted)
    expect($result['Q2']['normalized'])->toBe(100.0);
});

it('returns equal actual and normalized when no discounts exist', function () {
    $customer = ShopifyCustomer::factory()->create();

    for ($i = 0; $i < 10; $i++) {
        ShopifyOrder::factory()->create([
            'ordered_at' => '2025-08-15',
            'financial_status' => 'PAID',
            'is_first_order' => false,
            'net_revenue' => 95.00,
            'discounts' => 0,
            'customer_id' => $customer->id,
        ]);
    }

    $service = app(QuarterlyAovCalculator::class);
    $result = $service->repeatAovByQuarter(2025);

    expect($result['Q3']['actual'])->toBe($result['Q3']['normalized']);
});

it('calculates discount rate correctly', function () {
    $customer = ShopifyCustomer::factory()->create();

    // 6 orders without discount
    for ($i = 0; $i < 6; $i++) {
        ShopifyOrder::factory()->create([
            'financial_status' => 'PAID',
            'discounts' => 0,
            'customer_id' => $customer->id,
        ]);
    }

    // 4 orders with discount
    for ($i = 0; $i < 4; $i++) {
        ShopifyOrder::factory()->create([
            'financial_status' => 'PAID',
            'discounts' => 15.00,
            'customer_id' => $customer->id,
        ]);
    }

    $service = app(QuarterlyAovCalculator::class);
    $result = $service->discountRate();

    expect($result['total_orders'])->toBe(10)
        ->and($result['orders_with_discount'])->toBe(4)
        ->and($result['discount_rate'])->toBe(40.0)
        ->and($result['avg_discount'])->toBe(15.0);
});
