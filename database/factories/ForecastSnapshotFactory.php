<?php

namespace Database\Factories;

use App\Enums\ForecastRegion;
use App\Enums\ProductCategory;
use App\Models\ForecastSnapshot;
use App\Models\Scenario;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ForecastSnapshot>
 */
class ForecastSnapshotFactory extends Factory
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
            'year_month' => fake()->date('Y-m'),
            'product_category' => fake()->randomElement(ProductCategory::cases()),
            'region' => fake()->optional()->randomElement(ForecastRegion::cases()),
            'forecasted_units' => fake()->numberBetween(10, 5000),
            'forecasted_revenue' => fake()->randomFloat(2, 500, 100000),
            'actual_units' => null,
            'actual_revenue' => null,
        ];
    }
}
