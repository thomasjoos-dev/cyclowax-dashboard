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
        $full = $this->option('full');
        $this->info($full ? 'Recomputing ALL order margins...' : 'Computing margins for new orders...');

        $feePercentage = config('fees.payment.percentage', 1.9) / 100;
        $feeFixed = config('fees.payment.fixed', 0.25);

        $computed = 0;

        $query = ShopifyOrder::query();

        if (! $full) {
            // Only orders missing net_revenue (new/uncomputed)
            $query->whereNull('net_revenue');
        }

        $query->chunkById(500, function ($orders) use (&$computed, $feePercentage, $feeFixed) {
            foreach ($orders as $order) {
                $totalCost = $order->lineItems()
                    ->whereNotNull('cost_price')
                    ->selectRaw('SUM(cost_price * quantity) as total')
                    ->value('total') ?? 0;

                $paymentFee = round($order->total_price * $feePercentage + $feeFixed, 2);
                $netRevenue = round($order->total_price - $order->tax - $order->refunded, 2);

                $order->update([
                    'net_revenue' => $netRevenue,
                    'total_cost' => $totalCost,
                    'payment_fee' => $paymentFee,
                    'gross_margin' => round($netRevenue - $totalCost - $paymentFee, 2),
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
