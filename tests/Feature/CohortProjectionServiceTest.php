<?php

use App\Services\CohortProjectionService;
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
