<?php

namespace Database\Factories;

use App\Enums\FollowerSegment;
use App\Enums\LifecycleStage;
use App\Models\RiderProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RiderProfile>
 */
class RiderProfileFactory extends Factory
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
            'lifecycle_stage' => LifecycleStage::Follower,
            'linked_at' => now(),
        ];
    }

    /**
     * A follower profile with engagement data.
     */
    public function follower(): static
    {
        return $this->state(fn () => [
            'lifecycle_stage' => LifecycleStage::Follower,
            'engagement_score' => fake()->numberBetween(1, 5),
            'segment' => fake()->randomElement(FollowerSegment::cases())->value,
        ]);
    }

    /**
     * A customer profile.
     */
    public function customer(): static
    {
        return $this->state(fn () => [
            'lifecycle_stage' => LifecycleStage::Customer,
            'segment' => null,
            'engagement_score' => null,
        ]);
    }
}
