<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductBom;
use App\Models\ProductBomLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductBomLine>
 */
class ProductBomLineFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'bom_id' => ProductBom::factory(),
            'component_product_id' => Product::factory(),
            'quantity' => fake()->randomFloat(4, 0.5, 10),
        ];
    }
}
