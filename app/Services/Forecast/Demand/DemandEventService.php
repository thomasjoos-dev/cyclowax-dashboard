<?php

namespace App\Services\Forecast\Demand;

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
     * Extract product-targeted earmarks from planned events for a given year.
     *
     * Returns earmarked units grouped by month, category, and product_id.
     * Only includes event categories where a specific product_id is set.
     *
     * @return array<int, array<string, array<int, int>>> [month => [category_value => [product_id => units]]]
     */
    public function skuEarmarksForYear(int $year): array
    {
        $events = $this->plannedForYear($year);
        $earmarks = [];

        foreach ($events as $event) {
            foreach ($event->categories as $eventCategory) {
                if (! $eventCategory->isProductTargeted()) {
                    continue;
                }

                if (! $eventCategory->expected_uplift_units || $eventCategory->expected_uplift_units <= 0) {
                    continue;
                }

                $eventMonths = max(1, (int) $event->start_date->diffInMonths($event->end_date) + 1);
                $monthlyUnits = (int) ceil($eventCategory->expected_uplift_units / $eventMonths);

                $catValue = $eventCategory->product_category->value;
                $productId = $eventCategory->product_id;

                // Distribute across each month the event spans
                $cursor = $event->start_date->toMutable()->startOfMonth();
                $end = $event->end_date->toMutable()->endOfMonth();

                while ($cursor->lte($end)) {
                    $month = (int) $cursor->format('m');
                    $monthYear = (int) $cursor->format('Y');

                    if ($monthYear === $year) {
                        $earmarks[$month][$catValue][$productId] =
                            ($earmarks[$month][$catValue][$productId] ?? 0) + $monthlyUnits;
                    }

                    $cursor->addMonth();
                }
            }
        }

        return $earmarks;
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
