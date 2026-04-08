<?php

namespace Database\Factories;

use App\Models\ShopifyCustomer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShopifyCustomer>
 */
class ShopifyCustomerFactory extends Factory
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
            'email' => fake()->unique()->safeEmail(),
            'orders_count' => fake()->numberBetween(1, 50),
            'total_spent' => fake()->randomFloat(2, 10, 5000),
            'first_order_at' => fake()->dateTimeBetween('-2 years', '-6 months'),
            'last_order_at' => fake()->dateTimeBetween('-6 months', 'now'),
            'country_code' => fake()->randomElement(['NL', 'BE', 'DE', 'FR', 'US', 'GB']),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'locale' => fake()->randomElement(['nl', 'en', 'de', 'fr']),
            'tags' => null,
            'email_marketing_consent' => fake()->randomElement(['SUBSCRIBED', 'NOT_SUBSCRIBED', null]),
            'shopify_created_at' => fake()->dateTimeBetween('-3 years', '-2 years'),
        ];
    }
}
