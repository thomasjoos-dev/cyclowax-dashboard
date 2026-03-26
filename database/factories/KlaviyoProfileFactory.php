<?php

namespace Database\Factories;

use App\Models\KlaviyoProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KlaviyoProfile>
 */
class KlaviyoProfileFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'klaviyo_id' => fake()->unique()->uuid(),
            'email' => fake()->unique()->safeEmail(),
            'phone_number' => fake()->e164PhoneNumber(),
            'external_id' => (string) fake()->unique()->randomNumber(8),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'city' => fake()->city(),
            'country' => fake()->countryCode(),
            'zip' => fake()->postcode(),
            'historic_clv' => fake()->randomFloat(2, 0, 500),
            'predicted_clv' => fake()->randomFloat(2, 0, 300),
            'total_clv' => fake()->randomFloat(2, 0, 800),
            'historic_number_of_orders' => fake()->numberBetween(0, 20),
            'predicted_number_of_orders' => fake()->numberBetween(0, 10),
            'average_order_value' => fake()->randomFloat(2, 15, 80),
            'churn_probability' => fake()->randomFloat(4, 0, 1),
            'klaviyo_created_at' => fake()->dateTimeBetween('-2 years'),
            'klaviyo_updated_at' => fake()->dateTimeBetween('-6 months'),
        ];
    }
}
