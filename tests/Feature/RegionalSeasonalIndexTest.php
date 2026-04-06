<?php

use App\Enums\ForecastRegion;
use App\Models\SeasonalIndex;
use App\Models\ShopifyOrder;
use App\Services\Forecast\Demand\SeasonalIndexCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createOrdersForCountry(string $countryCode, string $date, int $count = 1): void
{
    foreach (range(1, $count) as $i) {
        ShopifyOrder::factory()->create([
            'ordered_at' => $date,
            'financial_status' => 'PAID',
            'shipping_country_code' => $countryCode,
            'billing_country_code' => $countryCode,
        ]);
    }
}

it('calculates seasonal indices per forecast region', function () {
    // DE: peak in July
    createOrdersForCountry('DE', '2025-01-15', 5);
    createOrdersForCountry('DE', '2025-07-15', 20);

    // BE: peak in March
    createOrdersForCountry('BE', '2025-01-15', 5);
    createOrdersForCountry('BE', '2025-03-15', 20);

    $this->artisan('seasonal:calculate', ['--region' => 'de'])->assertSuccessful();
    $this->artisan('seasonal:calculate', ['--region' => 'be'])->assertSuccessful();

    $deJan = SeasonalIndex::where('month', 1)->where('region', 'de')->first();
    $deJul = SeasonalIndex::where('month', 7)->where('region', 'de')->first();
    $beJan = SeasonalIndex::where('month', 1)->where('region', 'be')->first();
    $beMar = SeasonalIndex::where('month', 3)->where('region', 'be')->first();

    expect((float) $deJul->index_value)->toBeGreaterThan((float) $deJan->index_value)
        ->and((float) $beMar->index_value)->toBeGreaterThan((float) $beJan->index_value);
});

it('stores regional indices separately from global', function () {
    createOrdersForCountry('DE', '2025-01-15', 10);
    createOrdersForCountry('DE', '2025-07-15', 10);

    $this->artisan('seasonal:calculate')->assertSuccessful();
    $this->artisan('seasonal:calculate', ['--region' => 'de'])->assertSuccessful();

    $globalCount = SeasonalIndex::whereNull('region')->count();
    $deCount = SeasonalIndex::where('region', 'de')->count();

    expect($globalCount)->toBe(2)
        ->and($deCount)->toBe(2);
});

it('rejects invalid region value', function () {
    $this->artisan('seasonal:calculate', ['--region' => 'invalid'])
        ->assertFailed();
});

it('calculates all regions with seasonal:calculate via SeasonalIndexCalculator', function () {
    createOrdersForCountry('DE', '2025-03-15', 10);
    createOrdersForCountry('BE', '2025-06-15', 10);
    createOrdersForCountry('AU', '2025-09-15', 5);

    // Run global + each region manually (simulates --all-regions behavior)
    $calculator = app(SeasonalIndexCalculator::class);
    $calculator->calculate(null);

    foreach (ForecastRegion::cases() as $region) {
        $calculator->calculate($region);
    }

    expect(SeasonalIndex::whereNull('region')->count())->toBeGreaterThan(0)
        ->and(SeasonalIndex::where('region', 'de')->count())->toBeGreaterThan(0)
        ->and(SeasonalIndex::where('region', 'be')->count())->toBeGreaterThan(0)
        ->and(SeasonalIndex::where('region', 'row')->count())->toBeGreaterThan(0);
});
