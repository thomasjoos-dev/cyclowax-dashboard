<?php

use App\Enums\ForecastRegion;
use App\Enums\ProductCategory;
use App\Models\Scenario;
use App\Models\ScenarioAssumption;
use App\Models\ScenarioProductMix;
use App\Models\SeasonalIndex;
use App\Models\ShopifyCustomer;
use App\Models\ShopifyOrder;
use App\Services\Forecast\Demand\CohortProjectionService;
use App\Services\Forecast\Demand\DemandForecastService;
use App\Services\Forecast\Demand\RegionalForecastAggregator;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function setupRegionalScenario(): Scenario
{
    // Create orders per region for 2025 + Q1 2026
    $regions = [
        'DE' => ['monthly_rev' => 200, 'repeat_rev' => 30],
        'BE' => ['monthly_rev' => 100, 'repeat_rev' => 20],
    ];

    foreach ($regions as $countryCode => $config) {
        for ($month = 1; $month <= 12; $month++) {
            $m = str_pad($month, 2, '0', STR_PAD_LEFT);

            ShopifyOrder::factory()->create([
                'ordered_at' => "2025-{$m}-15",
                'financial_status' => 'PAID',
                'is_first_order' => true,
                'net_revenue' => $config['monthly_rev'],
                'shipping_country_code' => $countryCode,
                'billing_country_code' => $countryCode,
                'customer_id' => ShopifyCustomer::factory()->create()->id,
            ]);

            ShopifyOrder::factory()->create([
                'ordered_at' => "2025-{$m}-20",
                'financial_status' => 'PAID',
                'is_first_order' => false,
                'net_revenue' => $config['repeat_rev'],
                'shipping_country_code' => $countryCode,
                'billing_country_code' => $countryCode,
                'customer_id' => ShopifyCustomer::factory()->create()->id,
            ]);
        }

        // Q1 2026 actuals
        for ($month = 1; $month <= 3; $month++) {
            $m = str_pad($month, 2, '0', STR_PAD_LEFT);
            ShopifyOrder::factory()->create([
                'ordered_at' => "2026-{$m}-15",
                'financial_status' => 'PAID',
                'is_first_order' => true,
                'net_revenue' => $config['monthly_rev'] * 1.1,
                'shipping_country_code' => $countryCode,
                'billing_country_code' => $countryCode,
                'customer_id' => ShopifyCustomer::factory()->create()->id,
            ]);
        }
    }

    // Seasonal indices (flat = 1.0) for both regions and global
    for ($month = 1; $month <= 12; $month++) {
        foreach ([null, 'de', 'be'] as $region) {
            SeasonalIndex::create([
                'month' => $month,
                'region' => $region,
                'product_category' => ProductCategory::WaxTablet->value,
                'index_value' => 1.0,
                'source' => 'test',
            ]);
        }
    }

    // Global scenario + assumptions
    $scenario = Scenario::factory()->create(['year' => 2026]);
    foreach (['Q2', 'Q3', 'Q4'] as $quarter) {
        ScenarioAssumption::factory()->create([
            'scenario_id' => $scenario->id,
            'quarter' => $quarter,
            'acq_rate' => 1.20,
            'repeat_rate' => 0.25,
            'repeat_aov' => 85.00,
        ]);
    }

    // Global product mix (single category for simplicity)
    ScenarioProductMix::create([
        'scenario_id' => $scenario->id,
        'product_category' => ProductCategory::WaxTablet->value,
        'acq_share' => 1.0,
        'repeat_share' => 1.0,
        'avg_unit_price' => 27.50,
    ]);

    return $scenario;
}

it('generates a regional forecast with filtered baseline', function () {
    $scenario = setupRegionalScenario();

    // Mock cohort service to avoid complex retention setup
    $mock = Mockery::mock(CohortProjectionService::class);
    $mock->shouldReceive('retentionCurve')->andReturn(['months' => [], 'cohorts_used' => 0, 'avg_cohort_size' => 0, 'source' => 'global']);
    app()->instance(CohortProjectionService::class, $mock);

    $service = app(DemandForecastService::class);

    $deForecast = $service->totalForecast($scenario, 2026, ForecastRegion::De);
    $beForecast = $service->totalForecast($scenario, 2026, ForecastRegion::Be);

    // DE has higher revenue than BE (200 vs 100 baseline)
    expect($deForecast['year_total']['revenue'])->toBeGreaterThan(0)
        ->and($beForecast['year_total']['revenue'])->toBeGreaterThan(0)
        ->and($deForecast['year_total']['revenue'])->toBeGreaterThan($beForecast['year_total']['revenue']);
});

it('uses regional assumptions when available with global fallback', function () {
    $scenario = setupRegionalScenario();

    // Add DE-specific assumptions with higher growth rate
    foreach (['Q2', 'Q3', 'Q4'] as $quarter) {
        ScenarioAssumption::create([
            'scenario_id' => $scenario->id,
            'quarter' => $quarter,
            'region' => ForecastRegion::De->value,
            'acq_rate' => 2.00, // double growth for DE
            'repeat_rate' => 0.25,
            'repeat_aov' => 85.00,
        ]);
    }

    $mock = Mockery::mock(CohortProjectionService::class);
    $mock->shouldReceive('retentionCurve')->andReturn(['months' => [], 'cohorts_used' => 0, 'avg_cohort_size' => 0, 'source' => 'global']);
    app()->instance(CohortProjectionService::class, $mock);

    $service = app(DemandForecastService::class);

    // DE uses regional assumptions (2.0x), BE uses global (1.2x)
    $deForecast = $service->totalForecast($scenario->fresh(), 2026, ForecastRegion::De);
    $beForecast = $service->totalForecast($scenario->fresh(), 2026, ForecastRegion::Be);

    // DE should be significantly higher due to 2x growth rate
    // Even though DE baseline is only 2x BE, with 2x growth vs 1.2x growth the gap widens
    expect($deForecast['year_total']['revenue'])->toBeGreaterThan($beForecast['year_total']['revenue'] * 2);
});

it('returns empty forecast for region with no baseline data', function () {
    $scenario = setupRegionalScenario();

    $mock = Mockery::mock(CohortProjectionService::class);
    $mock->shouldReceive('retentionCurve')->andReturn(['months' => [], 'cohorts_used' => 0, 'avg_cohort_size' => 0, 'source' => 'global']);
    app()->instance(CohortProjectionService::class, $mock);

    $service = app(DemandForecastService::class);

    // ROW has no orders → empty forecast (no exception)
    $rowForecast = $service->totalForecast($scenario, 2026, ForecastRegion::Row);

    expect($rowForecast['year_total']['revenue'])->toBe(0.0)
        ->and($rowForecast['year_total']['units'])->toBe(0);
});

it('reports repeat model info with regional source', function () {
    $scenario = setupRegionalScenario();

    $service = app(DemandForecastService::class);

    $info = $service->repeatModelInfo($scenario, ForecastRegion::De);

    // DE won't have enough cohort data in test → likely flat model
    expect($info)->toHaveKeys(['model', 'curve_adjustment', 'cohorts_used', 'source']);
});

it('aggregates all regional forecasts to a total', function () {
    $scenario = setupRegionalScenario();

    $mock = Mockery::mock(CohortProjectionService::class);
    $mock->shouldReceive('retentionCurve')->andReturn(['months' => [], 'cohorts_used' => 0, 'avg_cohort_size' => 0, 'source' => 'global']);
    app()->instance(CohortProjectionService::class, $mock);

    $aggregator = app(RegionalForecastAggregator::class);

    $result = $aggregator->forecastAllRegions($scenario, 2026);

    expect($result)->toHaveKeys(['total', 'regions', 'year_total', 'region_totals'])
        ->and($result['regions'])->toHaveCount(9) // 9 ForecastRegion cases
        ->and($result['year_total']['revenue'])->toBeGreaterThan(0)
        ->and($result['region_totals']['de']['revenue'])->toBeGreaterThan(0)
        ->and($result['region_totals']['be']['revenue'])->toBeGreaterThan(0)
        ->and($result['region_totals']['row']['revenue'])->toBe(0.0);
});

it('aggregates by warehouse correctly', function () {
    $scenario = setupRegionalScenario();

    $mock = Mockery::mock(CohortProjectionService::class);
    $mock->shouldReceive('retentionCurve')->andReturn(['months' => [], 'cohorts_used' => 0, 'avg_cohort_size' => 0, 'source' => 'global']);
    app()->instance(CohortProjectionService::class, $mock);

    $aggregator = app(RegionalForecastAggregator::class);

    $warehouseTotals = $aggregator->forecastByWarehouse($scenario, 2026);

    expect($warehouseTotals)->toHaveKeys(['be', 'us'])
        ->and($warehouseTotals['be']['revenue'])->toBeGreaterThan(0)
        ->and($warehouseTotals['us']['revenue'])->toBe(0.0); // No US orders in test data
});
