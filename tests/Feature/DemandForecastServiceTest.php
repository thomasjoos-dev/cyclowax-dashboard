<?php

use App\Enums\DemandEventType;
use App\Enums\ForecastGroup;
use App\Enums\ProductCategory;
use App\Exceptions\InsufficientBaselineException;
use App\Exceptions\InvalidProductMixException;
use App\Models\Product;
use App\Models\Scenario;
use App\Models\ScenarioAssumption;
use App\Models\ScenarioProductMix;
use App\Models\SeasonalIndex;
use App\Models\ShopifyCustomer;
use App\Models\ShopifyLineItem;
use App\Models\ShopifyOrder;
use App\Services\Forecast\Demand\CohortProjectionService;
use App\Services\Forecast\Demand\DemandEventService;
use App\Services\Forecast\Demand\DemandForecastService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

function setupForecastScenario(): Scenario
{
    // Create a customer
    $customer = ShopifyCustomer::factory()->create();

    // Create products for two categories
    $waxProduct = Product::factory()->create(['product_category' => ProductCategory::WaxTablet->value]);
    $kitProduct = Product::factory()->create(['product_category' => ProductCategory::StarterKit->value]);

    // Create orders for baseline year (2025) — every month
    for ($month = 1; $month <= 12; $month++) {
        $m = str_pad($month, 2, '0', STR_PAD_LEFT);

        // Acquisition orders
        $acqOrder = ShopifyOrder::factory()->create([
            'ordered_at' => "2025-{$m}-15",
            'financial_status' => 'PAID',
            'is_first_order' => true,
            'net_revenue' => 200,
            'customer_id' => ShopifyCustomer::factory()->create()->id,
        ]);
        ShopifyLineItem::factory()->create([
            'order_id' => $acqOrder->id,
            'product_id' => $kitProduct->id,
            'quantity' => 1,
            'price' => 200,
        ]);

        // Repeat orders
        $repOrder = ShopifyOrder::factory()->create([
            'ordered_at' => "2025-{$m}-20",
            'financial_status' => 'PAID',
            'is_first_order' => false,
            'net_revenue' => 30,
            'customer_id' => $customer->id,
        ]);
        ShopifyLineItem::factory()->create([
            'order_id' => $repOrder->id,
            'product_id' => $waxProduct->id,
            'quantity' => 1,
            'price' => 30,
        ]);
    }

    // Create Q1 2026 actuals
    for ($month = 1; $month <= 3; $month++) {
        $m = str_pad($month, 2, '0', STR_PAD_LEFT);
        $acqOrder = ShopifyOrder::factory()->create([
            'ordered_at' => "2026-{$m}-15",
            'financial_status' => 'PAID',
            'is_first_order' => true,
            'net_revenue' => 220,
            'customer_id' => ShopifyCustomer::factory()->create()->id,
        ]);
        ShopifyLineItem::factory()->create([
            'order_id' => $acqOrder->id,
            'product_id' => $kitProduct->id,
            'quantity' => 1,
            'price' => 220,
        ]);
    }

    // Create scenario with assumptions
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

    // Create product mixes (shares must sum to ~1.0 per type)
    ScenarioProductMix::create([
        'scenario_id' => $scenario->id,
        'product_category' => ProductCategory::StarterKit->value,
        'acq_share' => 0.65,
        'repeat_share' => 0.35,
        'avg_unit_price' => 200.00,
    ]);
    ScenarioProductMix::create([
        'scenario_id' => $scenario->id,
        'product_category' => ProductCategory::WaxTablet->value,
        'acq_share' => 0.35,
        'repeat_share' => 0.65,
        'avg_unit_price' => 30.00,
    ]);

    // Create seasonal indices (flat = 1.0 for simplicity)
    foreach ([ProductCategory::StarterKit, ProductCategory::WaxTablet] as $cat) {
        for ($m = 1; $m <= 12; $m++) {
            SeasonalIndex::create([
                'month' => $m,
                'region' => null,
                'product_category' => $cat->value,
                'forecast_group' => null,
                'index_value' => 1.0,
                'source' => 'test',
            ]);
        }
    }

    // Create group indices as fallback
    foreach (ForecastGroup::cases() as $group) {
        for ($m = 1; $m <= 12; $m++) {
            SeasonalIndex::create([
                'month' => $m,
                'region' => null,
                'product_category' => null,
                'forecast_group' => $group->value,
                'index_value' => 1.0,
                'source' => 'test',
            ]);
        }
    }

    return $scenario;
}

it('generates a full year forecast', function () {
    $scenario = setupForecastScenario();
    $service = app(DemandForecastService::class);

    $forecast = $service->forecastYear($scenario, 2026);

    expect($forecast)->toHaveCount(12);

    // Q1 months should have data (based on actuals)
    expect($forecast[1])->not->toBeEmpty();
    expect($forecast[2])->not->toBeEmpty();
    expect($forecast[3])->not->toBeEmpty();

    // Q2+ months should have forecasted values
    expect($forecast[4])->not->toBeEmpty();

    // Check that starter_kit and wax_tablet are both present
    $aprilData = $forecast[4];
    expect($aprilData)->toHaveKey(ProductCategory::StarterKit->value)
        ->and($aprilData)->toHaveKey(ProductCategory::WaxTablet->value);

    // StarterKit should have more revenue (higher acq_share)
    expect($aprilData[ProductCategory::StarterKit->value]['revenue'])
        ->toBeGreaterThan($aprilData[ProductCategory::WaxTablet->value]['revenue']);
});

it('generates total forecast with year summary', function () {
    $scenario = setupForecastScenario();
    $service = app(DemandForecastService::class);

    $total = $service->totalForecast($scenario, 2026);

    expect($total)->toHaveKey('months')
        ->and($total)->toHaveKey('year_total')
        ->and($total['months'])->toHaveCount(12)
        ->and($total['year_total']['units'])->toBeGreaterThan(0)
        ->and($total['year_total']['revenue'])->toBeGreaterThan(0);

    // Sum of months should equal year total
    $monthlySum = collect($total['months'])->sum('revenue');
    expect(round($monthlySum, 2))->toBe($total['year_total']['revenue']);
});

it('applies pull-forward only to Getting Started categories', function () {
    $scenario = setupForecastScenario();

    // Create a planned event with pull-forward
    $eventService = app(DemandEventService::class);
    $eventService->createWithCategories(
        [
            'name' => 'Planned BF',
            'type' => DemandEventType::PromoCampaign,
            'start_date' => '2026-11-20',
            'end_date' => '2026-11-30',
            'is_historical' => false,
        ],
        [
            [
                'product_category' => ProductCategory::StarterKit->value,
                'expected_uplift_units' => 100,
                'pull_forward_pct' => 30,
            ],
            [
                'product_category' => ProductCategory::WaxTablet->value,
                'expected_uplift_units' => 200,
                'pull_forward_pct' => 0,
            ],
        ],
    );

    $service = app(DemandForecastService::class);
    $forecast = $service->forecastYear($scenario, 2026);

    // November StarterKit should have event boost
    $novKit = $forecast[11][ProductCategory::StarterKit->value] ?? null;
    expect($novKit)->not->toBeNull()
        ->and($novKit['event_boost'])->toBeGreaterThan(0);

    // December StarterKit should have pull-forward deduction
    $decKit = $forecast[12][ProductCategory::StarterKit->value] ?? null;
    expect($decKit)->not->toBeNull()
        ->and($decKit['pull_forward'])->toBeGreaterThan(0);

    // December WaxTablet should NOT have pull-forward (not Getting Started)
    $decWax = $forecast[12][ProductCategory::WaxTablet->value] ?? null;
    expect($decWax)->not->toBeNull()
        ->and($decWax['pull_forward'])->toBe(0.0);
});

it('handles scenario without product mixes gracefully', function () {
    $scenario = Scenario::factory()->create(['year' => 2026]);
    ScenarioAssumption::factory()->create([
        'scenario_id' => $scenario->id,
        'quarter' => 'Q2',
        'acq_rate' => 1.0,
        'repeat_rate' => 0.20,
        'repeat_aov' => 80.00,
    ]);

    // No product mixes created

    $service = app(DemandForecastService::class);
    $forecast = $service->forecastYear($scenario, 2026);

    expect($forecast)->toHaveCount(12);
    // All months should be empty arrays (no product mixes)
    foreach ($forecast as $monthData) {
        expect($monthData)->toBeEmpty();
    }
});

it('throws InvalidProductMixException when acq_share sum exceeds tolerance', function () {
    $scenario = setupForecastScenario();

    // Override mixes with shares that sum to 1.30
    ScenarioProductMix::where('scenario_id', $scenario->id)->delete();
    ScenarioProductMix::create([
        'scenario_id' => $scenario->id,
        'product_category' => ProductCategory::StarterKit->value,
        'acq_share' => 0.80,
        'repeat_share' => 0.50,
        'avg_unit_price' => 200.00,
    ]);
    ScenarioProductMix::create([
        'scenario_id' => $scenario->id,
        'product_category' => ProductCategory::WaxTablet->value,
        'acq_share' => 0.50,
        'repeat_share' => 0.50,
        'avg_unit_price' => 30.00,
    ]);

    $service = app(DemandForecastService::class);

    expect(fn () => $service->forecastYear($scenario->fresh(), 2026))
        ->toThrow(InvalidProductMixException::class, 'acq_share');
});

it('throws InvalidProductMixException when repeat_share sum is below tolerance', function () {
    $scenario = setupForecastScenario();

    // Override mixes with repeat shares that sum to 0.30
    ScenarioProductMix::where('scenario_id', $scenario->id)->delete();
    ScenarioProductMix::create([
        'scenario_id' => $scenario->id,
        'product_category' => ProductCategory::StarterKit->value,
        'acq_share' => 0.50,
        'repeat_share' => 0.10,
        'avg_unit_price' => 200.00,
    ]);
    ScenarioProductMix::create([
        'scenario_id' => $scenario->id,
        'product_category' => ProductCategory::WaxTablet->value,
        'acq_share' => 0.50,
        'repeat_share' => 0.20,
        'avg_unit_price' => 30.00,
    ]);

    $service = app(DemandForecastService::class);

    expect(fn () => $service->forecastYear($scenario->fresh(), 2026))
        ->toThrow(InvalidProductMixException::class, 'repeat_share');
});

it('throws InvalidProductMixException when individual share is out of range', function () {
    $scenario = setupForecastScenario();

    ScenarioProductMix::where('scenario_id', $scenario->id)->delete();
    ScenarioProductMix::create([
        'scenario_id' => $scenario->id,
        'product_category' => ProductCategory::StarterKit->value,
        'acq_share' => 1.50,
        'repeat_share' => 0.50,
        'avg_unit_price' => 200.00,
    ]);
    ScenarioProductMix::create([
        'scenario_id' => $scenario->id,
        'product_category' => ProductCategory::WaxTablet->value,
        'acq_share' => -0.50,
        'repeat_share' => 0.50,
        'avg_unit_price' => 30.00,
    ]);

    $service = app(DemandForecastService::class);

    expect(fn () => $service->forecastYear($scenario->fresh(), 2026))
        ->toThrow(InvalidProductMixException::class, 'out of valid range');
});

it('throws InsufficientBaselineException when no Q1 data exists', function () {
    // Create scenario without any order data
    $scenario = Scenario::factory()->create(['year' => 2026]);
    ScenarioAssumption::factory()->create([
        'scenario_id' => $scenario->id,
        'quarter' => 'Q2',
        'acq_rate' => 1.0,
        'repeat_rate' => 0.20,
        'repeat_aov' => 80.00,
    ]);
    ScenarioProductMix::create([
        'scenario_id' => $scenario->id,
        'product_category' => ProductCategory::StarterKit->value,
        'acq_share' => 0.50,
        'repeat_share' => 0.50,
        'avg_unit_price' => 200.00,
    ]);
    ScenarioProductMix::create([
        'scenario_id' => $scenario->id,
        'product_category' => ProductCategory::WaxTablet->value,
        'acq_share' => 0.50,
        'repeat_share' => 0.50,
        'avg_unit_price' => 30.00,
    ]);

    $service = app(DemandForecastService::class);

    expect(fn () => $service->forecastYear($scenario, 2026))
        ->toThrow(InsufficientBaselineException::class);
});

it('logs warning when Q1 data is partial', function () {
    $scenario = setupForecastScenario();

    // Delete Jan and Feb orders for 2026, keeping only March
    ShopifyOrder::where('ordered_at', 'like', '2026-01%')
        ->orWhere('ordered_at', 'like', '2026-02%')
        ->delete();

    Log::shouldReceive('warning')
        ->once()
        ->withArgs(fn (string $msg) => str_contains($msg, 'Incomplete Q1'));

    Log::shouldReceive('warning')
        ->zeroOrMoreTimes()
        ->withArgs(fn (string $msg) => str_contains($msg, 'AOV consistency'));

    $service = app(DemandForecastService::class);
    $forecast = $service->forecastYear($scenario->fresh(), 2026);

    // Should still produce a forecast (March data available)
    expect($forecast)->toHaveCount(12);
});

it('uses cohort-based repeat model when retention curve is available', function () {
    $scenario = setupForecastScenario();

    // Mock CohortProjectionService to return a known retention curve
    $retentionCurve = [
        1 => 5.0,   // 5% cumulative at month 1
        2 => 8.0,
        3 => 10.0,
        6 => 15.0,
        12 => 20.0,
    ];

    $realService = app(CohortProjectionService::class);
    $mock = Mockery::mock(CohortProjectionService::class);
    $mock->shouldReceive('retentionCurve')
        ->andReturn(['months' => $retentionCurve, 'cohorts_used' => 6, 'avg_cohort_size' => 50]);
    $mock->shouldReceive('monthlyRetentionRate')
        ->andReturnUsing(fn (int $age, array $curve) => $realService->monthlyRetentionRate($age, $curve));

    app()->instance(CohortProjectionService::class, $mock);

    $service = app(DemandForecastService::class);
    $forecast = $service->forecastYear($scenario, 2026);

    expect($forecast)->toHaveCount(12);

    // Q2 months should have data driven by cohort retention
    $aprilData = $forecast[4];
    expect($aprilData)->not->toBeEmpty();

    // Repeat revenue should be present — cohort model produces repeat from Q1 cohorts aging
    $totalRepeatRevenue = 0;
    for ($month = 4; $month <= 12; $month++) {
        $monthRevenue = collect($forecast[$month])->sum('revenue');
        $totalRepeatRevenue += $monthRevenue;
    }

    expect($totalRepeatRevenue)->toBeGreaterThan(0);
});

it('cohort and flat models produce different repeat revenue', function () {
    $scenario = setupForecastScenario();

    $retentionCurve = [
        1 => 5.0,
        2 => 8.0,
        3 => 10.0,
        6 => 15.0,
        12 => 20.0,
    ];

    // Run with cohort model
    $realService = app(CohortProjectionService::class);
    $cohortMock = Mockery::mock(CohortProjectionService::class);
    $cohortMock->shouldReceive('retentionCurve')
        ->andReturn(['months' => $retentionCurve, 'cohorts_used' => 6, 'avg_cohort_size' => 50]);
    $cohortMock->shouldReceive('monthlyRetentionRate')
        ->andReturnUsing(fn (int $age, array $curve) => $realService->monthlyRetentionRate($age, $curve));

    app()->instance(CohortProjectionService::class, $cohortMock);
    $cohortForecast = app(DemandForecastService::class)->totalForecast($scenario, 2026);

    // Run with flat model (empty curve)
    $flatMock = Mockery::mock(CohortProjectionService::class);
    $flatMock->shouldReceive('retentionCurve')
        ->andReturn(['months' => [], 'cohorts_used' => 0, 'avg_cohort_size' => 0]);

    app()->instance(CohortProjectionService::class, $flatMock);
    $flatForecast = app(DemandForecastService::class)->totalForecast($scenario->fresh(), 2026);

    // Both produce positive revenue
    expect($cohortForecast['year_total']['revenue'])->toBeGreaterThan(0);
    expect($flatForecast['year_total']['revenue'])->toBeGreaterThan(0);

    // But different values — proves the model actually differs
    expect($cohortForecast['year_total']['revenue'])
        ->not->toBe($flatForecast['year_total']['revenue']);
});

it('curve adjustment scales cohort repeat revenue', function () {
    $scenario = setupForecastScenario();

    $retentionCurve = [
        1 => 5.0,
        2 => 8.0,
        3 => 10.0,
        6 => 15.0,
        12 => 20.0,
    ];

    $realService = app(CohortProjectionService::class);

    // Run with adjustment = 1.0
    $scenario->update(['retention_curve_adjustment' => 1.00]);
    $mock1 = Mockery::mock(CohortProjectionService::class);
    $mock1->shouldReceive('retentionCurve')
        ->andReturn(['months' => $retentionCurve, 'cohorts_used' => 6, 'avg_cohort_size' => 50]);
    $mock1->shouldReceive('monthlyRetentionRate')
        ->andReturnUsing(fn (int $age, array $curve) => $realService->monthlyRetentionRate($age, $curve));

    app()->instance(CohortProjectionService::class, $mock1);
    $baseRevenue = app(DemandForecastService::class)->totalForecast($scenario->fresh(), 2026)['year_total']['revenue'];

    // Run with adjustment = 1.50 (optimistic)
    $scenario->update(['retention_curve_adjustment' => 1.50]);
    $mock2 = Mockery::mock(CohortProjectionService::class);
    $mock2->shouldReceive('retentionCurve')
        ->andReturn(['months' => $retentionCurve, 'cohorts_used' => 6, 'avg_cohort_size' => 50]);
    $mock2->shouldReceive('monthlyRetentionRate')
        ->andReturnUsing(fn (int $age, array $curve) => $realService->monthlyRetentionRate($age, $curve));

    app()->instance(CohortProjectionService::class, $mock2);
    $optimisticRevenue = app(DemandForecastService::class)->totalForecast($scenario->fresh(), 2026)['year_total']['revenue'];

    // Optimistic should produce more revenue
    expect($optimisticRevenue)->toBeGreaterThan($baseRevenue);
});

it('falls back to flat repeat model when no retention curve exists', function () {
    $scenario = setupForecastScenario();

    // Mock CohortProjectionService to return empty curve
    $mock = Mockery::mock(CohortProjectionService::class);
    $mock->shouldReceive('retentionCurve')
        ->andReturn(['months' => [], 'cohorts_used' => 0, 'avg_cohort_size' => 0]);

    app()->instance(CohortProjectionService::class, $mock);

    $service = app(DemandForecastService::class);
    $forecast = $service->forecastYear($scenario, 2026);

    expect($forecast)->toHaveCount(12);

    // Should still produce forecast data using flat model
    expect($forecast[4])->not->toBeEmpty();

    $totalRevenue = 0;
    for ($month = 4; $month <= 12; $month++) {
        $totalRevenue += collect($forecast[$month])->sum('revenue');
    }

    expect($totalRevenue)->toBeGreaterThan(0);
});
