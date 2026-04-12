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
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'month' => fake()->numberBetween(1, 12),
            'index_value' => fake()->randomFloat(4, 0.5, 1.8),
            'region' => null,
            'product_category' => null,
            'forecast_group' => null,
            'source' => 'calculated',
        ];
    }

    /**
     * Flat index (1.0) for testing — no seasonal effect.
     */
    public function flat(): static
    {
        return $this->state(fn () => [
            'index_value' => 1.0,
            'source' => 'test',
        ]);
    }

    public function forCategory(string $category): static
    {
        return $this->state(fn () => [
            'product_category' => $category,
        ]);
    }

    public function forGroup(string $group): static
    {
        return $this->state(fn () => [
            'forecast_group' => $group,
        ]);
    }

    public function forRegion(string $region): static
    {
        return $this->state(fn () => [
            'region' => $region,
        ]);
    }
}
