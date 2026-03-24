<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductStockSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OdooProductSyncer
{
    protected int $syncedCount = 0;

    protected int $snapshotCount = 0;

    public function __construct(
        protected OdooClient $odoo,
    ) {}

    /**
     * Sync all active products with SKU from Odoo and record stock snapshots.
     *
     * @return array{products: int, snapshots: int}
     */
    public function sync(): array
    {
        $this->syncedCount = 0;
        $this->snapshotCount = 0;

        $now = CarbonImmutable::now();

        Log::info('Odoo product sync starting');

        $odooProducts = $this->odoo->searchRead(
            'product.product',
            [['default_code', '!=', false], ['active', '=', true]],
            [
                'default_code', 'name', 'standard_price', 'list_price',
                'qty_available', 'virtual_available', 'free_qty',
                'weight', 'barcode', 'categ_id',
            ],
        );

        Log::info('Fetched products from Odoo', ['count' => count($odooProducts)]);

        foreach ($odooProducts as $odooProduct) {
            $this->upsertProduct($odooProduct, $now);
        }

        $this->deactivateMissing($odooProducts);

        Log::info('Odoo product sync completed', [
            'products' => $this->syncedCount,
            'snapshots' => $this->snapshotCount,
        ]);

        return [
            'products' => $this->syncedCount,
            'snapshots' => $this->snapshotCount,
        ];
    }

    /**
     * Enrich products table with product_type from Shopify line items.
     * Fills product_type for products that have it null but have matching line items.
     */
    public function enrichFromShopifyLineItems(): int
    {
        $enriched = 0;

        $products = Product::query()
            ->whereNull('product_type')
            ->get();

        foreach ($products as $product) {
            $productType = DB::table('shopify_line_items')
                ->where('sku', $product->sku)
                ->whereNotNull('product_type')
                ->groupBy('product_type')
                ->orderByRaw('COUNT(*) DESC')
                ->value('product_type');

            if ($productType) {
                $product->update(['product_type' => $productType]);
                $enriched++;
            }
        }

        return $enriched;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function upsertProduct(array $data, CarbonImmutable $now): void
    {
        $sku = trim($data['default_code']);

        if ($sku === '') {
            return;
        }

        $category = is_array($data['categ_id']) ? $data['categ_id'][1] : null;

        $product = Product::query()->updateOrCreate(
            ['sku' => $sku],
            [
                'name' => $data['name'],
                'category' => $category,
                'odoo_product_id' => $data['id'],
                'cost_price' => $data['standard_price'],
                'list_price' => $data['list_price'],
                'weight' => $data['weight'] ?: null,
                'barcode' => $data['barcode'] ?: null,
                'is_active' => true,
                'last_synced_at' => $now,
            ],
        );

        ProductStockSnapshot::query()->create([
            'product_id' => $product->id,
            'qty_on_hand' => $data['qty_available'],
            'qty_forecasted' => $data['virtual_available'],
            'qty_free' => $data['free_qty'],
            'recorded_at' => $now,
        ]);

        $this->syncedCount++;
        $this->snapshotCount++;
    }

    /**
     * Mark products as inactive if they were not in the Odoo response.
     *
     * @param  array<int, array<string, mixed>>  $odooProducts
     */
    protected function deactivateMissing(array $odooProducts): void
    {
        $activeSkus = collect($odooProducts)
            ->pluck('default_code')
            ->map(fn (string $sku) => trim($sku))
            ->filter()
            ->toArray();

        Product::query()
            ->whereNotNull('odoo_product_id')
            ->where('is_active', true)
            ->whereNotIn('sku', $activeSkus)
            ->update(['is_active' => false]);
    }
}
