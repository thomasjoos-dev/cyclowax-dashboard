<?php

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sku' => strtoupper(fake()->unique()->bothify('CW-###-??')),
            'name' => fake()->words(3, true),
            'product_type' => fake()->randomElement(['wax_tablet', 'chain', 'heater', 'starter_kit']),
            'cost_price' => fake()->randomFloat(4, 5, 80),
            'list_price' => fake()->randomFloat(2, 20, 300),
            'is_active' => true,
            'is_discontinued' => false,
        ];
    }
}
