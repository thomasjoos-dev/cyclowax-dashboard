<?php

namespace Database\Factories;

use App\Models\SeasonalIndex;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SeasonalIndex>
 */
class SeasonalIndexFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'month' => fake()->unique()->numberBetween(1, 12),
            'index_value' => fake()->randomFloat(4, 0.5, 1.8),
            'region' => null,
            'source' => 'calculated',
        ];
    }
}
