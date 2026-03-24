<?php

namespace App\Services;

use App\Models\ShopifyCustomer;
use App\Models\ShopifyLineItem;
use App\Models\ShopifyOrder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ShopifyOrderSyncer
{
    protected int $syncedCount = 0;

    public function __construct(
        protected ShopifyClient $shopify,
        protected PostalProvinceResolver $provinceResolver,
    ) {}

    /**
     * Sync orders within a date range. Uses pagination for smaller sets,
     * bulk operations for larger ones.
     */
    public function sync(CarbonImmutable $from, CarbonImmutable $to): int
    {
        $this->syncedCount = 0;

        $count = $this->countOrders($from, $to);

        Log::info('Shopify order sync starting', [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'estimated_count' => $count,
        ]);

        if ($count > 1000) {
            $this->syncViaBulkOperation($from, $to);
        } else {
            $this->syncViaPagination($from, $to);
        }

        Log::info('Shopify order sync completed', ['synced' => $this->syncedCount]);

        return $this->syncedCount;
    }

    /**
     * Count orders in a date range to decide sync strategy.
     */
    protected function countOrders(CarbonImmutable $from, CarbonImmutable $to): int
    {
        $response = $this->shopify->query(<<<GRAPHQL
            {
                ordersCount(query: "created_at:>={$from->toDateString()} created_at:<={$to->toDateString()}") {
                    count
                }
            }
        GRAPHQL);

        return $response['data']['ordersCount']['count'] ?? 0;
    }

    /**
     * Sync orders using cursor-based pagination (for <1000 orders).
     */
    protected function syncViaPagination(CarbonImmutable $from, CarbonImmutable $to): void
    {
        $cursor = null;
        $hasNextPage = true;

        while ($hasNextPage) {
            $afterClause = $cursor ? ", after: \"{$cursor}\"" : '';

            $response = $this->shopify->query(<<<GRAPHQL
                {
                    orders(
                        first: 50,
                        query: "created_at:>={$from->toDateString()} created_at:<={$to->toDateString()}"
                        sortKey: CREATED_AT
                        {$afterClause}
                    ) {
                        pageInfo {
                            hasNextPage
                            endCursor
                        }
                        edges {
                            node {
                                id
                                name
                                createdAt
                                totalPriceSet { shopMoney { amount currencyCode } }
                                subtotalPriceSet { shopMoney { amount } }
                                totalShippingPriceSet { shopMoney { amount } }
                                totalTaxSet { shopMoney { amount } }
                                totalDiscountsSet { shopMoney { amount } }
                                totalRefundedSet { shopMoney { amount } }
                                displayFinancialStatus
                                displayFulfillmentStatus
                                billingAddress { countryCodeV2 provinceCode zip }
                                shippingAddress { countryCodeV2 provinceCode zip }
                                landingPageUrl
                                referrerUrl
                                sourceName
                                customerJourneySummary {
                                    firstVisit {
                                        source
                                        sourceType
                                        landingPage
                                        referrerUrl
                                        utmParameters { source medium campaign content term }
                                    }
                                    lastVisit {
                                        source
                                        sourceType
                                        landingPage
                                        referrerUrl
                                        utmParameters { source medium campaign content term }
                                    }
                                }
                                customer {
                                    id
                                    email
                                    numberOfOrders
                                    amountSpent { amount }
                                    defaultAddress { countryCodeV2 }
                                }
                                lineItems(first: 100) {
                                    edges {
                                        node {
                                            title
                                            product { productType }
                                            sku
                                            quantity
                                            originalUnitPriceSet { shopMoney { amount } }
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            GRAPHQL);

            $orders = $response['data']['orders'];
            $pageInfo = $orders['pageInfo'];

            foreach ($orders['edges'] as $edge) {
                $this->upsertOrder($edge['node']);
            }

            $hasNextPage = $pageInfo['hasNextPage'];
            $cursor = $pageInfo['endCursor'];
        }
    }

    /**
     * Sync orders using Shopify Bulk Operations (for >1000 orders).
     */
    protected function syncViaBulkOperation(CarbonImmutable $from, CarbonImmutable $to): void
    {
        $bulkQuery = <<<GRAPHQL
            {
                orders(query: "created_at:>={$from->toDateString()} created_at:<={$to->toDateString()}") {
                    edges {
                        node {
                            id
                            name
                            createdAt
                            totalPriceSet { shopMoney { amount currencyCode } }
                            subtotalPriceSet { shopMoney { amount } }
                            totalShippingPriceSet { shopMoney { amount } }
                            totalTaxSet { shopMoney { amount } }
                            totalDiscountsSet { shopMoney { amount } }
                            totalRefundedSet { shopMoney { amount } }
                            displayFinancialStatus
                            displayFulfillmentStatus
                            billingAddress { countryCodeV2 provinceCode zip }
                            shippingAddress { countryCodeV2 provinceCode zip }
                            landingPageUrl
                            referrerUrl
                            sourceName
                            customerJourneySummary {
                                firstVisit {
                                    source
                                    sourceType
                                    landingPage
                                    referrerUrl
                                    utmParameters { source medium campaign content term }
                                }
                                lastVisit {
                                    source
                                    sourceType
                                    landingPage
                                    referrerUrl
                                    utmParameters { source medium campaign content term }
                                }
                            }
                            customer {
                                id
                                email
                                numberOfOrders
                                amountSpent { amount }
                                defaultAddress { countryCodeV2 }
                            }
                            lineItems {
                                edges {
                                    node {
                                        title
                                        product { productType }
                                        sku
                                        quantity
                                        originalUnitPriceSet { shopMoney { amount } }
                                    }
                                }
                            }
                        }
                    }
                }
            }
        GRAPHQL;

        $operation = $this->shopify->bulkOperation($bulkQuery);

        Log::info('Bulk operation started', ['id' => $operation['id']]);

        // Poll until complete
        $maxAttempts = 120;
        $attempt = 0;

        while ($attempt < $maxAttempts) {
            sleep(5);
            $status = $this->shopify->bulkOperationStatus();
            $attempt++;

            if ($status['status'] === 'COMPLETED') {
                if ($status['url']) {
                    $this->processBulkResults($status['url']);
                }

                return;
            }

            if ($status['status'] === 'FAILED') {
                throw new \RuntimeException("Bulk operation failed: {$status['errorCode']}");
            }

            Log::info('Bulk operation polling', [
                'status' => $status['status'],
                'objects' => $status['objectCount'] ?? 0,
                'attempt' => $attempt,
            ]);
        }

        throw new \RuntimeException('Bulk operation timed out after 10 minutes.');
    }

    /**
     * Process JSONL results from a bulk operation.
     */
    protected function processBulkResults(string $url): void
    {
        $results = $this->shopify->bulkOperationResults($url);

        // Bulk operations return flat JSONL with __parentId references.
        // Orders come first, followed by their child line items.
        // Process each order once the next order appears (meaning all its line items have been read).
        $currentOrder = null;
        $currentLineItems = [];

        foreach ($results as $row) {
            if (isset($row['__parentId'])) {
                $currentLineItems[] = $row;
            } else {
                // New order encountered — flush the previous one
                if ($currentOrder !== null) {
                    $this->flushBulkOrder($currentOrder, $currentLineItems);
                }

                $currentOrder = $row;
                $currentLineItems = [];
            }
        }

        // Flush the last order
        if ($currentOrder !== null) {
            $this->flushBulkOrder($currentOrder, $currentLineItems);
        }
    }

    /**
     * Process a single order from bulk operation results.
     *
     * @param  array<string, mixed>  $orderData
     * @param  array<int, array<string, mixed>>  $lineItems
     */
    protected function flushBulkOrder(array $orderData, array $lineItems): void
    {
        $orderData['lineItems'] = [
            'edges' => array_map(
                fn (array $item) => ['node' => $item],
                $lineItems
            ),
        ];
        $this->upsertOrder($orderData);
    }

    /**
     * Upsert a single order from GraphQL response data.
     *
     * @param  array<string, mixed>  $data
     */
    protected function upsertOrder(array $data): void
    {
        DB::transaction(function () use ($data) {
            $customerId = null;

            if ($data['customer'] ?? null) {
                $customer = $this->upsertCustomer($data['customer']);
                $customerId = $customer->id;
            }

            $shopifyId = $this->extractGid($data['id']);

            $order = ShopifyOrder::query()->updateOrCreate(
                ['shopify_id' => $shopifyId],
                [
                    'name' => $data['name'],
                    'ordered_at' => $data['createdAt'],
                    'total_price' => $data['totalPriceSet']['shopMoney']['amount'],
                    'subtotal' => $data['subtotalPriceSet']['shopMoney']['amount'],
                    'shipping' => $data['totalShippingPriceSet']['shopMoney']['amount'],
                    'tax' => $data['totalTaxSet']['shopMoney']['amount'],
                    'discounts' => $data['totalDiscountsSet']['shopMoney']['amount'] ?? 0,
                    'refunded' => $data['totalRefundedSet']['shopMoney']['amount'] ?? 0,
                    'financial_status' => $data['displayFinancialStatus'],
                    'fulfillment_status' => $data['displayFulfillmentStatus'],
                    'customer_id' => $customerId,
                    'billing_country_code' => $data['billingAddress']['countryCodeV2'] ?? null,
                    'billing_province_code' => $data['billingAddress']['provinceCode'] ?? null,
                    'billing_postal_code' => $data['billingAddress']['zip'] ?? null,
                    'shipping_country_code' => $data['shippingAddress']['countryCodeV2'] ?? null,
                    'shipping_province_code' => $data['shippingAddress']['provinceCode'] ?? null,
                    'shipping_postal_code' => $data['shippingAddress']['zip'] ?? null,
                    'currency' => $data['totalPriceSet']['shopMoney']['currencyCode'],
                    // Attribution
                    'landing_page_url' => $data['landingPageUrl'] ?? null,
                    'referrer_url' => $data['referrerUrl'] ?? null,
                    'source_name' => $data['sourceName'] ?? null,
                    // First-touch
                    'ft_source' => $data['customerJourneySummary']['firstVisit']['source'] ?? null,
                    'ft_source_type' => $data['customerJourneySummary']['firstVisit']['sourceType'] ?? null,
                    'ft_utm_source' => $data['customerJourneySummary']['firstVisit']['utmParameters']['source'] ?? null,
                    'ft_utm_medium' => $data['customerJourneySummary']['firstVisit']['utmParameters']['medium'] ?? null,
                    'ft_utm_campaign' => $data['customerJourneySummary']['firstVisit']['utmParameters']['campaign'] ?? null,
                    'ft_utm_content' => $data['customerJourneySummary']['firstVisit']['utmParameters']['content'] ?? null,
                    'ft_utm_term' => $data['customerJourneySummary']['firstVisit']['utmParameters']['term'] ?? null,
                    'ft_landing_page' => $data['customerJourneySummary']['firstVisit']['landingPage'] ?? null,
                    'ft_referrer_url' => $data['customerJourneySummary']['firstVisit']['referrerUrl'] ?? null,
                    // Last-touch
                    'lt_source' => $data['customerJourneySummary']['lastVisit']['source'] ?? null,
                    'lt_source_type' => $data['customerJourneySummary']['lastVisit']['sourceType'] ?? null,
                    'lt_utm_source' => $data['customerJourneySummary']['lastVisit']['utmParameters']['source'] ?? null,
                    'lt_utm_medium' => $data['customerJourneySummary']['lastVisit']['utmParameters']['medium'] ?? null,
                    'lt_utm_campaign' => $data['customerJourneySummary']['lastVisit']['utmParameters']['campaign'] ?? null,
                    'lt_utm_content' => $data['customerJourneySummary']['lastVisit']['utmParameters']['content'] ?? null,
                    'lt_utm_term' => $data['customerJourneySummary']['lastVisit']['utmParameters']['term'] ?? null,
                    'lt_landing_page' => $data['customerJourneySummary']['lastVisit']['landingPage'] ?? null,
                    'lt_referrer_url' => $data['customerJourneySummary']['lastVisit']['referrerUrl'] ?? null,
                ]
            );

            $this->resolveProvinces($order);

            // Update customer order date boundaries
            if ($customerId) {
                $customer->update([
                    'first_order_at' => ShopifyOrder::query()->where('customer_id', $customerId)->min('ordered_at'),
                    'last_order_at' => ShopifyOrder::query()->where('customer_id', $customerId)->max('ordered_at'),
                ]);
            }

            // Replace line items
            $order->lineItems()->delete();

            $lineItems = $data['lineItems']['edges'] ?? [];

            foreach ($lineItems as $edge) {
                $item = $edge['node'];

                ShopifyLineItem::query()->create([
                    'order_id' => $order->id,
                    'product_title' => $item['title'],
                    'product_type' => $item['product']['productType'] ?? null,
                    'sku' => $item['sku'] ?? null,
                    'quantity' => $item['quantity'],
                    'price' => $item['originalUnitPriceSet']['shopMoney']['amount'],
                ]);
            }

            $this->syncedCount++;
        });
    }

    /**
     * Resolve province codes from postal codes when Shopify doesn't provide them.
     */
    protected function resolveProvinces(ShopifyOrder $order): void
    {
        $updates = [];

        if (! $order->shipping_province_code && $order->shipping_country_code && $order->shipping_postal_code) {
            $province = $this->provinceResolver->resolve($order->shipping_country_code, $order->shipping_postal_code);

            if ($province) {
                $updates['shipping_province_code'] = $province;
            }
        }

        if (! $order->billing_province_code && $order->billing_country_code && $order->billing_postal_code) {
            $province = $this->provinceResolver->resolve($order->billing_country_code, $order->billing_postal_code);

            if ($province) {
                $updates['billing_province_code'] = $province;
            }
        }

        if ($updates) {
            $order->update($updates);
        }
    }

    /**
     * Upsert a customer from GraphQL response data.
     *
     * @param  array<string, mixed>  $data
     */
    protected function upsertCustomer(array $data): ShopifyCustomer
    {
        $shopifyId = $this->extractGid($data['id']);

        return ShopifyCustomer::query()->updateOrCreate(
            ['shopify_id' => $shopifyId],
            [
                'email' => $data['email'] ?? null,
                'orders_count' => $data['numberOfOrders'] ?? 0,
                'total_spent' => $data['amountSpent']['amount'] ?? 0,
                'country_code' => $data['defaultAddress']['countryCodeV2'] ?? null,
            ]
        );
    }

    /**
     * Extract the numeric ID from a Shopify GID (e.g., "gid://shopify/Order/123" → "123").
     */
    protected function extractGid(string $gid): string
    {
        return str_replace(['gid://shopify/Order/', 'gid://shopify/Customer/', 'gid://shopify/Product/'], '', $gid);
    }
}
