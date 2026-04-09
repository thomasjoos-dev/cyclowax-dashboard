<?php

namespace Database\Factories;

use App\Enums\Warehouse;
use App\Models\PurchaseCalendarRun;
use App\Models\Scenario;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PurchaseCalendarRun>
 */
class PurchaseCalendarRunFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'scenario_id' => Scenario::factory(),
            'year' => 2026,
            'warehouse' => fake()->randomElement(Warehouse::cases()),
            'generated_at' => now(),
            'summary' => [],
            'netting_summary' => [],
            'sku_mix_summary' => [],
        ];
    }
}
