<?php

namespace App\Services\Sync;

use App\Models\Product;
use App\Models\ProductBom;
use App\Models\ProductBomLine;
use App\Services\Api\OdooClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OdooBomSyncer
{
    public function __construct(
        protected OdooClient $odoo,
    ) {}

    /**
     * Sync BOMs, BOM lines and assembly lead times from Odoo.
     *
     * @return array{boms: int, lines: int, skipped: int}
     */
    public function sync(): array
    {
        DB::connection()->disableQueryLog();

        Log::info('Odoo BOM sync starting');

        try {
            $odooBoms = $this->fetchBoms();
            $odooBomLines = $this->fetchBomLines();
            $manufacturingOrders = $this->fetchManufacturingOrders();
        } catch (\Throwable $e) {
            Log::error('Odoo BOM sync failed: unable to fetch data', [
                'error' => $e->getMessage(),
            ]);

            return ['boms' => 0, 'lines' => 0, 'skipped' => 0];
        }

        Log::info('Fetched BOM data from Odoo', [
            'boms' => count($odooBoms),
            'bom_lines' => count($odooBomLines),
            'manufacturing_orders' => count($manufacturingOrders),
        ]);

        $productMap = $this->buildProductMap();
        $templateMap = $this->buildTemplateMap($productMap);
        $reverseProductMap = array_flip($productMap); // local_id → odoo_product_id
        $moByProduct = $this->groupManufacturingOrders($manufacturingOrders);

        $bomCount = 0;
        $lineCount = 0;
        $skipped = 0;
        $syncedOdooBomIds = [];

        // Index BOM lines by bom_id for quick lookup
        $linesByBom = [];

        foreach ($odooBomLines as $line) {
            $bomId = is_array($line['bom_id']) ? $line['bom_id'][0] : $line['bom_id'];
            $linesByBom[$bomId][] = $line;
        }

        foreach ($odooBoms as $odooBom) {
            $localProductId = $this->resolveLocalProductId($odooBom, $productMap, $templateMap);

            if ($localProductId === null) {
                $skipped++;

                continue;
            }

            $odooProductId = $reverseProductMap[$localProductId] ?? null;
            $assemblyData = $this->calculateAssemblyLeadTime($moByProduct, $odooProductId);

            DB::transaction(function () use ($odooBom, $localProductId, $assemblyData, $linesByBom, $productMap, &$bomCount, &$lineCount, &$skipped) {
                $bom = ProductBom::query()->updateOrCreate(
                    ['odoo_bom_id' => $odooBom['id']],
                    [
                        'product_id' => $localProductId,
                        'bom_type' => $odooBom['type'] === 'phantom' ? 'phantom' : 'normal',
                        'product_qty' => $odooBom['product_qty'],
                        'assembly_lead_time_days' => $assemblyData['days'],
                        'assembly_time_source' => $assemblyData['source'],
                        'assembly_time_samples' => $assemblyData['samples'],
                    ],
                );

                // Delete existing lines and recreate
                ProductBomLine::query()->where('bom_id', $bom->id)->delete();

                $bomLines = $linesByBom[$odooBom['id']] ?? [];

                foreach ($bomLines as $line) {
                    $componentOdooId = is_array($line['product_id']) ? $line['product_id'][0] : $line['product_id'];
                    $componentLocalId = $productMap[$componentOdooId] ?? null;

                    if ($componentLocalId === null) {
                        Log::debug('Skipping BOM line: component not found locally', [
                            'bom_id' => $odooBom['id'],
                            'odoo_product_id' => $componentOdooId,
                        ]);
                        $skipped++;

                        continue;
                    }

                    ProductBomLine::query()->create([
                        'bom_id' => $bom->id,
                        'component_product_id' => $componentLocalId,
                        'quantity' => $line['product_qty'],
                    ]);

                    $lineCount++;
                }

                $bomCount++;
            });

            $syncedOdooBomIds[] = $odooBom['id'];
        }

        // Cleanup removed BOMs
        $deleted = ProductBom::query()
            ->whereNotIn('odoo_bom_id', $syncedOdooBomIds)
            ->delete();

        if ($deleted > 0) {
            Log::info('Cleaned up removed BOMs', ['deleted' => $deleted]);
        }

        Log::info('Odoo BOM sync completed', [
            'boms' => $bomCount,
            'lines' => $lineCount,
            'skipped' => $skipped,
        ]);

        return [
            'boms' => $bomCount,
            'lines' => $lineCount,
            'skipped' => $skipped,
        ];
    }

    /**
     * Fetch all BOMs from Odoo.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function fetchBoms(): array
    {
        return $this->odoo->searchRead(
            'mrp.bom',
            [],
            ['id', 'product_tmpl_id', 'product_id', 'product_qty', 'type'],
        );
    }

    /**
     * Fetch all BOM lines from Odoo.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function fetchBomLines(): array
    {
        return $this->odoo->searchRead(
            'mrp.bom.line',
            [],
            ['bom_id', 'product_id', 'product_qty'],
        );
    }

    /**
     * Fetch all done manufacturing orders from Odoo in batches.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function fetchManufacturingOrders(): array
    {
        $batchSize = 200;
        $offset = 0;
        $allOrders = [];

        do {
            $batch = $this->odoo->searchRead(
                'mrp.production',
                [['state', '=', 'done']],
                ['product_id', 'product_qty', 'date_start', 'date_finished', 'duration'],
                $batchSize,
                $offset,
            );

            $allOrders = array_merge($allOrders, $batch);
            $offset += $batchSize;
        } while (count($batch) === $batchSize);

        return $allOrders;
    }

    /**
     * Build a map from Odoo product_id to local products.id.
     *
     * @return array<int, int>
     */
    protected function buildProductMap(): array
    {
        return Product::query()
            ->whereNotNull('odoo_product_id')
            ->pluck('id', 'odoo_product_id')
            ->all();
    }

    /**
     * Build a map from Odoo product_tmpl_id to local products.id.
     * Fetches product.product records from Odoo to get the template mapping.
     *
     * @param  array<int, int>  $productMap
     * @return array<int, int>
     */
    protected function buildTemplateMap(array $productMap): array
    {
        if (empty($productMap)) {
            return [];
        }

        $odooProducts = $this->odoo->searchRead(
            'product.product',
            [['id', 'in', array_map('intval', array_keys($productMap))]],
            ['id', 'product_tmpl_id'],
        );

        $templateMap = [];

        foreach ($odooProducts as $odooProduct) {
            $templateId = is_array($odooProduct['product_tmpl_id'])
                ? $odooProduct['product_tmpl_id'][0]
                : $odooProduct['product_tmpl_id'];

            $localId = $productMap[$odooProduct['id']] ?? null;

            if ($localId !== null && ! isset($templateMap[$templateId])) {
                $templateMap[$templateId] = $localId;
            }
        }

        return $templateMap;
    }

    /**
     * Group manufacturing orders by Odoo product_id.
     *
     * @param  array<int, array<string, mixed>>  $orders
     * @return array<int, array<int, array<string, mixed>>>
     */
    protected function groupManufacturingOrders(array $orders): array
    {
        $grouped = [];

        foreach ($orders as $order) {
            $productId = is_array($order['product_id']) ? $order['product_id'][0] : $order['product_id'];
            $grouped[$productId][] = $order;
        }

        return $grouped;
    }

    /**
     * Resolve a BOM's Odoo product to a local product_id.
     *
     * @param  array<string, mixed>  $odooBom
     * @param  array<int, int>  $productMap
     * @param  array<int, int>  $templateMap
     */
    protected function resolveLocalProductId(array $odooBom, array $productMap, array $templateMap): ?int
    {
        // Priority 1: direct product_id match
        if (! empty($odooBom['product_id']) && $odooBom['product_id'] !== false) {
            $odooProductId = is_array($odooBom['product_id']) ? $odooBom['product_id'][0] : $odooBom['product_id'];

            if (isset($productMap[$odooProductId])) {
                return $productMap[$odooProductId];
            }
        }

        // Priority 2: template match
        if (! empty($odooBom['product_tmpl_id']) && $odooBom['product_tmpl_id'] !== false) {
            $templateId = is_array($odooBom['product_tmpl_id']) ? $odooBom['product_tmpl_id'][0] : $odooBom['product_tmpl_id'];

            if (isset($templateMap[$templateId])) {
                return $templateMap[$templateId];
            }
        }

        return null;
    }

    /**
     * Calculate assembly lead time from manufacturing orders.
     *
     * @param  array<int, array<int, array<string, mixed>>>  $moByProduct
     * @return array{days: float, source: string|null, samples: int}
     */
    protected function calculateAssemblyLeadTime(array $moByProduct, ?int $odooProductId): array
    {
        $orders = $odooProductId !== null ? ($moByProduct[$odooProductId] ?? []) : [];

        if (empty($orders)) {
            return ['days' => 0, 'source' => null, 'samples' => 0];
        }

        // Priority 1: duration field (minutes tracked by Odoo)
        $durationOrders = array_filter($orders, fn (array $o) => ! empty($o['duration']) && (float) $o['duration'] > 0);

        if (! empty($durationOrders)) {
            $totalMinutes = 0.0;
            $totalQty = 0.0;

            foreach ($durationOrders as $order) {
                $totalMinutes += (float) $order['duration'];
                $totalQty += max((float) $order['product_qty'], 1);
            }

            if ($totalQty > 0) {
                $avgMinPerUnit = $totalMinutes / $totalQty;
                $avgBatch = $totalQty / count($durationOrders);
                $days = max(0.5, round(($avgMinPerUnit * $avgBatch) / 480, 1));

                return ['days' => $days, 'source' => 'duration', 'samples' => count($durationOrders)];
            }
        }

        // Priority 2: calendar days (date_finished - date_start)
        $calendarDays = [];

        foreach ($orders as $order) {
            if (empty($order['date_start']) || empty($order['date_finished'])) {
                continue;
            }

            try {
                $start = new \DateTimeImmutable($order['date_start']);
                $end = new \DateTimeImmutable($order['date_finished']);
                $diff = $end->diff($start)->days;

                if ($diff > 0) {
                    $calendarDays[] = $diff;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        if (! empty($calendarDays)) {
            sort($calendarDays);

            // Filter outliers: remove values > p75 x 3
            $p75Index = (int) floor(count($calendarDays) * 0.75);
            $p75 = $calendarDays[$p75Index] ?? end($calendarDays);
            $threshold = $p75 * 3;

            $filtered = array_values(array_filter($calendarDays, fn (int $d) => $d <= $threshold));

            if (! empty($filtered)) {
                sort($filtered);
                $count = count($filtered);
                $mid = (int) floor($count / 2);

                $median = $count % 2 === 0
                    ? ($filtered[$mid - 1] + $filtered[$mid]) / 2
                    : $filtered[$mid];

                return ['days' => round($median, 1), 'source' => 'calendar', 'samples' => $count];
            }
        }

        // Priority 3: no data
        return ['days' => 0, 'source' => null, 'samples' => 0];
    }
}
