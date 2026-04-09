<?php

namespace App\Console\Commands;

use App\Enums\PortfolioRole;
use App\Enums\ProductCategory;
use App\Models\Product;
use App\Services\Scoring\ProductClassifier;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

#[Signature('products:classify-portfolio {--force : Overwrite existing classifications}')]
#[Description('Classify all products with portfolio role, category, journey phase, wax recipe and heater generation')]
class ClassifyProductPortfolioCommand extends Command
{
    public function handle(ProductClassifier $classifier): int
    {
        try {
            $this->info('Classifying products...');

            $result = $classifier->classifyAll(force: (bool) $this->option('force'));

            foreach ($result['unmatched'] as $product) {
                $this->warn("  Unmatched: [{$product->id}] {$product->sku} — {$product->name} (cat: {$product->category})");
            }

            $this->newLine();
            $this->info("{$result['classified']} classified, {$result['skipped']} skipped, ".count($result['unmatched']).' unmatched');
            $this->newLine();

            $this->table(
                ['Category', 'Count', 'Portfolio Role(s)'],
                Product::whereNotNull('product_category')
                    ->selectRaw('product_category, portfolio_role, count(*) as cnt')
                    ->groupBy('product_category', 'portfolio_role')
                    ->orderBy('product_category')
                    ->get()
                    ->map(fn ($row) => [
                        $row->product_category instanceof ProductCategory ? $row->product_category->label() : $row->product_category,
                        $row->cnt,
                        $row->portfolio_role instanceof PortfolioRole ? $row->portfolio_role->label() : ($row->portfolio_role ?? '—'),
                    ])
                    ->toArray()
            );

            $discontinued = Product::where('is_discontinued', true)->get();
            if ($discontinued->isNotEmpty()) {
                $this->newLine();
                $this->info('Discontinued products:');
                foreach ($discontinued as $p) {
                    $successor = $p->successor ? " → {$p->successor->name}" : '';
                    $this->line("  [{$p->sku}] {$p->name}{$successor}");
                }
            }

            return self::SUCCESS;
        } catch (\Throwable $e) {
            Log::error('ClassifyProductPortfolioCommand failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
