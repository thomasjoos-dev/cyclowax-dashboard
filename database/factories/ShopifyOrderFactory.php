<?php

namespace Database\Factories;

use App\Models\ShopifyCustomer;
use App\Models\ShopifyOrder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShopifyOrder>
 */
class ShopifyOrderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $subtotal = fake()->randomFloat(2, 10, 500);
        $shipping = fake()->randomFloat(2, 0, 15);
        $tax = round($subtotal * 0.21, 2);
        $discounts = fake()->boolean(30) ? fake()->randomFloat(2, 1, 50) : 0;

        return [
            'shopify_id' => (string) fake()->unique()->numberBetween(1000000, 9999999),
            'name' => '#'.fake()->unique()->numberBetween(1000, 9999),
            'ordered_at' => fake()->dateTimeBetween('-1 year', 'now'),
            'total_price' => $subtotal + $shipping + $tax - $discounts,
            'subtotal' => $subtotal,
            'shipping' => $shipping,
            'tax' => $tax,
            'discounts' => $discounts,
            'refunded' => 0,
            'financial_status' => fake()->randomElement(['PAID', 'PENDING', 'REFUNDED', 'PARTIALLY_REFUNDED']),
            'fulfillment_status' => fake()->randomElement(['FULFILLED', 'UNFULFILLED', 'PARTIALLY_FULFILLED', null]),
            'customer_id' => ShopifyCustomer::factory(),
            'country_code' => fake()->randomElement(['NL', 'BE', 'DE', 'FR', 'US', 'GB']),
            'currency' => 'EUR',
        ];
    }
}
