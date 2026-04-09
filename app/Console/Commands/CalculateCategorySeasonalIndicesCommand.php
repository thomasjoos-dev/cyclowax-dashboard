<?php

namespace App\Console\Commands;

use App\Enums\ForecastRegion;
use App\Enums\ProductCategory;
use App\Services\Forecast\Demand\CategorySeasonalCalculator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

#[Signature('forecast:calculate-seasonal {--category= : Calculate for a specific product category} {--region= : Calculate for a specific region (e.g. de, eu_alpine)} {--all-regions : Calculate for all regions plus global}')]
#[Description('Calculate monthly seasonal indices per product category and forecast group')]
class CalculateCategorySeasonalIndicesCommand extends Command
{
    public function handle(CategorySeasonalCalculator $calculator): int
    {
        try {
            $regionValue = $this->option('region');
            $allRegions = $this->option('all-regions');
            $categoryValue = $this->option('category');

            $region = null;
            if ($regionValue) {
                $region = ForecastRegion::tryFrom($regionValue);

                if (! $region) {
                    $this->error("Unknown region: {$regionValue}. Valid: ".implode(', ', array_map(fn ($r) => $r->value, ForecastRegion::cases())));

                    return self::FAILURE;
                }
            }

            if ($allRegions) {
                return $this->calculateAllRegions($calculator, $categoryValue);
            }

            return $this->calculateForRegion($calculator, $region, $categoryValue);
        } catch (\Throwable $e) {
            Log::error('CalculateCategorySeasonalIndicesCommand failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function calculateAllRegions(CategorySeasonalCalculator $calculator, ?string $categoryValue): int
    {
        // Global first
        $this->info('Calculating global seasonal indices...');
        $this->calculateForRegion($calculator, null, $categoryValue);

        // Then each region
        foreach (ForecastRegion::cases() as $region) {
            $this->newLine();
            $this->info("--- {$region->label()} ({$region->value}) ---");
            $this->calculateForRegion($calculator, $region, $categoryValue);
        }

        return self::SUCCESS;
    }

    private function calculateForRegion(CategorySeasonalCalculator $calculator, ?ForecastRegion $region, ?string $categoryValue): int
    {
        if ($categoryValue) {
            $category = ProductCategory::tryFrom($categoryValue);

            if (! $category) {
                $this->error("Unknown product category: {$categoryValue}");

                return self::FAILURE;
            }

            $this->info("Calculating seasonal indices for {$category->label()}...");
            $indices = $calculator->calculateForCategory($category, $region);

            if ($indices === null) {
                $this->warn('No data found for this category.');

                return self::SUCCESS;
            }

            $this->displayIndices($category->label(), $indices);

            return self::SUCCESS;
        }

        $this->info('Calculating seasonal indices for all categories and groups...');
        $result = $calculator->calculateAll($region);

        foreach ($result['categories'] as $catValue => $indices) {
            $category = ProductCategory::from($catValue);
            if ($indices !== null) {
                $this->displayIndices($category->label(), $indices);
            } else {
                $this->warn("  {$category->label()}: no data");
            }
        }

        $this->newLine();
        $this->info('Forecast group averages:');

        foreach ($result['groups'] as $groupValue => $indices) {
            if ($indices !== null) {
                $this->displayIndices(strtoupper($groupValue), $indices);
            }
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<int, float>  $indices
     */
    private function displayIndices(string $label, array $indices): void
    {
        $this->newLine();
        $this->info("  {$label}:");
        $this->table(
            ['Month', 'Index', 'Interpretation'],
            collect($indices)->map(fn ($val, $month) => [
                $month,
                number_format($val, 4),
                $val >= 1.1 ? 'Above average' : ($val <= 0.9 ? 'Below average' : 'Average'),
            ])->sortKeys()->values()->toArray(),
        );
    }
}
