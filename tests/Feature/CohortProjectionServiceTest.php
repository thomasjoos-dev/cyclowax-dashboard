<?php

use App\Services\Forecast\Demand\CohortProjectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('projects cohort revenue based on retention curve', function () {
    $service = app(CohortProjectionService::class);

    $retentionCurve = [
        1 => 5.0,   // 5% repeat at month 1
        2 => 8.0,
        3 => 10.0,  // 10% cumulative at month 3
        6 => 15.0,
        12 => 20.0,
    ];

    $projection = $service->projectCohort(
        cohortSize: 1000,
        firstOrderAov: 150,
        repeatAov: 95,
        retentionCurve: $retentionCurve,
        months: 3,
    );

    expect($projection)->toHaveCount(3);

    // Month 1: 1000 * 5% = 50 repeaters → 50 * 95 = 4750 repeat rev
    expect($projection[1]['cumulative_repeaters'])->toBe(50)
        ->and($projection[1]['cumulative_repeat_revenue'])->toBe(4750.00)
        ->and($projection[1]['cumulative_total_revenue'])->toBe(154750.00); // 150000 + 4750

    // Month 3: 1000 * 10% = 100 repeaters → 100 * 95 = 9500 repeat rev
    expect($projection[3]['cumulative_repeaters'])->toBe(100)
        ->and($projection[3]['cumulative_repeat_revenue'])->toBe(9500.00);
});

it('interpolates retention rate for missing months', function () {
    $service = app(CohortProjectionService::class);

    $curve = [
        1 => 5.0,
        3 => 10.0,
        6 => 15.0,
        12 => 20.0,
    ];

    // Exact match
    expect($service->monthlyRetentionRate(1, $curve))->toBe(5.0);
    expect($service->monthlyRetentionRate(3, $curve))->toBe(10.0);

    // Interpolated: month 2 between 1→5.0 and 3→10.0
    expect($service->monthlyRetentionRate(2, $curve))->toBe(7.5);

    // Interpolated: month 4 between 3→10.0 and 6→15.0
    expect($service->monthlyRetentionRate(4, $curve))->toBe(11.67);

    // Interpolated: month 9 between 6→15.0 and 12→20.0
    expect($service->monthlyRetentionRate(9, $curve))->toBe(17.5);

    // After last point: plateau
    expect($service->monthlyRetentionRate(15, $curve))->toBe(20.0);
    expect($service->monthlyRetentionRate(24, $curve))->toBe(20.0);
});

it('returns zero retention for invalid month ages', function () {
    $service = app(CohortProjectionService::class);

    $curve = [1 => 5.0, 3 => 10.0];

    expect($service->monthlyRetentionRate(0, $curve))->toBe(0.0);
    expect($service->monthlyRetentionRate(-1, $curve))->toBe(0.0);
    expect($service->monthlyRetentionRate(1, []))->toBe(0.0);
});

it('handles empty retention curve gracefully', function () {
    $service = app(CohortProjectionService::class);

    $projection = $service->projectCohort(
        cohortSize: 500,
        firstOrderAov: 120,
        repeatAov: 90,
        retentionCurve: [],
        months: 3,
    );

    expect($projection)->toHaveCount(3);

    // With no retention curve, repeat revenue should be 0
    foreach ($projection as $month) {
        expect($month['cumulative_repeaters'])->toBe(0)
            ->and($month['cumulative_repeat_revenue'])->toBe(0.00);
    }
});
