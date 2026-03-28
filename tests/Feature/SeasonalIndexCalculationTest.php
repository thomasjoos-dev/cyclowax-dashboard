<?php

use App\Models\SeasonalIndex;
use App\Models\ShopifyOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('calculates seasonal indices from order data', function () {
    // Jan: 10 orders, Jul: 20 orders → Jul should have higher index
    foreach (range(1, 10) as $i) {
        ShopifyOrder::factory()->create([
            'ordered_at' => '2025-01-'.str_pad($i, 2, '0', STR_PAD_LEFT),
            'financial_status' => 'paid',
        ]);
    }

    foreach (range(1, 20) as $i) {
        ShopifyOrder::factory()->create([
            'ordered_at' => '2025-07-'.str_pad(min($i, 28), 2, '0', STR_PAD_LEFT),
            'financial_status' => 'paid',
        ]);
    }

    $this->artisan('seasonal:calculate')
        ->assertSuccessful();

    $jan = SeasonalIndex::where('month', 1)->whereNull('region')->first();
    $jul = SeasonalIndex::where('month', 7)->whereNull('region')->first();

    expect($jan)->not->toBeNull()
        ->and($jul)->not->toBeNull()
        ->and((float) $jul->index_value)->toBeGreaterThan((float) $jan->index_value);
});

it('normalizes indices so average equals 1.0', function () {
    // Create evenly spread orders across 12 months
    foreach (range(1, 12) as $month) {
        foreach (range(1, 10) as $i) {
            ShopifyOrder::factory()->create([
                'ordered_at' => '2025-'.str_pad($month, 2, '0', STR_PAD_LEFT).'-'.str_pad($i, 2, '0', STR_PAD_LEFT),
                'financial_status' => 'paid',
            ]);
        }
    }

    $this->artisan('seasonal:calculate')
        ->assertSuccessful();

    $indices = SeasonalIndex::whereNull('region')->pluck('index_value');
    $avg = $indices->avg();

    expect(round($avg, 2))->toBe(1.00);
});
