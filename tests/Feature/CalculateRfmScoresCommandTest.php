<?php

use App\Enums\CustomerSegment;
use App\Models\ShopifyCustomer;
use App\Models\ShopifyOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createCustomerWithOrders(array $orderOverrides = [], array $customerOverrides = []): ShopifyCustomer
{
    $customer = ShopifyCustomer::factory()->create($customerOverrides);

    foreach ($orderOverrides as $override) {
        $netRevenue = ($override['total_price'] ?? 100) - ($override['tax'] ?? 21) - ($override['refunded'] ?? 0);

        ShopifyOrder::factory()->create(array_merge([
            'customer_id' => $customer->id,
            'financial_status' => 'PAID',
            'total_price' => 100,
            'tax' => 21,
            'refunded' => 0,
            'net_revenue' => $netRevenue,
            'ordered_at' => '2025-01-01',
        ], $override));
    }

    return $customer;
}

it('scores customers and assigns segments', function () {
    // Top Customer: recent, frequent, high spend
    $top = createCustomerWithOrders([
        ['ordered_at' => now()->subDays(5), 'total_price' => 300, 'tax' => 63, 'refunded' => 0],
        ['ordered_at' => now()->subDays(30), 'total_price' => 300, 'tax' => 63, 'refunded' => 0],
        ['ordered_at' => now()->subDays(60), 'total_price' => 300, 'tax' => 63, 'refunded' => 0],
        ['ordered_at' => now()->subDays(90), 'total_price' => 300, 'tax' => 63, 'refunded' => 0],
        ['ordered_at' => now()->subDays(120), 'total_price' => 300, 'tax' => 63, 'refunded' => 0],
    ]);

    // Low-Value One-Timer: one cheap order long ago
    $lowValue = createCustomerWithOrders([
        ['ordered_at' => '2024-06-01', 'total_price' => 30, 'tax' => 6.30, 'refunded' => 0],
    ]);

    $this->artisan('customers:calculate-rfm')->assertSuccessful();

    $top->refresh();
    expect($top->r_score)->toBe(5)
        ->and($top->f_score)->toBe(5)
        ->and($top->rfm_segment)->toBe(CustomerSegment::Champion)
        ->and($top->rfm_scored_at)->not->toBeNull();

    $lowValue->refresh();
    expect($lowValue->f_score)->toBe(1)
        ->and($lowValue->rfm_segment)->toBe(CustomerSegment::OneTimer);
});

it('excludes refunded and voided orders from scoring', function () {
    $customer = createCustomerWithOrders([
        ['ordered_at' => now()->subDays(10), 'financial_status' => 'REFUNDED', 'total_price' => 500, 'tax' => 105, 'refunded' => 0],
        ['ordered_at' => now()->subDays(20), 'financial_status' => 'VOIDED', 'total_price' => 500, 'tax' => 105, 'refunded' => 0],
        ['ordered_at' => now()->subDays(30), 'financial_status' => 'PAID', 'total_price' => 50, 'tax' => 10.50, 'refunded' => 0],
    ]);

    $this->artisan('customers:calculate-rfm')->assertSuccessful();

    $customer->refresh();
    // Only the PAID order should count: F=1
    expect($customer->f_score)->toBe(1)
        ->and($customer->rfm_segment)->not->toBeNull();
});

it('excludes orders with zero or negative net revenue', function () {
    $customer = createCustomerWithOrders([
        ['ordered_at' => now()->subDays(10), 'total_price' => 100, 'tax' => 21, 'refunded' => 100],
        ['ordered_at' => now()->subDays(20), 'total_price' => 50, 'tax' => 10.50, 'refunded' => 0],
    ]);

    $this->artisan('customers:calculate-rfm')->assertSuccessful();

    $customer->refresh();
    // First order has net_revenue = 100 - 21 - 100 = -21 (excluded), second = 39.50 (qualifying)
    expect($customer->f_score)->toBe(1);
});

it('scores customers with orders before 2024', function () {
    $customer = createCustomerWithOrders([
        ['ordered_at' => '2023-06-01', 'total_price' => 200, 'tax' => 42, 'refunded' => 0],
    ]);

    $this->artisan('customers:calculate-rfm')->assertSuccessful();

    $customer->refresh();
    expect($customer->rfm_segment)->not->toBeNull()
        ->and($customer->r_score)->toBeInt();
});

it('clears scores for customers who fall out of scope', function () {
    $customer = ShopifyCustomer::factory()->create([
        'r_score' => 5,
        'f_score' => 5,
        'm_score' => 5,
        'rfm_segment' => CustomerSegment::Champion,
        'rfm_scored_at' => now()->subDay(),
    ]);

    // No qualifying orders in scope
    $this->artisan('customers:calculate-rfm')->assertSuccessful();

    $customer->refresh();
    expect($customer->rfm_segment)->toBeNull()
        ->and($customer->r_score)->toBeNull();
});

it('applies frequency breakpoints correctly', function () {
    $fiveOrders = createCustomerWithOrders(array_fill(0, 5, ['ordered_at' => now()->subDays(10)]));
    $threeOrders = createCustomerWithOrders(array_fill(0, 3, ['ordered_at' => now()->subDays(10)]));
    $twoOrders = createCustomerWithOrders(array_fill(0, 2, ['ordered_at' => now()->subDays(10)]));
    $oneOrder = createCustomerWithOrders([['ordered_at' => now()->subDays(10)]]);

    $this->artisan('customers:calculate-rfm')->assertSuccessful();

    expect($fiveOrders->refresh()->f_score)->toBe(5)
        ->and($threeOrders->refresh()->f_score)->toBe(4)
        ->and($twoOrders->refresh()->f_score)->toBe(3)
        ->and($oneOrder->refresh()->f_score)->toBe(1);
});

it('assigns at risk segment before loyal middle', function () {
    // Need enough customers to establish quintile spread
    // Create background customers to fill quintiles
    for ($i = 0; $i < 20; $i++) {
        createCustomerWithOrders([
            ['ordered_at' => now()->subDays(rand(1, 800)), 'total_price' => rand(30, 400), 'tax' => 10, 'refunded' => 0],
        ]);
    }

    // At Risk candidate: high F, high M, but very old recency
    $atRisk = createCustomerWithOrders([
        ['ordered_at' => '2024-02-01', 'total_price' => 300, 'tax' => 63, 'refunded' => 0],
        ['ordered_at' => '2024-03-01', 'total_price' => 300, 'tax' => 63, 'refunded' => 0],
        ['ordered_at' => '2024-04-01', 'total_price' => 300, 'tax' => 63, 'refunded' => 0],
    ]);

    $this->artisan('customers:calculate-rfm')->assertSuccessful();

    $atRisk->refresh();
    // With very old orders, R should be low (1-2), F=4 (3 orders), M should be high
    // This should match At Risk (R<=2, F>=3, M>=3) before Loyal Middle (F>=3, M>=2)
    expect($atRisk->rfm_segment)->toBe(CustomerSegment::AtRisk);
});
