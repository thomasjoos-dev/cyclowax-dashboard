<?php

namespace Database\Factories;

use App\Models\SyncState;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SyncState>
 */
class SyncStateFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'step' => fake()->randomElement([
                'shopify:sync-orders',
                'shopify:sync-products',
                'shopify:sync-customers',
                'klaviyo:sync-profiles',
                'klaviyo:sync-campaigns',
            ]),
            'last_synced_at' => fake()->optional()->dateTimeBetween('-7 days', 'now'),
            'duration_seconds' => fake()->optional()->randomFloat(2, 0.5, 120),
            'records_synced' => fake()->optional()->numberBetween(0, 10000),
            'was_full_sync' => fake()->boolean(),
            'status' => fake()->randomElement(['idle', 'running', 'completed']),
            'cursor' => null,
            'started_at' => fake()->optional()->dateTimeBetween('-1 hour', 'now'),
        ];
    }
}
