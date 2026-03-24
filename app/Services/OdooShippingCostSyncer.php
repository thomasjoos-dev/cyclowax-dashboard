<?php

namespace App\Services;

use App\Models\ShopifyOrder;
use Illuminate\Support\Facades\Log;

class OdooShippingCostSyncer
{
    protected int $exactCount = 0;

    protected int $carrierCount = 0;

    public function __construct(
        protected OdooClient $odoo,
    ) {}

    /**
     * Sync shipping costs and carrier names from Odoo to local orders.
     *
     * @return array{exact_costs: int, carriers: int}
     */
    public function sync(): array
    {
        $this->exactCount = 0;
        $this->carrierCount = 0;

        Log::info('Odoo shipping cost sync starting');

        $this->syncCarrierNames();
        $this->syncExactCosts();

        Log::info('Odoo shipping cost sync completed', [
            'exact_costs' => $this->exactCount,
            'carriers' => $this->carrierCount,
        ]);

        return [
            'exact_costs' => $this->exactCount,
            'carriers' => $this->carrierCount,
        ];
    }

    /**
     * Sync carrier names from sale.order → our orders (broad coverage).
     */
    protected function syncCarrierNames(): void
    {
        $saleOrders = $this->odoo->searchRead(
            'sale.order',
            [['shopify_order_number', '!=', false], ['carrier_id', '!=', false]],
            ['shopify_order_number', 'carrier_id'],
        );

        foreach ($saleOrders as $so) {
            $orderName = '#'.$so['shopify_order_number'];
            $carrier = is_array($so['carrier_id']) ? $so['carrier_id'][1] : null;

            if (! $carrier) {
                continue;
            }

            $updated = ShopifyOrder::query()
                ->where('name', $orderName)
                ->whereNull('shipping_carrier')
                ->update(['shipping_carrier' => $carrier]);

            if ($updated) {
                $this->carrierCount++;
            }
        }
    }

    /**
     * Sync exact carrier costs from stock.picking → our orders.
     */
    protected function syncExactCosts(): void
    {
        $pickings = $this->odoo->searchRead(
            'stock.picking',
            [
                ['picking_type_code', '=', 'outgoing'],
                ['carrier_price', '>', 0],
                ['sale_id', '!=', false],
            ],
            ['carrier_price', 'sale_id'],
        );

        // Build sale_id → carrier_price map (take highest cost if multiple pickings per sale)
        $costBySaleId = [];
        foreach ($pickings as $p) {
            $saleId = is_array($p['sale_id']) ? $p['sale_id'][0] : $p['sale_id'];
            $costBySaleId[$saleId] = max($costBySaleId[$saleId] ?? 0, $p['carrier_price']);
        }

        if (empty($costBySaleId)) {
            return;
        }

        // Get sale orders to map sale_id → shopify order number
        $saleOrders = $this->odoo->searchRead(
            'sale.order',
            [['id', 'in', array_keys($costBySaleId)], ['shopify_order_number', '!=', false]],
            ['shopify_order_number'],
        );

        foreach ($saleOrders as $so) {
            $orderName = '#'.$so['shopify_order_number'];
            $cost = $costBySaleId[$so['id']] ?? null;

            if (! $cost) {
                continue;
            }

            $updated = ShopifyOrder::query()
                ->where('name', $orderName)
                ->whereNull('shipping_cost')
                ->update([
                    'shipping_cost' => round($cost, 2),
                    'shipping_cost_estimated' => false,
                ]);

            if ($updated) {
                $this->exactCount++;
            }
        }
    }
}
