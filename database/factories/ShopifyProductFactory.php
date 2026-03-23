<?php

namespace Database\Factories;

use App\Models\ShopifyProduct;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShopifyProduct>
 */
class ShopifyProductFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'shopify_id' => (string) fake()->unique()->numberBetween(1000000, 9999999),
            'title' => fake()->randomElement(['Cyclowax Classic', 'Cyclowax Pro', 'Cyclowax Chain Lube', 'Cyclowax Starter Kit']),
            'product_type' => fake()->randomElement(['Wax', 'Lube', 'Kit', 'Accessory']),
            'status' => 'active',
        ];
    }
}
