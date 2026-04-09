<?php

namespace Database\Factories;

use App\Models\OpenPurchaseOrder;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OpenPurchaseOrder>
 */
class OpenPurchaseOrderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $ordered = fake()->randomFloat(2, 50, 1000);
        $received = fake()->randomFloat(2, 0, $ordered);

        return [
            'odoo_po_line_id' => fake()->unique()->numberBetween(1000, 99999),
            'po_reference' => 'PO-'.str_pad((string) fake()->numberBetween(1, 99999), 5, '0', STR_PAD_LEFT),
            'product_id' => Product::factory(),
            'odoo_product_id' => fake()->numberBetween(100, 9999),
            'product_name' => fake()->words(3, true),
            'quantity_ordered' => $ordered,
            'quantity_received' => $received,
            'quantity_open' => round($ordered - $received, 2),
            'unit_price' => fake()->randomFloat(2, 5, 200),
            'date_order' => fake()->dateTimeBetween('-3 months', 'now'),
            'date_planned' => fake()->dateTimeBetween('now', '+3 months'),
            'supplier_name' => fake()->company(),
            'state' => fake()->randomElement(['purchase', 'done']),
        ];
    }
}
