<?php

use App\Enums\ForecastRegion;
use App\Models\ShopifyCustomer;
use App\Models\ShopifyOrder;
use App\Services\Forecast\Demand\CohortProjectionService;

function createCohortCustomer(string $countryCode, string $firstOrderDate, array $repeatDates = []): void
{
    $customer = ShopifyCustomer::factory()->create([
        'first_order_at' => $firstOrderDate,
    ]);

    // First order
    ShopifyOrder::factory()->create([
        'customer_id' => $customer->id,
        'ordered_at' => $firstOrderDate,
        'financial_status' => 'PAID',
        'is_first_order' => true,
        'shipping_country_code' => $countryCode,
        'billing_country_code' => $countryCode,
    ]);

    // Repeat orders
    foreach ($repeatDates as $date) {
        ShopifyOrder::factory()->create([
            'customer_id' => $customer->id,
            'ordered_at' => $date,
            'financial_status' => 'PAID',
            'is_first_order' => false,
            'shipping_country_code' => $countryCode,
            'billing_country_code' => $countryCode,
        ]);
    }
}

it('builds regional cohort data filtered by country', function () {
    // DE cohort: 3 customers, 2 repeat
    createCohortCustomer('DE', '2025-06-01', ['2025-07-15']);
    createCohortCustomer('DE', '2025-06-05', ['2025-08-10']);
    createCohortCustomer('DE', '2025-06-10');

    // BE cohort: 2 customers, 0 repeat
    createCohortCustomer('BE', '2025-06-01');
    createCohortCustomer('BE', '2025-06-05');

    $service = app(CohortProjectionService::class);

    $deData = $service->buildCohortData(12, ForecastRegion::De);
    $beData = $service->buildCohortData(12, ForecastRegion::Be);

    // DE should have 3 customers in one cohort
    expect($deData['cohorts'])->toHaveCount(1)
        ->and($deData['cohorts'][0]['size'])->toBe(3);

    // BE should have 2 customers in one cohort
    expect($beData['cohorts'])->toHaveCount(1)
        ->and($beData['cohorts'][0]['size'])->toBe(2);
});

it('groups multi-country regions in cohort data', function () {
    // AT + CH = EU_ALPINE
    createCohortCustomer('AT', '2025-06-01', ['2025-07-15']);
    createCohortCustomer('CH', '2025-06-05');

    $service = app(CohortProjectionService::class);

    $alpineData = $service->buildCohortData(12, ForecastRegion::EuAlpine);

    expect($alpineData['cohorts'])->toHaveCount(1)
        ->and($alpineData['cohorts'][0]['size'])->toBe(2);
});

it('falls back to global curve when region has too few cohorts', function () {
    // Create enough global data (4 cohorts of 15+ customers each)
    foreach (['2025-03', '2025-04', '2025-05', '2025-06'] as $month) {
        foreach (range(1, 15) as $i) {
            createCohortCustomer('DE', "{$month}-".str_pad($i, 2, '0', STR_PAD_LEFT), [
                "{$month}-28", // repeat within same month-ish
            ]);
        }
    }

    // ROW has only 2 customers (way below threshold)
    createCohortCustomer('AU', '2025-06-01', ['2025-07-15']);
    createCohortCustomer('SG', '2025-06-05');

    $service = app(CohortProjectionService::class);

    $rowCurve = $service->retentionCurve(12, ForecastRegion::Row);

    expect($rowCurve['source'])->toBe('global_fallback');
});

it('uses own curve when region has enough qualified cohorts', function () {
    // Create 4 monthly cohorts of 12 DE customers each, some with repeats
    foreach (['2025-03', '2025-04', '2025-05', '2025-06'] as $month) {
        foreach (range(1, 12) as $i) {
            $repeatDates = $i <= 3 ? ["{$month}-28"] : [];
            createCohortCustomer('DE', "{$month}-".str_pad($i, 2, '0', STR_PAD_LEFT), $repeatDates);
        }
    }

    $service = app(CohortProjectionService::class);

    $deCurve = $service->retentionCurve(12, ForecastRegion::De);

    expect($deCurve['source'])->toBe('regional:de')
        ->and($deCurve['cohorts_used'])->toBeGreaterThanOrEqual(3)
        ->and($deCurve['months'])->not->toBeEmpty();
});

it('returns source field indicating curve origin', function () {
    // Minimal global data
    foreach (range(1, 15) as $i) {
        createCohortCustomer('DE', '2025-06-'.str_pad($i, 2, '0', STR_PAD_LEFT));
    }

    $service = app(CohortProjectionService::class);

    $globalCurve = $service->retentionCurve(12);
    expect($globalCurve['source'])->toBe('global');
});
