<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductStockSnapshot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductStockSnapshot>
 */
class ProductStockSnapshotFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'qty_on_hand' => fake()->randomFloat(2, 0, 500),
            'qty_forecasted' => fake()->randomFloat(2, 0, 1000),
            'qty_free' => fake()->randomFloat(2, 0, 500),
            'recorded_at' => now(),
        ];
    }
}
