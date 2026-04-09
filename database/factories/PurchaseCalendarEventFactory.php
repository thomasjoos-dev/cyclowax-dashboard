<?php

namespace Database\Factories;

use App\Enums\ProductCategory;
use App\Models\PurchaseCalendarEvent;
use App\Models\PurchaseCalendarRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PurchaseCalendarEvent>
 */
class PurchaseCalendarEventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'run_id' => PurchaseCalendarRun::factory(),
            'date' => fake()->dateTimeBetween('2026-01-01', '2026-12-31'),
            'event_type' => fake()->randomElement(['purchase', 'receipt', 'production_start']),
            'product_id' => null,
            'sku' => strtoupper(fake()->unique()->bothify('CW-###-??')),
            'name' => fake()->words(3, true),
            'quantity' => fake()->randomFloat(2, 10, 5000),
            'gross_quantity' => fake()->randomFloat(2, 10, 5000),
            'net_quantity' => fake()->randomFloat(2, 10, 5000),
            'supplier' => fake()->company(),
            'product_category' => fake()->randomElement(ProductCategory::cases()),
            'month_label' => fake()->date('Y-m'),
            'note' => null,
        ];
    }
}
