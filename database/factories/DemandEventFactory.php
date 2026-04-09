<?php

namespace Database\Factories;

use App\Enums\DemandEventType;
use App\Models\DemandEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DemandEvent>
 */
class DemandEventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startDate = fake()->dateTimeBetween('-6 months', '+6 months');
        $endDate = (clone $startDate)->modify('+'.fake()->numberBetween(1, 30).' days');

        return [
            'name' => fake()->sentence(3),
            'type' => fake()->randomElement(DemandEventType::cases()),
            'start_date' => $startDate,
            'end_date' => $endDate,
            'description' => fake()->sentence(),
            'is_historical' => fake()->boolean(),
        ];
    }
}
