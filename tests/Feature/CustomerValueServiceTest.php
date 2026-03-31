<?php

use App\Models\ShopifyCustomer;
use App\Models\ShopifyOrder;
use App\Services\Analysis\CustomerValueService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('calculates LTV per customer', function () {
    $customer = ShopifyCustomer::factory()->create(['first_order_at' => '2025-01-15']);

    ShopifyOrder::factory()->create([
        'customer_id' => $customer->id,
        'ordered_at' => '2025-01-15',
        'net_revenue' => 150,
        'financial_status' => 'paid',
    ]);

    ShopifyOrder::factory()->create([
        'customer_id' => $customer->id,
        'ordered_at' => '2025-04-10',
        'net_revenue' => 80,
        'financial_status' => 'paid',
    ]);

    $service = app(CustomerValueService::class);
    $result = $service->ltvPerCustomer('2024-01-01');

    expect($result)->toHaveCount(1)
        ->and((float) $result[0]->total_ltv)->toBe(230.00)
        ->and((int) $result[0]->order_count)->toBe(2);
});

it('excludes voided and refunded orders from LTV', function () {
    $customer = ShopifyCustomer::factory()->create(['first_order_at' => '2025-01-15']);

    ShopifyOrder::factory()->create([
        'customer_id' => $customer->id,
        'ordered_at' => '2025-01-15',
        'net_revenue' => 150,
        'financial_status' => 'paid',
    ]);

    ShopifyOrder::factory()->create([
        'customer_id' => $customer->id,
        'ordered_at' => '2025-02-10',
        'net_revenue' => 100,
        'financial_status' => 'refunded',
    ]);

    $service = app(CustomerValueService::class);
    $result = $service->ltvPerCustomer('2024-01-01');

    expect($result)->toHaveCount(1)
        ->and((float) $result[0]->total_ltv)->toBe(150.00);
});

it('groups LTV by RFM segment', function () {
    $champion = ShopifyCustomer::factory()->create([
        'first_order_at' => '2025-01-01',
        'rfm_segment' => 'champion',
    ]);
    $atRisk = ShopifyCustomer::factory()->create([
        'first_order_at' => '2025-01-01',
        'rfm_segment' => 'at_risk',
    ]);

    ShopifyOrder::factory()->create([
        'customer_id' => $champion->id,
        'ordered_at' => '2025-01-15',
        'net_revenue' => 500,
        'financial_status' => 'paid',
    ]);

    ShopifyOrder::factory()->create([
        'customer_id' => $atRisk->id,
        'ordered_at' => '2025-02-15',
        'net_revenue' => 80,
        'financial_status' => 'paid',
    ]);

    $service = app(CustomerValueService::class);
    $result = $service->ltvBySegment('2024-01-01');

    expect($result)->toHaveCount(2);

    $championRow = collect($result)->firstWhere('segment', 'champion');
    expect((float) $championRow->avg_ltv)->toBe(500.00);
});

it('groups LTV by acquisition channel', function () {
    $google = ShopifyCustomer::factory()->create([
        'first_order_at' => '2025-01-01',
        'first_order_channel' => 'paid_google',
    ]);
    $meta = ShopifyCustomer::factory()->create([
        'first_order_at' => '2025-01-01',
        'first_order_channel' => 'paid_instagram',
    ]);

    ShopifyOrder::factory()->create([
        'customer_id' => $google->id,
        'ordered_at' => '2025-01-15',
        'net_revenue' => 200,
        'financial_status' => 'paid',
    ]);

    ShopifyOrder::factory()->create([
        'customer_id' => $meta->id,
        'ordered_at' => '2025-02-15',
        'net_revenue' => 120,
        'financial_status' => 'paid',
    ]);

    $service = app(CustomerValueService::class);
    $result = $service->ltvByChannel('2024-01-01');

    expect($result)->toHaveCount(2);

    $googleRow = collect($result)->firstWhere('channel', 'paid_google');
    expect((float) $googleRow->avg_ltv)->toBe(200.00);
});
