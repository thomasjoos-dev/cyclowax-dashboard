<?php

namespace Database\Factories;

use App\Models\AdSpend;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AdSpend>
 */
class AdSpendFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'date' => fake()->date(),
            'platform' => fake()->randomElement(['google_ads', 'meta_ads']),
            'campaign_name' => fake()->words(3, true),
            'campaign_id' => (string) fake()->numberBetween(100000, 999999),
            'country_code' => fake()->randomElement(['DE', 'NL', 'BE', 'US', 'GB']),
            'channel_type' => fake()->randomElement(['search', 'pmax', 'acquisition', 'retargeting']),
            'spend' => fake()->randomFloat(2, 10, 500),
            'impressions' => fake()->numberBetween(100, 50000),
            'clicks' => fake()->numberBetween(5, 500),
            'conversions' => fake()->randomFloat(2, 0, 50),
            'conversions_value' => fake()->randomFloat(2, 0, 5000),
        ];
    }
}
