<?php

namespace Database\Factories;

use App\Enums\Team;
use App\Models\Objective;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Objective>
 */
class ObjectiveFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => fake()->sentence(6),
            'year' => 2026,
            'sort_order' => 0,
        ];
    }

    public function company(): static
    {
        return $this->state(fn () => ['team' => null, 'parent_key_result_id' => null]);
    }

    public function forTeam(Team $team): static
    {
        return $this->state(fn () => ['team' => $team]);
    }
}
