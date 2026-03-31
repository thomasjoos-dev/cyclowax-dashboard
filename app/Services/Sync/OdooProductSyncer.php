<?php

namespace App\Services\Sync;

use App\Models\Product;
use App\Models\ProductStockSnapshot;
use App\Services\Api\OdooClient;
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
        DB::connection()->disableQueryLog();

        $now = CarbonImmutable::now();

        Log::info('Odoo product sync starting');

        try {
            $odooProducts = $this->odoo->searchRead(
                'product.product',
                [['default_code', '!=', false], ['active', '=', true]],
                [
                    'default_code', 'name', 'standard_price', 'list_price',
                    'qty_available', 'virtual_available', 'free_qty',
                    'weight', 'barcode', 'categ_id',
                ],
            );
        } catch (\Throwable $e) {
            Log::error('Odoo product sync failed: unable to fetch products', [
                'error' => $e->getMessage(),
            ]);

            return ['products' => 0, 'snapshots' => 0];
        }

        Log::info('Fetched products from Odoo', ['count' => count($odooProducts)]);

        foreach ($odooProducts as $odooProduct) {
            try {
                $this->upsertProduct($odooProduct, $now);
            } catch (\Throwable $e) {
                Log::warning('Failed to upsert Odoo product', [
                    'sku' => $odooProduct['default_code'] ?? 'unknown',
                    'error' => $e->getMessage(),
                ]);
            }
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
        $skus = Product::query()
            ->whereNull('product_type')
            ->pluck('sku')
            ->all();

        if (empty($skus)) {
            return 0;
        }

        // Single query: most common product_type per SKU
        $ranked = DB::table('shopify_line_items')
            ->select('sku', 'product_type', DB::raw('COUNT(*) as cnt'))
            ->whereIn('sku', $skus)
            ->whereNotNull('product_type')
            ->groupBy('sku', 'product_type');

        $typeMap = DB::query()
            ->fromSub(
                DB::query()
                    ->fromSub($ranked, 'grouped')
                    ->select('sku', 'product_type', 'cnt')
                    ->selectRaw('ROW_NUMBER() OVER (PARTITION BY sku ORDER BY cnt DESC) as rn'),
                'ranked',
            )
            ->where('rn', 1)
            ->pluck('product_type', 'sku');

        $enriched = 0;

        foreach ($typeMap as $sku => $productType) {
            Product::query()
                ->where('sku', $sku)
                ->whereNull('product_type')
                ->update(['product_type' => $productType]);

            $enriched++;
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

        $snapshotExists = ProductStockSnapshot::query()
            ->where('product_id', $product->id)
            ->where('recorded_at', $now)
            ->exists();

        if (! $snapshotExists) {
            ProductStockSnapshot::query()->create([
                'product_id' => $product->id,
                'qty_on_hand' => $data['qty_available'],
                'qty_forecasted' => $data['virtual_available'],
                'qty_free' => $data['free_qty'],
                'recorded_at' => $now,
            ]);

            $this->snapshotCount++;
        }

        $this->syncedCount++;
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
