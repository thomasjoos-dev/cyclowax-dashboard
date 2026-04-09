<?php

namespace Database\Factories;

use App\Models\RiderProfile;
use App\Models\SegmentTransition;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SegmentTransition>
 */
class SegmentTransitionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'rider_profile_id' => RiderProfile::factory(),
            'type' => fake()->randomElement(['lifecycle_change', 'segment_change']),
            'from_lifecycle' => null,
            'to_lifecycle' => null,
            'from_segment' => null,
            'to_segment' => null,
            'occurred_at' => fake()->dateTimeBetween('-6 months', 'now'),
        ];
    }
}
