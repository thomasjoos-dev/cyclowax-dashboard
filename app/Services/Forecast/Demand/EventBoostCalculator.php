<?php

namespace App\Services\Forecast\Demand;

use App\Enums\ProductCategory;
use App\Models\DemandEvent;
use Illuminate\Support\Collection;

class EventBoostCalculator
{
    /**
     * Calculate demand event boost for a specific month and category.
     *
     * @param  Collection<int, DemandEvent>  $events
     */
    public function calculate(Collection $events, string $yearMonth, ProductCategory $category, float $avgUnitPrice): float
    {
        $boost = 0;
        $monthStart = $yearMonth.'-01';
        $monthEnd = $yearMonth.'-'.cal_days_in_month(CAL_GREGORIAN, (int) substr($yearMonth, 5, 2), (int) substr($yearMonth, 0, 4));

        foreach ($events as $event) {
            if ($event->start_date->gt($monthEnd) || $event->end_date->lt($monthStart)) {
                continue;
            }

            $eventCategory = $event->categories
                ->first(fn ($ec) => $ec->product_category === $category);

            if ($eventCategory && $eventCategory->expected_uplift_units) {
                $eventMonths = max(1, $event->start_date->diffInMonths($event->end_date) + 1);
                $monthlyUplift = $eventCategory->expected_uplift_units / $eventMonths;
                $boost += $monthlyUplift * $avgUnitPrice;
            }
        }

        return $boost;
    }
}
