<?php

namespace Database\Factories;

use App\Models\KeyResult;
use App\Models\Objective;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KeyResult>
 */
class KeyResultFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'objective_id' => Objective::factory(),
            'title' => fake()->sentence(5),
            'target_value' => fake()->randomFloat(2, 100, 10000),
            'unit' => fake()->randomElement(['count', 'percentage', 'currency']),
            'tracking_mode' => 'manual',
            'sort_order' => 0,
        ];
    }

    public function autoTracked(string $metricKey): static
    {
        return $this->state(fn () => [
            'metric_key' => $metricKey,
            'tracking_mode' => 'auto',
        ]);
    }
}
