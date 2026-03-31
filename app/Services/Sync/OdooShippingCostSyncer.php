<?php

namespace App\Services\Sync;

use App\Models\ShopifyOrder;
use App\Services\Api\OdooClient;
use Illuminate\Support\Facades\DB;
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
        DB::connection()->disableQueryLog();

        Log::info('Odoo shipping cost sync starting');

        try {
            $this->syncCarrierNames();
        } catch (\Throwable $e) {
            Log::error('Odoo shipping cost sync failed during carrier name sync', [
                'carriers_synced_before_failure' => $this->carrierCount,
                'error' => $e->getMessage(),
            ]);
        }

        try {
            $this->syncExactCosts();
        } catch (\Throwable $e) {
            Log::error('Odoo shipping cost sync failed during exact cost sync', [
                'exact_costs_synced_before_failure' => $this->exactCount,
                'error' => $e->getMessage(),
            ]);
        }

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

        // Build order_number → carrier map
        $carrierByName = [];
        foreach ($saleOrders as $so) {
            $carrier = is_array($so['carrier_id']) ? $so['carrier_id'][1] : null;

            if ($carrier) {
                $carrierByName[$this->toOrderName($so['shopify_order_number'])] = $carrier;
            }
        }

        if (empty($carrierByName)) {
            return;
        }

        // Batch lookup: fetch orders that need a carrier update
        $orders = ShopifyOrder::query()
            ->whereIn('name', array_keys($carrierByName))
            ->whereNull('shipping_carrier')
            ->pluck('name');

        foreach ($orders as $name) {
            ShopifyOrder::query()
                ->where('name', $name)
                ->update(['shipping_carrier' => $carrierByName[$name]]);

            $this->carrierCount++;
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

        // Build order_name → cost map
        $costByName = [];
        foreach ($saleOrders as $so) {
            $cost = $costBySaleId[$so['id']] ?? null;

            if ($cost) {
                $costByName[$this->toOrderName($so['shopify_order_number'])] = round($cost, 2);
            }
        }

        if (empty($costByName)) {
            return;
        }

        // Batch lookup: fetch orders that need a cost update
        $orders = ShopifyOrder::query()
            ->whereIn('name', array_keys($costByName))
            ->whereNull('shipping_cost')
            ->pluck('name');

        foreach ($orders as $name) {
            ShopifyOrder::query()
                ->where('name', $name)
                ->update([
                    'shipping_cost' => $costByName[$name],
                    'shipping_cost_estimated' => false,
                ]);

            $this->exactCount++;
        }
    }

    /**
     * Convert a Shopify order number to the local name format.
     */
    protected function toOrderName(mixed $orderNumber): string
    {
        return '#'.$orderNumber;
    }
}
