<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ProductBom;
use App\Services\Forecast\BomExplosionService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('forecast:validate-bom')]
#[Description('Validate BOM integrity: missing BOMs, empty explosions, circular references')]
class ValidateBomCommand extends Command
{
    public function handle(BomExplosionService $bomService): int
    {
        $this->info('Validating BOM structures...');

        $boms = ProductBom::with('product')->get();
        $allProducts = Product::whereNotNull('product_category')->get();

        // 1. Products with BOMs — validate explosion
        $issues = [];
        $valid = 0;

        foreach ($boms as $bom) {
            $product = $bom->product;

            if (! $product) {
                $issues[] = [
                    'BOM #'.$bom->id,
                    '—',
                    'orphan_bom',
                    'BOM exists but product is missing',
                ];

                continue;
            }

            $components = $bomService->explode($product->id);

            if (count($components) === 0) {
                $issues[] = [
                    $product->sku ?? '—',
                    $product->name ?? '—',
                    'empty_explosion',
                    'BOM explodes to zero components (possible circular ref or missing lines)',
                ];

                continue;
            }

            // Check for components without procurement lead time
            $noLt = collect($components)->filter(fn (array $c) => $c['procurement_lt'] === null);

            if ($noLt->isNotEmpty()) {
                $skus = $noLt->pluck('sku')->implode(', ');
                $issues[] = [
                    $product->sku ?? '—',
                    $product->name ?? '—',
                    'missing_lead_time',
                    "Components without procurement LT: {$skus}",
                ];
            }

            $valid++;
        }

        // 2. Finished products without BOMs (have a category, are sellable, but no BOM)
        $bomProductIds = $boms->pluck('product_id')->toArray();
        $noBom = $allProducts->filter(fn (Product $p) => ! in_array($p->id, $bomProductIds) && $p->product_category->forecastGroup() !== null);

        if ($noBom->isNotEmpty()) {
            foreach ($noBom as $product) {
                $issues[] = [
                    $product->sku ?? '—',
                    $product->name ?? '—',
                    'no_bom',
                    'Forecastable product has no BOM defined',
                ];
            }
        }

        // Display results
        if (count($issues) > 0) {
            $this->warn(count($issues).' issue(s) found:');
            $this->table(['SKU', 'Name', 'Issue', 'Detail'], $issues);
        }

        $this->info("{$valid} BOMs validated successfully, {$noBom->count()} products without BOM, ".count($issues).' total issue(s).');

        return count($issues) > 0 ? self::FAILURE : self::SUCCESS;
    }
}
