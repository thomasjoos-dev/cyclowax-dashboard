<?php

namespace Database\Factories;

use App\Models\ShopifyLineItem;
use App\Models\ShopifyOrder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShopifyLineItem>
 */
class ShopifyLineItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => ShopifyOrder::factory(),
            'product_title' => fake()->randomElement(['Cyclowax Classic', 'Cyclowax Pro', 'Cyclowax Chain Lube', 'Cyclowax Starter Kit']),
            'product_type' => fake()->randomElement(['Wax', 'Lube', 'Kit', 'Accessory']),
            'sku' => strtoupper(fake()->bothify('CW-###-??')),
            'quantity' => fake()->numberBetween(1, 5),
            'price' => fake()->randomFloat(2, 5, 150),
        ];
    }
}
