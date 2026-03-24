<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ShopifyCustomer;
use App\Models\ShopifyLineItem;
use App\Models\ShopifyOrder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('orders:compute-margins')]
#[Description('Link line items to products, compute COGS/margin per order, and classify first orders')]
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
        $this->info('Linking line items to products via SKU...');

        $productMap = Product::query()
            ->pluck('id', 'sku')
            ->toArray();

        $linked = 0;
        $costSet = 0;

        ShopifyLineItem::query()
            ->whereNull('product_id')
            ->whereNotNull('sku')
            ->where('sku', '!=', '')
            ->chunkById(1000, function ($lineItems) use ($productMap, &$linked, &$costSet) {
                foreach ($lineItems as $lineItem) {
                    $productId = $productMap[$lineItem->sku] ?? null;

                    if ($productId) {
                        $updates = ['product_id' => $productId];

                        if ($lineItem->cost_price === null) {
                            $costPrice = Product::where('id', $productId)->value('cost_price');

                            if ($costPrice) {
                                $updates['cost_price'] = $costPrice;
                                $costSet++;
                            }
                        }

                        $lineItem->update($updates);
                        $linked++;
                    }
                }
            });

        $this->info("  Linked: {$linked} line items, COGS set: {$costSet}");
    }

    protected function computeOrderMargins(): void
    {
        $this->info('Computing order margins...');

        $computed = 0;

        ShopifyOrder::query()
            ->whereNull('total_cost')
            ->whereHas('lineItems', fn ($q) => $q->whereNotNull('cost_price'))
            ->chunkById(500, function ($orders) use (&$computed) {
                foreach ($orders as $order) {
                    $totalCost = $order->lineItems()
                        ->whereNotNull('cost_price')
                        ->selectRaw('SUM(cost_price * quantity) as total')
                        ->value('total') ?? 0;

                    $order->update([
                        'total_cost' => $totalCost,
                        'gross_margin' => $order->subtotal - $totalCost,
                    ]);

                    $computed++;
                }
            });

        $this->info("  Orders with margin: {$computed}");
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
