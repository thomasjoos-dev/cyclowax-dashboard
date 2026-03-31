<?php

namespace App\Services\Sync;

use App\Models\OpenPurchaseOrder;
use App\Models\Product;
use App\Services\Api\OdooClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OdooOpenPoSyncer
{
    public function __construct(
        protected OdooClient $odoo,
    ) {}

    /**
     * Sync open (pending delivery) purchase order lines from Odoo.
     * Replaces all existing records each run (snapshot approach).
     *
     * @return array{synced: int, total_po_lines: int}
     */
    public function sync(): array
    {
        DB::connection()->disableQueryLog();

        Log::info('Open PO sync starting');

        $productMap = Product::query()
            ->whereNotNull('odoo_product_id')
            ->pluck('id', 'odoo_product_id')
            ->all();

        $batchSize = 200;
        $offset = 0;
        $totalLines = 0;
        $openLines = [];

        $domain = [
            ['product_id', '!=', false],
            ['state', 'in', ['purchase', 'done']],
        ];

        $fields = [
            'id', 'product_id', 'product_qty', 'qty_received',
            'price_unit', 'date_order', 'date_planned',
            'partner_id', 'order_id', 'state',
        ];

        try {
            while (true) {
                $batch = $this->odoo->searchRead(
                    'purchase.order.line',
                    $domain,
                    $fields,
                    $batchSize,
                    $offset,
                );

                if (empty($batch)) {
                    break;
                }

                $totalLines += count($batch);

                // Collect unique order IDs to fetch PO references
                $orderIds = collect($batch)
                    ->pluck('order_id')
                    ->map(fn (array|false $val) => is_array($val) ? $val[0] : null)
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();

                $orderNames = [];

                if (! empty($orderIds)) {
                    $orders = $this->odoo->searchRead(
                        'purchase.order',
                        [['id', 'in', $orderIds]],
                        ['name'],
                    );

                    foreach ($orders as $order) {
                        $orderNames[$order['id']] = $order['name'];
                    }
                }

                foreach ($batch as $line) {
                    $qtyOrdered = (float) $line['product_qty'];
                    $qtyReceived = (float) $line['qty_received'];

                    if ($qtyReceived >= $qtyOrdered) {
                        continue;
                    }

                    $odooProductId = is_array($line['product_id']) ? $line['product_id'][0] : $line['product_id'];
                    $productName = is_array($line['product_id']) ? $line['product_id'][1] : 'Unknown';
                    $orderId = is_array($line['order_id']) ? $line['order_id'][0] : null;
                    $supplierName = is_array($line['partner_id']) ? $line['partner_id'][1] : null;

                    $openLines[] = [
                        'odoo_po_line_id' => $line['id'],
                        'po_reference' => $orderNames[$orderId] ?? 'Unknown',
                        'product_id' => $productMap[$odooProductId] ?? null,
                        'odoo_product_id' => $odooProductId,
                        'product_name' => $productName,
                        'quantity_ordered' => $qtyOrdered,
                        'quantity_received' => $qtyReceived,
                        'quantity_open' => $qtyOrdered - $qtyReceived,
                        'unit_price' => $line['price_unit'] ?: null,
                        'date_order' => $line['date_order'] ?: null,
                        'date_planned' => $line['date_planned'] ?: null,
                        'supplier_name' => $supplierName,
                        'state' => $line['state'],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }

                Log::info('Open PO sync batch processed', [
                    'offset' => $offset,
                    'batch_size' => count($batch),
                    'open_lines_so_far' => count($openLines),
                ]);

                if (count($batch) < $batchSize) {
                    break;
                }

                $offset += $batchSize;
            }
        } catch (\Throwable $e) {
            Log::error('Open PO sync failed: unable to fetch PO lines', [
                'error' => $e->getMessage(),
            ]);

            return ['synced' => 0, 'total_po_lines' => 0];
        }

        DB::transaction(function () use ($openLines) {
            OpenPurchaseOrder::query()->truncate();

            foreach (array_chunk($openLines, 500) as $chunk) {
                OpenPurchaseOrder::query()->insert($chunk);
            }
        });

        Log::info('Open PO sync completed', [
            'synced' => count($openLines),
            'total_po_lines' => $totalLines,
        ]);

        return [
            'synced' => count($openLines),
            'total_po_lines' => $totalLines,
        ];
    }
}
