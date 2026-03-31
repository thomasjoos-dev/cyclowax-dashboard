<?php

namespace App\Services\Scoring;

use App\Models\ShopifyCustomer;
use App\Models\ShopifyOrder;
use App\Services\Support\ShippingCostEstimator;
use Illuminate\Support\Facades\DB;

class OrderMarginCalculator
{
    public function __construct(
        private ShippingCostEstimator $shippingEstimator,
    ) {}

    /**
     * Compute net revenue, COGS, fees, and margins for orders.
     */
    public function computeMargins(bool $full = false): int
    {
        $feePercentage = config('fees.payment.percentage', 1.9) / 100;
        $feeFixed = config('fees.payment.fixed', 0.25);

        $computed = 0;

        $query = ShopifyOrder::query();

        if (! $full) {
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

                $shippingCost = $order->shipping_cost;
                $estimated = $order->shipping_cost_estimated;

                if ($shippingCost === null) {
                    $shippingCost = $this->shippingEstimator->estimate($order->shipping_carrier, $order->shipping_country_code);
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

        return $computed;
    }

    /**
     * Classify which orders are first orders per customer.
     */
    public function classifyFirstOrders(): int
    {
        $classified = 0;

        ShopifyOrder::query()
            ->whereNull('is_first_order')
            ->whereNotNull('customer_id')
            ->orderBy('ordered_at')
            ->chunkById(500, function ($orders) use (&$classified) {
                $firstOrderDates = ShopifyOrder::query()
                    ->whereIn('customer_id', $orders->pluck('customer_id')->unique())
                    ->groupBy('customer_id')
                    ->selectRaw('customer_id, MIN(ordered_at) as first_ordered_at')
                    ->pluck('first_ordered_at', 'customer_id');

                foreach ($orders as $order) {
                    $isFirst = $order->ordered_at <= ($firstOrderDates[$order->customer_id] ?? $order->ordered_at);
                    $order->update(['is_first_order' => $isFirst]);
                    $classified++;
                }
            });

        ShopifyOrder::query()
            ->whereNull('is_first_order')
            ->whereNull('customer_id')
            ->update(['is_first_order' => true]);

        return $classified;
    }

    /**
     * Update customer-level aggregates from order data.
     */
    public function updateCustomerAggregates(): int
    {
        $updated = 0;

        ShopifyCustomer::query()
            ->chunkById(500, function ($customers) use (&$updated) {
                $customerIds = $customers->pluck('id');

                $stats = DB::table('shopify_orders')
                    ->whereIn('customer_id', $customerIds)
                    ->groupBy('customer_id')
                    ->selectRaw('customer_id, COUNT(*) as order_count, COALESCE(SUM(total_cost), 0) as total_cost')
                    ->get()
                    ->keyBy('customer_id');

                $firstOrders = ShopifyOrder::query()
                    ->whereIn('customer_id', $customerIds)
                    ->where('is_first_order', true)
                    ->get(['customer_id', 'channel_type', 'refined_channel'])
                    ->keyBy('customer_id');

                foreach ($customers as $customer) {
                    $stat = $stats[$customer->id] ?? null;
                    $firstOrder = $firstOrders[$customer->id] ?? null;

                    $customer->update([
                        'local_orders_count' => $stat?->order_count ?? 0,
                        'total_cost' => $stat?->total_cost ?? 0,
                        'first_order_channel' => $firstOrder?->refined_channel ?? $firstOrder?->channel_type,
                    ]);

                    $updated++;
                }
            });

        return $updated;
    }
}
