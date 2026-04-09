<?php

namespace Database\Factories;

use App\Enums\ProductCategory;
use App\Models\DemandEvent;
use App\Models\DemandEventCategory;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DemandEventCategory>
 */
class DemandEventCategoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'demand_event_id' => DemandEvent::factory(),
            'product_category' => fake()->randomElement(ProductCategory::cases()),
            'expected_uplift_units' => fake()->numberBetween(10, 500),
            'pull_forward_pct' => fake()->randomFloat(2, 0, 1),
            'is_incremental' => false,
            'product_id' => null,
        ];
    }

    /**
     * Target a specific product (SKU-level).
     */
    public function forProduct(?Product $product = null): static
    {
        return $this->state(fn (array $attributes) => [
            'product_id' => $product?->id ?? Product::factory(),
        ]);
    }
}
