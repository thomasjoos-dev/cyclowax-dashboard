<?php

namespace Database\Factories;

use App\Models\Scenario;
use App\Models\ScenarioAssumption;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScenarioAssumption>
 */
class ScenarioAssumptionFactory extends Factory
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
            'quarter' => fake()->randomElement(['Q2', 'Q3', 'Q4']),
            'acq_rate' => fake()->randomFloat(4, 0.5, 1.5),
            'repeat_rate' => fake()->randomFloat(4, 0.10, 0.40),
            'repeat_aov' => fake()->randomFloat(2, 70, 130),
        ];
    }
}
