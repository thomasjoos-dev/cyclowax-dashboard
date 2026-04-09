<?php

namespace Database\Factories;

use App\Enums\ProductCategory;
use App\Models\SupplyProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SupplyProfile>
 */
class SupplyProfileFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_category' => fake()->unique()->randomElement(ProductCategory::cases()),
            'procurement_lead_time_days' => fake()->numberBetween(30, 90),
            'assembly_lead_time_days' => 0,
            'moq' => fake()->numberBetween(50, 500),
            'buffer_days' => fake()->numberBetween(7, 30),
            'supplier_name' => fake()->company(),
            'notes' => null,
            'validated_at' => null,
            'validated_by' => null,
        ];
    }

    /**
     * Mark the profile as validated.
     */
    public function validated(): static
    {
        return $this->state(fn (array $attributes) => [
            'validated_at' => now(),
            'validated_by' => fake()->name(),
        ]);
    }
}
