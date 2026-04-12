<?php

namespace App\Services\Forecast\Demand;

use App\Enums\ForecastGroup;
use App\Enums\ProductCategory;
use App\Models\DemandEvent;
use Illuminate\Support\Collection;

class PullForwardCalculator
{
    /**
     * Calculate pull-forward deduction: units pulled from this month by a previous month's event.
     * Only Getting Started products have pull-forward.
     *
     * @param  Collection<int, DemandEvent>  $events
     */
    public function calculate(Collection $events, string $yearMonth, int $month, ProductCategory $category, float $avgUnitPrice): float
    {
        $group = $category->forecastGroup();
        if ($group !== ForecastGroup::GettingStarted) {
            return 0;
        }

        $deduction = 0;

        foreach ($events as $event) {
            $eventEndMonth = (int) $event->end_date->format('m');
            $eventEndYear = (int) $event->end_date->format('Y');
            $forecastYear = (int) substr($yearMonth, 0, 4);

            $afterEventMonth = $eventEndMonth + 1;
            $afterEventYear = $eventEndYear;
            if ($afterEventMonth > 12) {
                $afterEventMonth = 1;
                $afterEventYear++;
            }

            if ($month !== $afterEventMonth || $forecastYear !== $afterEventYear) {
                continue;
            }

            $eventCategory = $event->categories
                ->first(fn ($ec) => $ec->product_category === $category);

            if ($eventCategory && $eventCategory->expected_uplift_units && (float) $eventCategory->pull_forward_pct > 0) {
                $pullForwardUnits = $eventCategory->expected_uplift_units * (float) $eventCategory->pull_forward_pct / 100;
                $deduction += $pullForwardUnits * $avgUnitPrice;
            }
        }

        return $deduction;
    }
}
