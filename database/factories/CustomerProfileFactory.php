<?php

namespace Database\Factories;

use App\Models\CustomerProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomerProfile>
 */
class CustomerProfileFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'email' => fake()->unique()->safeEmail(),
            'lifecycle_stage' => 'follower',
            'linked_at' => now(),
        ];
    }

    /**
     * A follower profile with engagement data.
     */
    public function follower(): static
    {
        return $this->state(fn () => [
            'lifecycle_stage' => 'follower',
            'engagement_score' => fake()->numberBetween(1, 5),
            'follower_segment' => fake()->randomElement(['high_potential', 'engaged', 'new', 'fading', 'inactive']),
        ]);
    }

    /**
     * A customer profile.
     */
    public function customer(): static
    {
        return $this->state(fn () => [
            'lifecycle_stage' => 'customer',
            'follower_segment' => null,
            'engagement_score' => null,
        ]);
    }
}
