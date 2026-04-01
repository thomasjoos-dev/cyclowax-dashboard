<?php

use App\Models\Scenario;
use App\Models\ScenarioAssumption;
use App\Services\Forecast\Tracking\ScenarioService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates a scenario with assumptions', function () {
    $service = app(ScenarioService::class);

    $scenario = $service->createWithAssumptions(
        ['name' => 'test', 'label' => 'Test', 'year' => 2026],
        [
            'Q2' => ['acq_rate' => 0.80, 'repeat_rate' => 0.20, 'repeat_aov' => 90],
            'Q3' => ['acq_rate' => 0.90, 'repeat_rate' => 0.25, 'repeat_aov' => 95],
            'Q4' => ['acq_rate' => 1.00, 'repeat_rate' => 0.30, 'repeat_aov' => 100],
        ],
    );

    expect($scenario)->toBeInstanceOf(Scenario::class)
        ->and($scenario->assumptions)->toHaveCount(3)
        ->and($scenario->name)->toBe('test')
        ->and($scenario->year)->toBe(2026);

    $q2 = $scenario->assumptions->firstWhere('quarter', 'Q2');
    expect((float) $q2->acq_rate)->toBe(0.8000)
        ->and((float) $q2->repeat_aov)->toBe(90.00);
});

it('converts scenario to forecast input format', function () {
    $service = app(ScenarioService::class);

    $scenario = $service->createWithAssumptions(
        ['name' => 'base', 'label' => 'Base', 'year' => 2026],
        [
            'Q2' => ['acq_rate' => 0.85, 'repeat_rate' => 0.25, 'repeat_aov' => 95],
            'Q3' => ['acq_rate' => 0.85, 'repeat_rate' => 0.25, 'repeat_aov' => 95],
            'Q4' => ['acq_rate' => 1.08, 'repeat_rate' => 0.25, 'repeat_aov' => 95],
        ],
    );

    $input = $service->toForecastInput($scenario);

    expect($input)->toHaveKeys(['Q2', 'Q3', 'Q4'])
        ->and($input['Q2'])->toBe(['acq_rate' => 0.85, 'repeat_rate' => 0.25, 'repeat_aov' => 95.0])
        ->and($input['Q4']['acq_rate'])->toBe(1.08);
});

it('returns only active scenarios for a year', function () {
    $service = app(ScenarioService::class);

    $service->createWithAssumptions(
        ['name' => 'active', 'label' => 'Active', 'year' => 2026, 'is_active' => true],
        ['Q2' => ['acq_rate' => 0.80, 'repeat_rate' => 0.20, 'repeat_aov' => 90]],
    );

    $service->createWithAssumptions(
        ['name' => 'inactive', 'label' => 'Inactive', 'year' => 2026, 'is_active' => false],
        ['Q2' => ['acq_rate' => 0.50, 'repeat_rate' => 0.10, 'repeat_aov' => 70]],
    );

    $service->createWithAssumptions(
        ['name' => 'other-year', 'label' => 'Other Year', 'year' => 2025, 'is_active' => true],
        ['Q2' => ['acq_rate' => 0.60, 'repeat_rate' => 0.15, 'repeat_aov' => 80]],
    );

    $results = $service->forYear(2026);

    expect($results)->toHaveCount(1)
        ->and($results->first()->name)->toBe('active');
});

it('updates a scenario and replaces assumptions', function () {
    $service = app(ScenarioService::class);

    $scenario = $service->createWithAssumptions(
        ['name' => 'original', 'label' => 'Original', 'year' => 2026],
        [
            'Q2' => ['acq_rate' => 0.70, 'repeat_rate' => 0.20, 'repeat_aov' => 85],
            'Q3' => ['acq_rate' => 0.70, 'repeat_rate' => 0.20, 'repeat_aov' => 85],
        ],
    );

    $updated = $service->updateWithAssumptions(
        $scenario,
        ['label' => 'Updated'],
        [
            'Q2' => ['acq_rate' => 0.90, 'repeat_rate' => 0.30, 'repeat_aov' => 100],
            'Q3' => ['acq_rate' => 0.95, 'repeat_rate' => 0.30, 'repeat_aov' => 100],
            'Q4' => ['acq_rate' => 1.00, 'repeat_rate' => 0.30, 'repeat_aov' => 100],
        ],
    );

    expect($updated->label)->toBe('Updated')
        ->and($updated->assumptions)->toHaveCount(3)
        ->and(ScenarioAssumption::where('scenario_id', $scenario->id)->count())->toBe(3);
});
