<?php

use App\Enums\ForecastRegion;
use App\Enums\Warehouse;
use App\Models\Scenario;
use App\Services\Forecast\Demand\DemandForecastService;
use App\Services\Forecast\Supply\PurchaseCalendarService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('calls forecastYear with each warehouse region when warehouse is specified', function () {
    $scenario = Scenario::factory()->create(['year' => 2026]);

    $mock = Mockery::mock(DemandForecastService::class);

    // US warehouse has only ForecastRegion::Us
    $mock->shouldReceive('forecastYear')
        ->with($scenario, 2026, ForecastRegion::Us)
        ->once()
        ->andReturn(emptyForecast());

    // Should NOT call forecastYear without region (global) or with non-US regions
    $mock->shouldNotReceive('forecastYear')
        ->with($scenario, 2026, null);

    app()->instance(DemandForecastService::class, $mock);

    $service = app(PurchaseCalendarService::class);
    $result = $service->generate($scenario, 2026, Warehouse::Us);

    expect($result['warehouse'])->toBe('us')
        ->and($result['timeline'])->toBeArray();
});

it('calls forecastYear for all BE warehouse regions', function () {
    $scenario = Scenario::factory()->create(['year' => 2026]);

    $mock = Mockery::mock(DemandForecastService::class);

    // BE warehouse has 8 regions
    $beRegions = Warehouse::Be->regions();
    foreach ($beRegions as $region) {
        $mock->shouldReceive('forecastYear')
            ->with($scenario, 2026, $region)
            ->once()
            ->andReturn(emptyForecast());
    }

    app()->instance(DemandForecastService::class, $mock);

    $service = app(PurchaseCalendarService::class);
    $result = $service->generate($scenario, 2026, Warehouse::Be);

    expect($result['warehouse'])->toBe('be');
});

it('calls forecastYear without region when no warehouse specified', function () {
    $scenario = Scenario::factory()->create(['year' => 2026]);

    $mock = Mockery::mock(DemandForecastService::class);

    // Global call — no region
    $mock->shouldReceive('forecastYear')
        ->with($scenario, 2026)
        ->once()
        ->andReturn(emptyForecast());

    app()->instance(DemandForecastService::class, $mock);

    $service = app(PurchaseCalendarService::class);
    $result = $service->generate($scenario, 2026);

    expect($result['warehouse'])->toBeNull();
});

it('rejects invalid warehouse in command', function () {
    $scenario = Scenario::factory()->create(['name' => 'base', 'year' => 2026]);

    $this->artisan('forecast:purchase-calendar', [
        'scenario' => 'base',
        '--year' => '2026',
        '--warehouse' => 'invalid',
    ])->assertFailed();
});

/**
 * Helper: empty forecast structure.
 *
 * @return array<int, array>
 */
function emptyForecast(): array
{
    $forecast = [];
    for ($m = 1; $m <= 12; $m++) {
        $forecast[$m] = [];
    }

    return $forecast;
}
