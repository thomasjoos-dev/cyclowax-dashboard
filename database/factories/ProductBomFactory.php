<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductBom;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductBom>
 */
class ProductBomFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'odoo_bom_id' => fake()->unique()->numberBetween(1000, 99999),
            'product_id' => Product::factory(),
            'bom_type' => fake()->randomElement(['normal', 'phantom']),
            'product_qty' => 1.0,
            'assembly_lead_time_days' => 0,
            'assembly_time_source' => null,
            'assembly_time_samples' => 0,
        ];
    }
}
