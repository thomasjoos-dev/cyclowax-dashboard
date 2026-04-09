<?php

namespace Database\Factories;

use App\Enums\ProductCategory;
use App\Models\Scenario;
use App\Models\ScenarioProductMix;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScenarioProductMix>
 */
class ScenarioProductMixFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'scenario_id' => Scenario::factory(),
            'product_category' => fake()->randomElement(ProductCategory::cases()),
            'region' => null,
            'product_id' => null,
            'sku_share' => null,
            'acq_share' => fake()->randomFloat(4, 0, 1),
            'repeat_share' => fake()->randomFloat(4, 0, 1),
            'avg_unit_price' => fake()->randomFloat(2, 10, 200),
        ];
    }
}
