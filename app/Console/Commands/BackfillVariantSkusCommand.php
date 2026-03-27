<?php

namespace App\Console\Commands;

use App\Models\ShopifyLineItem;
use App\Models\ShopifyOrder;
use App\Services\ShopifyClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('orders:backfill-variant-skus {--dry-run : Show what would change without updating}')]
#[Description('Fetch variant SKUs from Shopify API for line items missing SKU data')]
class BackfillVariantSkusCommand extends Command
{
    private int $updated = 0;

    private int $notFound = 0;

    private int $alreadyHasSku = 0;

    private int $apiCalls = 0;

    public function handle(ShopifyClient $shopify): int
    {
        $dryRun = $this->option('dry-run');

        // Find all orders that have at least one line item without SKU
        $orderIds = DB::table('shopify_line_items')
            ->where(function ($q) {
                $q->whereNull('sku')->orWhere('sku', '');
            })
            ->distinct()
            ->pluck('order_id');

        $orders = ShopifyOrder::whereIn('id', $orderIds)
            ->whereNotNull('shopify_id')
            ->get();

        $this->info("Found {$orders->count()} orders with SKU-less line items");

        if ($dryRun) {
            $this->warn('DRY RUN — no changes will be made');
        }

        $bar = $this->output->createProgressBar($orders->count());
        $bar->start();

        foreach ($orders as $order) {
            $this->processOrder($shopify, $order, $dryRun);
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("Done: {$this->updated} SKUs backfilled, {$this->notFound} not found in Shopify, {$this->alreadyHasSku} already had SKU");
        $this->info("API calls: {$this->apiCalls}");

        if ($this->updated > 0 && ! $dryRun) {
            $this->newLine();
            $this->warn('Next steps:');
            $this->line('  1. Reset product_id on updated items:');
            $this->line('     php artisan tinker --execute "...(see docs)"');
            $this->line('  2. Re-run margin computation:');
            $this->line('     php artisan orders:compute-margins');
        }

        return self::SUCCESS;
    }

    private function processOrder(ShopifyClient $shopify, ShopifyOrder $order, bool $dryRun): void
    {
        $lineItems = $order->lineItems()
            ->where(function ($q) {
                $q->whereNull('sku')->orWhere('sku', '');
            })
            ->get();

        if ($lineItems->isEmpty()) {
            return;
        }

        $shopifyData = $this->fetchOrderLineItems($shopify, $order->shopify_id);

        if ($shopifyData === null) {
            $this->notFound += $lineItems->count();

            return;
        }

        foreach ($lineItems as $lineItem) {
            $match = $this->matchVariant($lineItem, $shopifyData);

            if ($match === null || $match['sku'] === null || $match['sku'] === '') {
                $this->notFound++;

                continue;
            }

            if (! $dryRun) {
                $lineItem->update(['sku' => $match['sku']]);
            }

            $this->updated++;

            if ($dryRun && $this->updated <= 10) {
                $this->newLine();
                $this->line("  Would update: [{$lineItem->product_title}] → SKU: {$match['sku']} (variant: {$match['variant_title']})");
            }
        }
    }

    /**
     * @return array<int, array{title: string, sku: ?string, variant_title: ?string, quantity: int}>|null
     */
    private function fetchOrderLineItems(ShopifyClient $shopify, string $shopifyId): ?array
    {
        $this->apiCalls++;

        try {
            $result = $shopify->query("
                query {
                    order(id: \"gid://shopify/Order/{$shopifyId}\") {
                        lineItems(first: 100) {
                            edges {
                                node {
                                    title
                                    sku
                                    quantity
                                    variant {
                                        sku
                                        title
                                    }
                                }
                            }
                        }
                    }
                }
            ");
        } catch (\Exception $e) {
            $this->error("  API error for order {$shopifyId}: {$e->getMessage()}");

            return null;
        }

        $edges = $result['data']['order']['lineItems']['edges'] ?? [];

        if (empty($edges)) {
            return null;
        }

        return array_map(fn ($edge) => [
            'title' => $edge['node']['title'],
            'sku' => $edge['node']['variant']['sku'] ?? $edge['node']['sku'] ?? null,
            'variant_title' => $edge['node']['variant']['title'] ?? null,
            'quantity' => $edge['node']['quantity'],
        ], $edges);
    }

    /**
     * Match a local line item to a Shopify line item by title and quantity.
     *
     * @param  array<int, array{title: string, sku: ?string, variant_title: ?string, quantity: int}>  $shopifyItems
     * @return array{sku: ?string, variant_title: ?string}|null
     */
    private function matchVariant(ShopifyLineItem $lineItem, array $shopifyItems): ?array
    {
        // Exact match on title + quantity
        foreach ($shopifyItems as $shopifyItem) {
            if (
                mb_strtolower($shopifyItem['title']) === mb_strtolower($lineItem->product_title)
                && $shopifyItem['quantity'] === $lineItem->quantity
            ) {
                return [
                    'sku' => $shopifyItem['sku'],
                    'variant_title' => $shopifyItem['variant_title'],
                ];
            }
        }

        // Fallback: match on title only (in case quantity was adjusted)
        foreach ($shopifyItems as $shopifyItem) {
            if (mb_strtolower($shopifyItem['title']) === mb_strtolower($lineItem->product_title)) {
                return [
                    'sku' => $shopifyItem['sku'],
                    'variant_title' => $shopifyItem['variant_title'],
                ];
            }
        }

        return null;
    }
}
