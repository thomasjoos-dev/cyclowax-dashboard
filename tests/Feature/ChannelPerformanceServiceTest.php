<?php

use App\Models\AdSpend;
use App\Models\ShopifyCustomer;
use App\Models\ShopifyOrder;
use App\Services\Analysis\ChannelPerformanceService;

it('calculates CAC per platform', function () {
    // Google: €500 spend, 5 first orders → CAC = €100
    AdSpend::factory()->create([
        'date' => '2025-06-01',
        'platform' => 'google_ads',
        'spend' => 500,
    ]);

    $customer = ShopifyCustomer::factory()->create();

    foreach (range(1, 5) as $i) {
        ShopifyOrder::factory()->create([
            'customer_id' => $customer->id,
            'ordered_at' => '2025-06-0'.$i,
            'is_first_order' => true,
            'refined_channel' => 'paid_google',
            'financial_status' => 'paid',
            'net_revenue' => 100,
        ]);
    }

    $service = app(ChannelPerformanceService::class);
    $result = $service->cacByChannel('2025-06-01', '2025-07-01');

    expect($result['google_ads']['cac'])->toBe(100.00)
        ->and($result['google_ads']['first_orders'])->toBe(5)
        ->and($result['google_ads']['spend'])->toBe(500.00);
});

it('calculates ROAS per platform', function () {
    AdSpend::factory()->create([
        'date' => '2025-06-01',
        'platform' => 'meta_ads',
        'spend' => 200,
    ]);

    $customer = ShopifyCustomer::factory()->create();

    ShopifyOrder::factory()->create([
        'customer_id' => $customer->id,
        'ordered_at' => '2025-06-05',
        'refined_channel' => 'paid_instagram',
        'financial_status' => 'paid',
        'net_revenue' => 600,
    ]);

    ShopifyOrder::factory()->create([
        'customer_id' => $customer->id,
        'ordered_at' => '2025-06-10',
        'refined_channel' => 'paid_facebook',
        'financial_status' => 'paid',
        'net_revenue' => 400,
    ]);

    $service = app(ChannelPerformanceService::class);
    $result = $service->roasByChannel('2025-06-01', '2025-07-01');

    // Meta: €1000 revenue / €200 spend = 5.0 ROAS
    expect($result['meta_ads']['roas'])->toBe(5.00)
        ->and($result['meta_ads']['attributed_revenue'])->toBe(1000.00);
});

it('merges paid_instagram and paid_facebook into meta_ads', function () {
    AdSpend::factory()->create([
        'date' => '2025-06-01',
        'platform' => 'meta_ads',
        'spend' => 300,
    ]);

    $customer = ShopifyCustomer::factory()->create();

    ShopifyOrder::factory()->create([
        'customer_id' => $customer->id,
        'ordered_at' => '2025-06-05',
        'is_first_order' => true,
        'refined_channel' => 'paid_instagram',
        'financial_status' => 'paid',
    ]);

    ShopifyOrder::factory()->create([
        'customer_id' => $customer->id,
        'ordered_at' => '2025-06-10',
        'is_first_order' => true,
        'refined_channel' => 'paid_facebook',
        'financial_status' => 'paid',
    ]);

    $service = app(ChannelPerformanceService::class);
    $result = $service->cacByChannel('2025-06-01', '2025-07-01');

    // 2 first orders from Meta → €300 / 2 = €150 CAC
    expect($result['meta_ads']['first_orders'])->toBe(2)
        ->and($result['meta_ads']['cac'])->toBe(150.00);
});
