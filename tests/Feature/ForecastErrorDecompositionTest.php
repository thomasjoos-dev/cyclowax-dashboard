<?php

use App\Enums\ProductCategory;
use App\Models\ForecastSnapshot;
use App\Models\Scenario;
use App\Services\Forecast\Tracking\ForecastTrackingService;

function createSnapshotsWithActuals(Scenario $scenario, string $yearMonth, array $categories): void
{
    foreach ($categories as $cat) {
        ForecastSnapshot::factory()->create([
            'scenario_id' => $scenario->id,
            'year_month' => $yearMonth,
            'product_category' => $cat['category'],
            'region' => null,
            'forecasted_units' => $cat['f_units'],
            'forecasted_revenue' => $cat['f_revenue'],
            'actual_units' => $cat['a_units'],
            'actual_revenue' => $cat['a_revenue'],
        ]);
    }
}

it('decomposes variance into volume, price, and mix effects', function () {
    $scenario = Scenario::factory()->create(['year' => 2026]);

    // Forecast: 100 units @ 10.00 = 1000, actuals: 120 units @ 12.00 = 1440
    // Volume effect: (120 - 100) × 10 = 200
    // Price effect: (12 - 10) × 120 = 240
    // Total variance: 440
    createSnapshotsWithActuals($scenario, '2026-01', [
        [
            'category' => ProductCategory::WaxTablet->value,
            'f_units' => 100,
            'f_revenue' => 1000.00,
            'a_units' => 120,
            'a_revenue' => 1440.00,
        ],
    ]);

    $service = app(ForecastTrackingService::class);
    $result = $service->decomposeVariance($scenario, 2026);

    expect($result)->toHaveKey('2026-01');

    $jan = $result['2026-01'];
    expect($jan['total_variance'])->toBe(440.0)
        ->and($jan['volume_effect'])->toBe(200.0)
        ->and($jan['price_effect'])->toBe(240.0);
});

it('decomposes multi-category variance with mix effect', function () {
    $scenario = Scenario::factory()->create(['year' => 2026]);

    // Two categories:
    // Kits:  forecast 50u @ 200 = 10000, actual 30u @ 200 = 6000 (volume down)
    // Wax:   forecast 50u @ 30  = 1500,  actual 80u @ 30  = 2400 (volume up, cheaper product)
    // Mix shifted from expensive to cheap → negative mix effect
    createSnapshotsWithActuals($scenario, '2026-03', [
        [
            'category' => ProductCategory::StarterKit->value,
            'f_units' => 50,
            'f_revenue' => 10000.00,
            'a_units' => 30,
            'a_revenue' => 6000.00,
        ],
        [
            'category' => ProductCategory::WaxTablet->value,
            'f_units' => 50,
            'f_revenue' => 1500.00,
            'a_units' => 80,
            'a_revenue' => 2400.00,
        ],
    ]);

    $service = app(ForecastTrackingService::class);
    $result = $service->decomposeVariance($scenario, 2026);

    expect($result)->toHaveKey('2026-03');

    $march = $result['2026-03'];
    // Total: (6000 + 2400) - (10000 + 1500) = -3100
    expect($march['total_variance'])->toBe(-3100.0);

    // Mix effect should be negative (shift towards cheaper product)
    expect($march['mix_effect'])->toBeLessThan(0);

    // All effects should approximately sum to total variance
    $sumEffects = $march['volume_effect'] + $march['price_effect'] + $march['mix_effect'] + $march['residual'];
    expect(abs($sumEffects - $march['total_variance']))->toBeLessThan(0.1);
});

it('returns empty array when no actuals exist', function () {
    $scenario = Scenario::factory()->create(['year' => 2026]);

    // Snapshot without actuals
    ForecastSnapshot::factory()->create([
        'scenario_id' => $scenario->id,
        'year_month' => '2026-01',
        'product_category' => ProductCategory::WaxTablet->value,
        'region' => null,
        'forecasted_units' => 100,
        'forecasted_revenue' => 1000.00,
        'actual_units' => null,
        'actual_revenue' => null,
    ]);

    $service = app(ForecastTrackingService::class);
    $result = $service->decomposeVariance($scenario, 2026);

    expect($result)->toBeEmpty();
});

it('handles zero forecasted units without division error', function () {
    $scenario = Scenario::factory()->create(['year' => 2026]);

    createSnapshotsWithActuals($scenario, '2026-02', [
        [
            'category' => ProductCategory::WaxTablet->value,
            'f_units' => 0,
            'f_revenue' => 0.00,
            'a_units' => 10,
            'a_revenue' => 300.00,
        ],
    ]);

    $service = app(ForecastTrackingService::class);
    $result = $service->decomposeVariance($scenario, 2026);

    expect($result)->toHaveKey('2026-02');
    expect($result['2026-02']['total_variance'])->toBe(300.0);
});
