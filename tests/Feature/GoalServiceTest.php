<?php

use App\Enums\Team;
use App\Models\KeyResult;
use App\Models\Objective;
use App\Services\Forecast\Tracking\GoalService;

it('returns the full OKR tree for a year', function () {
    $companyObj = Objective::factory()->company()->create(['year' => 2026]);

    $companyKr = KeyResult::factory()->create([
        'objective_id' => $companyObj->id,
        'title' => 'Grow customer base to 5000',
        'target_value' => 5000,
        'unit' => 'count',
    ]);

    $teamObj = Objective::factory()->forTeam(Team::CustomerSuccess)->create([
        'year' => 2026,
        'parent_key_result_id' => $companyKr->id,
    ]);

    KeyResult::factory()->create([
        'objective_id' => $teamObj->id,
        'title' => 'Acquire 2800 new customers',
        'target_value' => 2800,
        'unit' => 'count',
    ]);

    $service = app(GoalService::class);
    $tree = $service->dashboard(2026);

    expect($tree)->toHaveCount(1)
        ->and($tree->first()->keyResults)->toHaveCount(1)
        ->and($tree->first()->keyResults->first()->childObjective)->not->toBeNull()
        ->and($tree->first()->keyResults->first()->childObjective->keyResults)->toHaveCount(1);
});

it('calculates company overview with rollup progress', function () {
    $obj = Objective::factory()->company()->create(['year' => 2026]);

    KeyResult::factory()->create([
        'objective_id' => $obj->id,
        'target_value' => 1000,
        'current_value' => 500,
        'unit' => 'count',
    ]);

    KeyResult::factory()->create([
        'objective_id' => $obj->id,
        'target_value' => 100,
        'current_value' => 80,
        'unit' => 'percentage',
    ]);

    $service = app(GoalService::class);
    $overview = $service->companyOverview(2026);

    expect($overview)->toHaveCount(1);

    // (0.5 + 0.8) / 2 = 0.65
    expect($overview[0]['progress'])->toBe(0.65);
});

it('calculates key result progress correctly', function () {
    $kr = KeyResult::factory()->make([
        'target_value' => 2800,
        'current_value' => 1400,
    ]);

    expect($kr->progress())->toBe(0.5);
});

it('caps progress at 1.0', function () {
    $kr = KeyResult::factory()->make([
        'target_value' => 100,
        'current_value' => 150,
    ]);

    expect($kr->progress())->toBe(1.0);
});

it('handles zero target gracefully', function () {
    $kr = KeyResult::factory()->make([
        'target_value' => 0,
        'current_value' => 50,
    ]);

    expect($kr->progress())->toBe(0.0);
});

it('identifies auto-tracked key results', function () {
    $auto = KeyResult::factory()->make([
        'tracking_mode' => 'auto',
        'metric_key' => 'new_customers',
    ]);

    $manual = KeyResult::factory()->make([
        'tracking_mode' => 'manual',
        'metric_key' => null,
    ]);

    expect($auto->isAutoTracked())->toBeTrue()
        ->and($manual->isAutoTracked())->toBeFalse();
});

it('scopes objectives by company and team', function () {
    Objective::factory()->company()->create(['year' => 2026]);
    Objective::factory()->forTeam(Team::Brand)->create(['year' => 2026]);
    Objective::factory()->forTeam(Team::Finance)->create(['year' => 2026]);

    expect(Objective::company()->count())->toBe(1)
        ->and(Objective::forTeam(Team::Brand)->count())->toBe(1)
        ->and(Objective::forYear(2026)->count())->toBe(3);
});
