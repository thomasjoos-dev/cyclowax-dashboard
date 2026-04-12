<?php

use App\Enums\ForecastRegion;
use App\Models\ShopifyCustomer;
use App\Models\ShopifyOrder;
use App\Services\Forecast\Demand\SalesBaselineService;

function createRegionalOrders(string $countryCode, int $count, string $month, bool $isFirstOrder = true, float $revenue = 100.0): void
{
    foreach (range(1, $count) as $i) {
        ShopifyOrder::factory()->create([
            'ordered_at' => "2025-{$month}-".str_pad(min($i, 28), 2, '0', STR_PAD_LEFT),
            'financial_status' => 'PAID',
            'is_first_order' => $isFirstOrder,
            'net_revenue' => $revenue,
            'shipping_country_code' => $countryCode,
            'billing_country_code' => $countryCode,
            'customer_id' => ShopifyCustomer::factory()->create()->id,
        ]);
    }
}

it('filters monthly actuals by forecast region', function () {
    createRegionalOrders('DE', 5, '03', true, 200.0);
    createRegionalOrders('BE', 3, '03', true, 150.0);
    createRegionalOrders('US', 2, '03', true, 300.0);

    $service = app(SalesBaselineService::class);

    $deActuals = $service->monthlyActuals('2025-01-01', '2025-04-01', ForecastRegion::De);
    $beActuals = $service->monthlyActuals('2025-01-01', '2025-04-01', ForecastRegion::Be);
    $usActuals = $service->monthlyActuals('2025-01-01', '2025-04-01', ForecastRegion::Us);
    $globalActuals = $service->monthlyActuals('2025-01-01', '2025-04-01');

    expect($deActuals)->toHaveCount(1)
        ->and((float) $deActuals[0]['acq_rev'])->toBe(1000.0)
        ->and((int) $deActuals[0]['new_customers'])->toBe(5)
        ->and($beActuals)->toHaveCount(1)
        ->and((float) $beActuals[0]['acq_rev'])->toBe(450.0)
        ->and($usActuals)->toHaveCount(1)
        ->and((float) $usActuals[0]['acq_rev'])->toBe(600.0)
        ->and($globalActuals)->toHaveCount(1)
        ->and((float) $globalActuals[0]['acq_rev'])->toBe(2050.0);
});

it('groups multi-country regions correctly', function () {
    createRegionalOrders('AT', 3, '06', true, 100.0);
    createRegionalOrders('CH', 2, '06', true, 200.0);

    $service = app(SalesBaselineService::class);

    $alpineActuals = $service->periodActuals('2025-01-01', '2025-07-01', ForecastRegion::EuAlpine);

    expect($alpineActuals['new_customers'])->toBe(5)
        ->and($alpineActuals['acq_rev'])->toBe(700);
});

it('handles ROW region as exclusion of all mapped countries', function () {
    createRegionalOrders('DE', 2, '04', true, 100.0);
    createRegionalOrders('AU', 3, '04', true, 150.0);
    createRegionalOrders('SG', 1, '04', true, 200.0);

    $service = app(SalesBaselineService::class);

    $rowActuals = $service->periodActuals('2025-01-01', '2025-05-01', ForecastRegion::Row);

    // AU and SG are not in any mapped region → ROW
    expect($rowActuals['new_customers'])->toBe(4)
        ->and($rowActuals['acq_rev'])->toBe(650);
});

it('splits acquisition and repeat revenue per region', function () {
    createRegionalOrders('DE', 3, '02', true, 200.0);
    createRegionalOrders('DE', 2, '02', false, 80.0);
    createRegionalOrders('NL', 1, '02', true, 150.0);
    createRegionalOrders('NL', 4, '02', false, 60.0);

    $service = app(SalesBaselineService::class);

    $deActuals = $service->periodActuals('2025-01-01', '2025-03-01', ForecastRegion::De);
    $nlActuals = $service->periodActuals('2025-01-01', '2025-03-01', ForecastRegion::Nl);

    expect($deActuals['acq_rev'])->toBe(600)
        ->and($deActuals['rep_rev'])->toBe(160)
        ->and($deActuals['new_customers'])->toBe(3)
        ->and($deActuals['repeat_orders'])->toBe(2)
        ->and($nlActuals['acq_rev'])->toBe(150)
        ->and($nlActuals['rep_rev'])->toBe(240)
        ->and($nlActuals['new_customers'])->toBe(1)
        ->and($nlActuals['repeat_orders'])->toBe(4);
});

it('returns sum of regions equal to global', function () {
    createRegionalOrders('DE', 5, '01', true, 100.0);
    createRegionalOrders('BE', 3, '01', true, 100.0);
    createRegionalOrders('US', 2, '01', true, 100.0);
    createRegionalOrders('GB', 1, '01', true, 100.0);
    createRegionalOrders('NL', 1, '01', true, 100.0);
    createRegionalOrders('AT', 1, '01', true, 100.0);
    createRegionalOrders('DK', 1, '01', true, 100.0);
    createRegionalOrders('FR', 1, '01', true, 100.0);
    createRegionalOrders('AU', 1, '01', true, 100.0);

    $service = app(SalesBaselineService::class);
    $from = '2025-01-01';
    $to = '2025-02-01';

    $globalActuals = $service->periodActuals($from, $to);

    $regionalSum = 0;
    foreach (ForecastRegion::cases() as $region) {
        $regionalSum += $service->periodActuals($from, $to, $region)['total_rev'];
    }

    expect($regionalSum)->toBe($globalActuals['total_rev']);
});
