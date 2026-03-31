<?php

namespace App\Services\Analysis;

use App\Services\Api\OdooClient;

class OdooB2bSalesService
{
    public function __construct(
        private OdooClient $odoo,
    ) {}

    /**
     * Fetch B2B sales from Odoo, aggregated per product SKU.
     *
     * @return array<string, array{name: string, sku: string, qty: float, revenue: float, orders: int}>
     */
    public function salesByProduct(string $since): array
    {
        $b2bOrders = $this->odoo->searchRead(
            'sale.order',
            [
                ['date_order', '>=', $since],
                ['state', 'in', ['sale', 'done']],
                ['shopify_order_number', '=', false],
            ],
            ['name', 'partner_id', 'date_order', 'amount_untaxed', 'order_line'],
            500,
        );

        $allLineIds = [];
        foreach ($b2bOrders as $order) {
            if (is_array($order['order_line'])) {
                $allLineIds = array_merge($allLineIds, $order['order_line']);
            }
        }

        $byProduct = [];
        $offset = 0;
        $batchSize = 1000;

        while ($offset < count($allLineIds)) {
            $lines = $this->odoo->searchRead(
                'sale.order.line',
                [['id', 'in', $allLineIds]],
                ['product_id', 'name', 'product_uom_qty', 'price_subtotal'],
                $batchSize,
                $offset,
            );

            foreach ($lines as $line) {
                $productName = is_array($line['product_id']) ? $line['product_id'][1] : $line['name'];

                $sku = 'unknown';
                if (preg_match('/^\[([^\]]+)\]/', $productName, $matches)) {
                    $sku = $matches[1];
                    $productName = trim(preg_replace('/^\[[^\]]+\]\s*/', '', $productName));
                }

                if (! isset($byProduct[$sku])) {
                    $byProduct[$sku] = [
                        'name' => $productName,
                        'sku' => $sku,
                        'qty' => 0,
                        'revenue' => 0,
                        'orders' => 0,
                    ];
                }
                $byProduct[$sku]['qty'] += $line['product_uom_qty'];
                $byProduct[$sku]['revenue'] += $line['price_subtotal'];
                $byProduct[$sku]['orders']++;
            }

            $offset += $batchSize;
        }

        uasort($byProduct, fn (array $a, array $b): int => $b['revenue'] <=> $a['revenue']);

        return $byProduct;
    }
}
