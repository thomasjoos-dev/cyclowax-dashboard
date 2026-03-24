<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ShopifyCustomer;
use App\Models\ShopifyLineItem;
use App\Models\ShopifyOrder;
use App\Services\ShippingCostEstimator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('orders:compute-margins {--full : Recompute all orders, not just new ones}')]
#[Description('Link line items to products, compute net revenue/COGS/margin per order, and classify first orders')]
class ComputeOrderMarginsCommand extends Command
{
    public function handle(): int
    {
        $this->linkLineItems();
        $this->computeOrderMargins();
        $this->classifyFirstOrders();
        $this->updateCustomerAggregates();

        return self::SUCCESS;
    }

    protected function linkLineItems(): void
    {
        $this->info('Linking line items to products...');

        $products = Product::all();
        $skuMap = $products->pluck('id', 'sku')->toArray();
        $barcodeMap = $products->pluck('id', 'barcode')->filter()->toArray();
        $skuAliases = config('sku-aliases', []);
        $titleMap = config('title-product-map', []);

        $stats = ['sku' => 0, 'barcode' => 0, 'alias' => 0, 'title' => 0, 'cost' => 0];

        ShopifyLineItem::query()
            ->whereNull('product_id')
            ->chunkById(1000, function ($lineItems) use ($skuMap, $barcodeMap, $skuAliases, $titleMap, &$stats) {
                foreach ($lineItems as $lineItem) {
                    $productId = $this->resolveProductId($lineItem, $skuMap, $barcodeMap, $skuAliases, $titleMap, $stats);

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

        $total = array_sum($stats) - $stats['cost'];
        $this->info("  Linked: {$total} (SKU: {$stats['sku']}, barcode: {$stats['barcode']}, alias: {$stats['alias']}, title: {$stats['title']}), COGS set: {$stats['cost']}");
    }

    /**
     * Try to resolve a product ID using multiple matching strategies.
     *
     * @param  array<string, int>  $skuMap
     * @param  array<string, int>  $barcodeMap
     * @param  array<string, string>  $skuAliases
     * @param  array<string, string>  $titleMap
     * @param  array<string, int>  $stats
     */
    protected function resolveProductId(
        ShopifyLineItem $lineItem,
        array $skuMap,
        array $barcodeMap,
        array $skuAliases,
        array $titleMap,
        array &$stats,
    ): ?int {
        $sku = $lineItem->sku ? trim($lineItem->sku) : '';

        if ($sku !== '') {
            // 1. Direct SKU match
            if (isset($skuMap[$sku])) {
                $stats['sku']++;

                return $skuMap[$sku];
            }

            // 2. Barcode match (EAN as SKU)
            $stripped = ltrim($sku, '0');
            $productId = $barcodeMap[$sku] ?? $barcodeMap[$stripped] ?? $barcodeMap['0'.$sku] ?? null;

            if ($productId) {
                $stats['barcode']++;

                return $productId;
            }

            // 3. SKU alias (legacy numeric SKUs)
            $aliasedSku = $skuAliases[$sku] ?? null;

            if ($aliasedSku && isset($skuMap[$aliasedSku])) {
                $stats['alias']++;

                return $skuMap[$aliasedSku];
            }
        }

        // 4. Product title match
        $title = $lineItem->product_title ? mb_strtolower(trim($lineItem->product_title)) : '';
        $mappedSku = $titleMap[$title] ?? null;

        if ($mappedSku && isset($skuMap[$mappedSku])) {
            $stats['title']++;

            return $skuMap[$mappedSku];
        }

        return null;
    }

    protected function computeOrderMargins(): void
    {
        $full = $this->option('full');
        $this->info($full ? 'Recomputing ALL order margins...' : 'Computing margins for new orders...');

        $feePercentage = config('fees.payment.percentage', 1.9) / 100;
        $feeFixed = config('fees.payment.fixed', 0.25);
        $estimator = app(ShippingCostEstimator::class);

        $computed = 0;

        $query = ShopifyOrder::query();

        if (! $full) {
            $query->whereNull('net_revenue');
        }

        $query->chunkById(500, function ($orders) use (&$computed, $feePercentage, $feeFixed, $estimator) {
            foreach ($orders as $order) {
                $totalCost = $order->lineItems()
                    ->whereNotNull('cost_price')
                    ->selectRaw('SUM(cost_price * quantity) as total')
                    ->value('total') ?? 0;

                $paymentFee = round($order->total_price * $feePercentage + $feeFixed, 2);
                $netRevenue = round($order->total_price - $order->tax - $order->refunded, 2);

                // Shipping cost: use exact (from Odoo) or estimate
                $shippingCost = $order->shipping_cost;
                $estimated = $order->shipping_cost_estimated;

                if ($shippingCost === null) {
                    $shippingCost = $estimator->estimate($order->shipping_carrier, $order->shipping_country_code);
                    $estimated = true;
                }

                $shippingMargin = $shippingCost !== null
                    ? round($order->shipping - $shippingCost, 2)
                    : null;

                $order->update([
                    'net_revenue' => $netRevenue,
                    'total_cost' => $totalCost,
                    'payment_fee' => $paymentFee,
                    'shipping_cost' => $shippingCost !== null ? round($shippingCost, 2) : null,
                    'shipping_cost_estimated' => $estimated ?? false,
                    'shipping_margin' => $shippingMargin,
                    'gross_margin' => round($netRevenue - $totalCost - $paymentFee - ($shippingCost ?? 0), 2),
                ]);

                $computed++;
            }
        });

        $this->info("  Orders computed: {$computed}");
    }

    protected function classifyFirstOrders(): void
    {
        $this->info('Classifying first orders...');

        $classified = 0;

        ShopifyOrder::query()
            ->whereNull('is_first_order')
            ->whereNotNull('customer_id')
            ->orderBy('ordered_at')
            ->chunkById(500, function ($orders) use (&$classified) {
                foreach ($orders as $order) {
                    $earlierOrderExists = ShopifyOrder::query()
                        ->where('customer_id', $order->customer_id)
                        ->where('ordered_at', '<', $order->ordered_at)
                        ->exists();

                    $order->update(['is_first_order' => ! $earlierOrderExists]);
                    $classified++;
                }
            });

        // Orders without customer are always "first"
        ShopifyOrder::query()
            ->whereNull('is_first_order')
            ->whereNull('customer_id')
            ->update(['is_first_order' => true]);

        $this->info("  Classified: {$classified} orders");
    }

    protected function updateCustomerAggregates(): void
    {
        $this->info('Updating customer aggregates...');

        $updated = 0;

        ShopifyCustomer::query()
            ->chunkById(500, function ($customers) use (&$updated) {
                foreach ($customers as $customer) {
                    $stats = DB::table('shopify_orders')
                        ->where('customer_id', $customer->id)
                        ->selectRaw('COUNT(*) as order_count, COALESCE(SUM(total_cost), 0) as total_cost')
                        ->first();

                    $firstOrderChannel = ShopifyOrder::query()
                        ->where('customer_id', $customer->id)
                        ->where('is_first_order', true)
                        ->value('ft_source');

                    $customer->update([
                        'local_orders_count' => $stats->order_count,
                        'total_cost' => $stats->total_cost,
                        'first_order_channel' => $firstOrderChannel,
                    ]);

                    $updated++;
                }
            });

        $this->info("  Customers updated: {$updated}");
    }
}
