<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ShopifyLineItem;

class LineItemLinker
{
    /** @var array<string, int> */
    private array $skuMap;

    /** @var array<string, int> */
    private array $barcodeMap;

    /** @var array<string, string> */
    private array $skuAliases;

    /** @var array<string, string> */
    private array $titleMap;

    /**
     * Link unlinked line items to products and set COGS where missing.
     *
     * @return array{sku: int, barcode: int, alias: int, title: int, cost: int}
     */
    public function linkAll(): array
    {
        $this->loadMaps();

        $stats = ['sku' => 0, 'barcode' => 0, 'alias' => 0, 'title' => 0, 'cost' => 0];

        ShopifyLineItem::query()
            ->whereNull('product_id')
            ->chunkById(1000, function ($lineItems) use (&$stats) {
                foreach ($lineItems as $lineItem) {
                    $productId = $this->resolve($lineItem, $stats);

                    if ($productId) {
                        $updates = ['product_id' => $productId];

                        if ($lineItem->cost_price === null) {
                            $costPrice = Product::where('id', $productId)->value('cost_price');

                            if ($costPrice) {
                                $updates['cost_price'] = $costPrice;
                                $stats['cost']++;
                            }
                        }

                        $lineItem->update($updates);
                    }
                }
            });

        return $stats;
    }

    /**
     * Try to resolve a product ID using 4-step fallback: SKU → barcode → alias → title.
     *
     * @param  array<string, int>  $stats
     */
    public function resolve(ShopifyLineItem $lineItem, array &$stats): ?int
    {
        $sku = $lineItem->sku ? trim($lineItem->sku) : '';

        if ($sku !== '') {
            // 1. Direct SKU match
            if (isset($this->skuMap[$sku])) {
                $stats['sku']++;

                return $this->skuMap[$sku];
            }

            // 2. Barcode match (EAN as SKU)
            $stripped = ltrim($sku, '0');
            $productId = $this->barcodeMap[$sku] ?? $this->barcodeMap[$stripped] ?? $this->barcodeMap['0'.$sku] ?? null;

            if ($productId) {
                $stats['barcode']++;

                return $productId;
            }

            // 3. SKU alias (legacy numeric SKUs)
            $aliasedSku = $this->skuAliases[$sku] ?? null;

            if ($aliasedSku && isset($this->skuMap[$aliasedSku])) {
                $stats['alias']++;

                return $this->skuMap[$aliasedSku];
            }
        }

        // 4. Product title match
        $title = $lineItem->product_title ? mb_strtolower(trim($lineItem->product_title)) : '';
        $mappedSku = $this->titleMap[$title] ?? null;

        if ($mappedSku && isset($this->skuMap[$mappedSku])) {
            $stats['title']++;

            return $this->skuMap[$mappedSku];
        }

        return null;
    }

    private function loadMaps(): void
    {
        $products = Product::all();
        $this->skuMap = $products->pluck('id', 'sku')->toArray();
        $this->barcodeMap = $products->pluck('id', 'barcode')->filter()->toArray();
        $this->skuAliases = config('sku-aliases', []);
        $this->titleMap = config('title-product-map', []);
    }
}
