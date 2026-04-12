<?php

namespace Database\Factories;

use App\Enums\ForecastRegion;
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

    public function starterKit(): static
    {
        return $this->state(fn () => [
            'product_category' => ProductCategory::StarterKit,
            'acq_share' => 0.65,
            'repeat_share' => 0.35,
            'avg_unit_price' => 200.00,
        ]);
    }

    public function waxTablet(): static
    {
        return $this->state(fn () => [
            'product_category' => ProductCategory::WaxTablet,
            'acq_share' => 0.35,
            'repeat_share' => 0.65,
            'avg_unit_price' => 30.00,
        ]);
    }

    public function chain(): static
    {
        return $this->state(fn () => [
            'product_category' => ProductCategory::Chain,
            'acq_share' => 0.50,
            'repeat_share' => 0.50,
            'avg_unit_price' => 80.00,
        ]);
    }

    public function heater(): static
    {
        return $this->state(fn () => [
            'product_category' => ProductCategory::Heater,
            'acq_share' => 0.80,
            'repeat_share' => 0.20,
            'avg_unit_price' => 150.00,
        ]);
    }

    public function forRegion(ForecastRegion $region): static
    {
        return $this->state(fn () => [
            'region' => $region,
        ]);
    }
}
