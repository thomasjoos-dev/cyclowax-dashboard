<?php

namespace App\Console\Commands;

use App\Enums\ProductCategory;
use App\Services\Forecast\Demand\CategorySeasonalCalculator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('forecast:calculate-seasonal {--category= : Calculate for a specific product category} {--region= : Calculate for a specific country code}')]
#[Description('Calculate monthly seasonal indices per product category and forecast group')]
class CalculateCategorySeasonalIndicesCommand extends Command
{
    public function handle(CategorySeasonalCalculator $calculator): int
    {
        $region = $this->option('region');
        $categoryValue = $this->option('category');

        if ($categoryValue) {
            $category = ProductCategory::tryFrom($categoryValue);

            if (! $category) {
                $this->error("Unknown product category: {$categoryValue}");

                return self::FAILURE;
            }

            $this->info("Calculating seasonal indices for {$category->label()}...");
            $indices = $calculator->calculateForCategory($category, $region);

            if ($indices === null) {
                $this->error('No data found for this category.');

                return self::FAILURE;
            }

            $this->displayIndices($category->label(), $indices);

            return self::SUCCESS;
        }

        $this->info('Calculating seasonal indices for all categories and groups...');
        $result = $calculator->calculateAll($region);

        foreach ($result['categories'] as $categoryValue => $indices) {
            $category = ProductCategory::from($categoryValue);
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
