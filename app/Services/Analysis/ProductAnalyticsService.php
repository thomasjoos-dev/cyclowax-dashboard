<?php

namespace App\Services\Analysis;

use App\Models\ShopifyLineItem;
use Illuminate\Support\Facades\Cache;

class ProductAnalyticsService
{
    /**
     * @return array<int, array{product_title: string, count: int, percentage: float}>
     */
    public function topProductsFirstOrder(int $limit = 10): array
    {
        return Cache::remember("dashboard:top_products_first:{$limit}", config('dashboard.cache_ttl'), function () use ($limit) {
            return $this->topProducts(true, $limit);
        });
    }

    /**
     * @return array<int, array{product_title: string, count: int, percentage: float}>
     */
    public function topProductsReturning(int $limit = 10): array
    {
        return Cache::remember("dashboard:top_products_returning:{$limit}", config('dashboard.cache_ttl'), function () use ($limit) {
            return $this->topProducts(false, $limit);
        });
    }

    /**
     * @return array<int, array{product_title: string, count: int, percentage: float}>
     */
    private function topProducts(bool $firstOrder, int $limit): array
    {
        $operator = $firstOrder ? '<=' : '>';

        $total = ShopifyLineItem::query()
            ->join('shopify_orders', 'shopify_line_items.order_id', '=', 'shopify_orders.id')
            ->join('shopify_customers', 'shopify_orders.customer_id', '=', 'shopify_customers.id')
            ->whereRaw("shopify_customers.orders_count {$operator} 1")
            ->count();

        if ($total === 0) {
            return [];
        }

        return ShopifyLineItem::query()
            ->join('shopify_orders', 'shopify_line_items.order_id', '=', 'shopify_orders.id')
            ->join('shopify_customers', 'shopify_orders.customer_id', '=', 'shopify_customers.id')
            ->whereRaw("shopify_customers.orders_count {$operator} 1")
            ->selectRaw('shopify_line_items.product_title, sum(shopify_line_items.quantity) as count')
            ->groupBy('shopify_line_items.product_title')
            ->orderByDesc('count')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'product_title' => $row->product_title,
                'count' => (int) $row->count,
                'percentage' => round(($row->count / $total) * 100, 1),
            ])
            ->toArray();
    }

    public function flushCache(): void
    {
        Cache::forget('dashboard:top_products_first:10');
        Cache::forget('dashboard:top_products_returning:10');
    }
}
