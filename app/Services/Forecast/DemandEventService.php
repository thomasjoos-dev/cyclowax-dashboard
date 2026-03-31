<?php

namespace App\Services\Forecast;

use App\Enums\ProductCategory;
use App\Models\DemandEvent;
use Illuminate\Support\Collection;

class DemandEventService
{
    /**
     * All events that overlap with a date range.
     *
     * @return Collection<int, DemandEvent>
     */
    public function forPeriod(string $from, string $to): Collection
    {
        return DemandEvent::query()
            ->overlapping($from, $to)
            ->with('categories')
            ->get();
    }

    /**
     * Historical events that affect a specific product category.
     *
     * @return Collection<int, DemandEvent>
     */
    public function historicalForCategory(ProductCategory $category): Collection
    {
        return DemandEvent::query()
            ->historical()
            ->whereHas('categories', fn ($q) => $q->where('product_category', $category->value))
            ->with('categories')
            ->get();
    }

    /**
     * Planned (non-historical) events for a given year.
     *
     * @return Collection<int, DemandEvent>
     */
    public function plannedForYear(int $year): Collection
    {
        return DemandEvent::query()
            ->planned()
            ->whereYear('start_date', $year)
            ->with('categories')
            ->get();
    }

    /**
     * Check if a date range overlaps with a demand event for a specific category.
     */
    public function overlapsWithCategory(string $from, string $to, ProductCategory $category): bool
    {
        return DemandEvent::query()
            ->overlapping($from, $to)
            ->whereHas('categories', fn ($q) => $q->where('product_category', $category->value))
            ->exists();
    }

    /**
     * Create a demand event with its category effects in one call.
     *
     * @param  array{name: string, type: string, start_date: string, end_date: string, description?: string, is_historical?: bool}  $eventData
     * @param  array<int, array{product_category: string, expected_uplift_units?: int, pull_forward_pct?: float}>  $categories
     */
    public function createWithCategories(array $eventData, array $categories): DemandEvent
    {
        $event = DemandEvent::create($eventData);

        foreach ($categories as $categoryData) {
            $event->categories()->create($categoryData);
        }

        return $event->load('categories');
    }
}
