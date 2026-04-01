<?php

namespace App\Services\Forecast\Demand;

use App\Enums\ForecastGroup;
use App\Enums\ProductCategory;
use App\Models\SeasonalIndex;
use App\Support\DbDialect;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CategorySeasonalCalculator
{
    public function __construct(
        private DemandEventService $demandEventService,
        private SeasonalIndexCalculator $baseCalculator,
    ) {}

    /**
     * Calculate and persist seasonal indices for a product category.
     *
     * @return array<int, float>|null Normalized indices keyed by month (1-12)
     */
    public function calculateForCategory(ProductCategory $category, ?string $region = null): ?array
    {
        $monthlyCounts = $this->getMonthlyCategoryCounts($category, $region);

        if ($monthlyCounts->isEmpty()) {
            return null;
        }

        $normalized = $this->baseCalculator->normalize($monthlyCounts);

        if ($normalized === null) {
            return null;
        }

        foreach ($normalized as $month => $indexValue) {
            SeasonalIndex::updateOrCreate(
                ['month' => $month, 'region' => $region, 'product_category' => $category->value],
                ['index_value' => $indexValue, 'source' => 'calculated', 'forecast_group' => null],
            );
        }

        return $normalized;
    }

    /**
     * Calculate a weighted average seasonal index for a forecast group.
     * Weight = total units per category (larger categories weigh more).
     *
     * @return array<int, float>|null
     */
    public function calculateForGroup(ForecastGroup $group, ?string $region = null): ?array
    {
        $categoryIndices = [];
        $categoryWeights = [];

        foreach ($group->categories() as $category) {
            $indices = $this->calculateForCategory($category, $region);

            if ($indices === null) {
                continue;
            }

            $totalUnits = $this->getTotalUnitsForCategory($category);
            $categoryIndices[] = $indices;
            $categoryWeights[] = $totalUnits;
        }

        if (empty($categoryIndices)) {
            return null;
        }

        $totalWeight = array_sum($categoryWeights);

        if ($totalWeight == 0) {
            return null;
        }

        $groupIndices = [];
        for ($month = 1; $month <= 12; $month++) {
            $weighted = 0;
            foreach ($categoryIndices as $i => $indices) {
                $weighted += ($indices[$month] ?? 1.0) * $categoryWeights[$i];
            }
            $groupIndices[$month] = round($weighted / $totalWeight, 4);
        }

        foreach ($groupIndices as $month => $indexValue) {
            SeasonalIndex::updateOrCreate(
                ['month' => $month, 'region' => $region, 'product_category' => null],
                ['index_value' => $indexValue, 'source' => 'calculated', 'forecast_group' => $group->value],
            );
        }

        return $groupIndices;
    }

    /**
     * Calculate all category indices and all group indices.
     *
     * @return array{categories: array<string, array<int, float>|null>, groups: array<string, array<int, float>|null>}
     */
    public function calculateAll(?string $region = null): array
    {
        $categories = [];
        foreach (ProductCategory::cases() as $category) {
            if ($category->forecastGroup() === null) {
                continue;
            }
            $categories[$category->value] = $this->calculateForCategory($category, $region);
        }

        $groups = [];
        foreach (ForecastGroup::cases() as $group) {
            $groups[$group->value] = $this->calculateForGroup($group, $region);
        }

        return ['categories' => $categories, 'groups' => $groups];
    }

    /**
     * Resolve the best available seasonal index for a category and month,
     * applying maturity-based fallback logic.
     */
    public function resolveIndex(ProductCategory $category, int $month, ?string $region = null): float
    {
        $maturityMonths = $this->productMaturityMonths($category);

        if ($maturityMonths >= 12) {
            // Mature: use own category index
            $index = SeasonalIndex::query()
                ->forCategory($category)
                ->where('month', $month)
                ->where('region', $region)
                ->first();

            if ($index) {
                return (float) $index->index_value;
            }
        }

        if ($maturityMonths >= 3) {
            // Ramping: weighted mix of own + group
            $ownIndex = SeasonalIndex::query()
                ->forCategory($category)
                ->where('month', $month)
                ->where('region', $region)
                ->first();

            $group = $category->forecastGroup();
            $groupIndex = $group ? SeasonalIndex::query()
                ->forGroup($group)
                ->where('month', $month)
                ->where('region', $region)
                ->first() : null;

            if ($ownIndex && $groupIndex) {
                $ownWeight = $maturityMonths / 12;

                return round(
                    (float) $ownIndex->index_value * $ownWeight + (float) $groupIndex->index_value * (1 - $ownWeight),
                    4,
                );
            }

            if ($ownIndex) {
                return (float) $ownIndex->index_value;
            }
        }

        // Launch: use forecast group index
        $group = $category->forecastGroup();
        if ($group) {
            $groupIndex = SeasonalIndex::query()
                ->forGroup($group)
                ->where('month', $month)
                ->where('region', $region)
                ->first();

            if ($groupIndex) {
                return (float) $groupIndex->index_value;
            }
        }

        // Ultimate fallback: global index
        $globalIndex = SeasonalIndex::query()
            ->global()
            ->whereNull('product_category')
            ->whereNull('forecast_group')
            ->where('month', $month)
            ->first();

        return $globalIndex ? (float) $globalIndex->index_value : 1.0;
    }

    /**
     * Determine how many months of clean (non-event) sales data exist for a category.
     */
    public function productMaturityMonths(ProductCategory $category): int
    {
        $firstSaleDate = DB::table('shopify_line_items')
            ->join('products', 'shopify_line_items.product_id', '=', 'products.id')
            ->join('shopify_orders', 'shopify_line_items.order_id', '=', 'shopify_orders.id')
            ->where('products.product_category', $category->value)
            ->whereNotIn('shopify_orders.financial_status', ['voided', 'refunded'])
            ->min('shopify_orders.ordered_at');

        if (! $firstSaleDate) {
            return 0;
        }

        $totalMonths = (int) now()->diffInMonths($firstSaleDate);

        // Subtract months that overlap with historical demand events
        $events = $this->demandEventService->historicalForCategory($category);
        $eventMonths = 0;

        foreach ($events as $event) {
            $eventMonths += (int) $event->start_date->diffInMonths($event->end_date) + 1;
        }

        return max(0, $totalMonths - $eventMonths);
    }

    /**
     * Get monthly unit counts for a category, excluding demand event periods.
     *
     * @return Collection<int, object{month: int, year: string, order_count: int}>
     */
    private function getMonthlyCategoryCounts(ProductCategory $category, ?string $region = null): Collection
    {
        $query = DB::table('shopify_line_items')
            ->join('products', 'shopify_line_items.product_id', '=', 'products.id')
            ->join('shopify_orders', 'shopify_line_items.order_id', '=', 'shopify_orders.id')
            ->where('products.product_category', $category->value)
            ->whereNotIn('shopify_orders.financial_status', ['voided', 'refunded']);

        // Exclude historical demand event periods
        $events = $this->demandEventService->historicalForCategory($category);
        foreach ($events as $event) {
            $query->where(function ($q) use ($event) {
                $q->where('shopify_orders.ordered_at', '<', $event->start_date->toDateString())
                    ->orWhere('shopify_orders.ordered_at', '>', $event->end_date->toDateString());
            });
        }

        if ($region) {
            $query->where('shopify_orders.shipping_country_code', $region);
        }

        return $query
            ->selectRaw(DbDialect::monthExpr('shopify_orders.ordered_at').' as month')
            ->selectRaw(DbDialect::yearExpr('shopify_orders.ordered_at').' as year')
            ->selectRaw('SUM(shopify_line_items.quantity) as order_count')
            ->groupBy('year', 'month')
            ->orderBy('year')
            ->orderBy('month')
            ->get();
    }

    /**
     * Get total units sold for a category (used for group weighting).
     */
    private function getTotalUnitsForCategory(ProductCategory $category): int
    {
        return (int) DB::table('shopify_line_items')
            ->join('products', 'shopify_line_items.product_id', '=', 'products.id')
            ->join('shopify_orders', 'shopify_line_items.order_id', '=', 'shopify_orders.id')
            ->where('products.product_category', $category->value)
            ->whereNotIn('shopify_orders.financial_status', ['voided', 'refunded'])
            ->sum('shopify_line_items.quantity');
    }
}
