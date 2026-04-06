<?php

use App\Enums\ForecastGroup;
use App\Enums\ProductCategory;
use App\Models\Product;
use App\Models\Scenario;
use App\Models\ScenarioAssumption;
use App\Models\ScenarioProductMix;
use App\Models\SeasonalIndex;
use App\Models\ShopifyCustomer;
use App\Models\ShopifyLineItem;
use App\Models\ShopifyOrder;
use App\Services\Forecast\Demand\CohortProjectionService;
use App\Services\Forecast\Demand\DemandForecastService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

function setupBaselineAnomalyScenario(array $q1Overrides = []): Scenario
{
    $customer = ShopifyCustomer::factory()->create();
    $waxProduct = Product::factory()->create(['product_category' => ProductCategory::WaxTablet->value]);
    $kitProduct = Product::factory()->create(['product_category' => ProductCategory::StarterKit->value]);

    // 2025 baseline: consistent 200 acq_rev + 30 rep_rev per month
    for ($month = 1; $month <= 12; $month++) {
        $m = str_pad($month, 2, '0', STR_PAD_LEFT);

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

    // Q1 2026: normal by default, with overrides for specific months
    for ($month = 1; $month <= 3; $month++) {
        $m = str_pad($month, 2, '0', STR_PAD_LEFT);
        $acqRev = $q1Overrides[$month]['acq_rev'] ?? 220;
        $newCustId = ShopifyCustomer::factory()->create()->id;

        $acqOrder = ShopifyOrder::factory()->create([
            'ordered_at' => "2026-{$m}-15",
            'financial_status' => 'PAID',
            'is_first_order' => true,
            'net_revenue' => $acqRev,
            'customer_id' => $newCustId,
        ]);
        ShopifyLineItem::factory()->create([
            'order_id' => $acqOrder->id,
            'product_id' => $kitProduct->id,
            'quantity' => 1,
            'price' => $acqRev,
        ]);
    }

    $scenario = Scenario::factory()->create(['year' => 2026]);
    foreach (['Q2', 'Q3', 'Q4'] as $quarter) {
        ScenarioAssumption::factory()->create([
            'scenario_id' => $scenario->id,
            'quarter' => $quarter,
            'acq_rate' => 1.00,
            'repeat_rate' => 0.25,
            'repeat_aov' => 85.00,
        ]);
    }

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

it('logs warning when Q1 month has anomalous spike', function () {
    // February 2026: 600 acq_rev vs 200 baseline = 200% deviation (>30%)
    $scenario = setupBaselineAnomalyScenario([
        2 => ['acq_rev' => 600],
    ]);

    $mock = Mockery::mock(CohortProjectionService::class);
    $mock->shouldReceive('retentionCurve')
        ->andReturn(['months' => [], 'cohorts_used' => 0, 'avg_cohort_size' => 0]);
    app()->instance(CohortProjectionService::class, $mock);

    Log::spy();

    $service = app(DemandForecastService::class);
    $service->forecastYear($scenario->fresh(), 2026);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $msg, array $ctx) => str_contains($msg, 'Q1 baseline anomaly')
            && ($ctx['metric'] ?? '') === 'acq_rev'
            && ($ctx['month'] ?? '') === '2026-02')
        ->atLeast()->once();
});

it('does not log anomaly warning when Q1 acq_rev is within threshold', function () {
    // All Q1 months are 220 vs 200 baseline = 10% deviation (<30%)
    $scenario = setupBaselineAnomalyScenario();

    $mock = Mockery::mock(CohortProjectionService::class);
    $mock->shouldReceive('retentionCurve')
        ->andReturn(['months' => [], 'cohorts_used' => 0, 'avg_cohort_size' => 0]);
    app()->instance(CohortProjectionService::class, $mock);

    Log::spy();

    $service = app(DemandForecastService::class);
    $service->forecastYear($scenario->fresh(), 2026);

    // acq_rev: 220 vs 200 = 10% deviation → no anomaly for acq_rev
    Log::shouldNotHaveReceived('warning', fn (string $msg, array $ctx) => str_contains($msg, 'Q1 baseline anomaly') && ($ctx['metric'] ?? '') === 'acq_rev');
});
