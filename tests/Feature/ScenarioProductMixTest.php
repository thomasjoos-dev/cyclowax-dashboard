<?php

use App\Enums\ForecastRegion;
use App\Enums\ProductCategory;
use App\Models\Product;
use App\Models\Scenario;
use App\Models\ScenarioProductMix;
use Illuminate\Database\UniqueConstraintViolationException;

it('belongs to a scenario', function () {
    $scenario = Scenario::factory()->create();
    $mix = ScenarioProductMix::factory()->waxTablet()->create([
        'scenario_id' => $scenario->id,
        'acq_share' => 0.15,
        'repeat_share' => 0.35,
        'avg_unit_price' => 27.50,
    ]);

    expect($mix->scenario->id)->toBe($scenario->id)
        ->and($mix->product_category)->toBe(ProductCategory::WaxTablet);
});

it('loads product mixes from scenario', function () {
    $scenario = Scenario::factory()->create();

    ScenarioProductMix::factory()->starterKit()->create([
        'scenario_id' => $scenario->id,
        'acq_share' => 0.40,
        'repeat_share' => 0.05,
        'avg_unit_price' => 220.00,
    ]);
    ScenarioProductMix::factory()->waxTablet()->create([
        'scenario_id' => $scenario->id,
        'acq_share' => 0.10,
        'repeat_share' => 0.35,
        'avg_unit_price' => 27.50,
    ]);

    $scenario->load('productMixes');

    expect($scenario->productMixes)->toHaveCount(2);
});

it('enforces unique constraint on scenario, category, region and product', function () {
    $scenario = Scenario::factory()->create();
    $product = Product::factory()->create(['product_category' => ProductCategory::Chain->value]);

    ScenarioProductMix::factory()->chain()->forRegion(ForecastRegion::De)->create([
        'scenario_id' => $scenario->id,
        'product_id' => $product->id,
        'acq_share' => 0.10,
        'repeat_share' => 0.30,
        'avg_unit_price' => 85.00,
    ]);

    expect(fn () => ScenarioProductMix::factory()->chain()->forRegion(ForecastRegion::De)->create([
        'scenario_id' => $scenario->id,
        'product_id' => $product->id,
        'acq_share' => 0.20,
        'repeat_share' => 0.40,
        'avg_unit_price' => 90.00,
    ]))->toThrow(UniqueConstraintViolationException::class);
});

it('allows same category for different regions', function () {
    $scenario = Scenario::factory()->create();

    ScenarioProductMix::factory()->chain()->forRegion(ForecastRegion::De)->create([
        'scenario_id' => $scenario->id,
        'acq_share' => 0.10,
        'repeat_share' => 0.30,
        'avg_unit_price' => 85.00,
    ]);

    $mix = ScenarioProductMix::factory()->chain()->forRegion(ForecastRegion::Be)->create([
        'scenario_id' => $scenario->id,
        'acq_share' => 0.15,
        'repeat_share' => 0.35,
        'avg_unit_price' => 85.00,
    ]);

    expect($mix->exists)->toBeTrue();
});

it('cascades delete when scenario is deleted', function () {
    $scenario = Scenario::factory()->create();

    ScenarioProductMix::factory()->waxTablet()->create([
        'scenario_id' => $scenario->id,
        'acq_share' => 0.10,
        'repeat_share' => 0.35,
        'avg_unit_price' => 27.50,
    ]);

    $scenario->delete();

    expect(ScenarioProductMix::count())->toBe(0);
});
