<?php

namespace App\Services\Forecast;

use App\Models\OpenPurchaseOrder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class ComponentNettingService
{
    /** @var array<int, float>|null */
    private ?array $stockCache = null;

    /** @var array<int, float>|null */
    private ?array $openPoQtyCache = null;

    /** @var array<int, array<int, OpenPurchaseOrder>>|null */
    private ?array $openPoDetailsCache = null;

    /**
     * Check freshness of the latest stock snapshot.
     *
     * @return array{latest_at: CarbonImmutable|null, age_hours: float|null, is_stale: bool}
     */
    public function stockFreshness(int $staleThresholdHours = 48): array
    {
        $row = DB::selectOne('SELECT MAX(recorded_at) as latest FROM product_stock_snapshots');
        $latestAt = $row?->latest ? CarbonImmutable::parse($row->latest) : null;

        if (! $latestAt) {
            return ['latest_at' => null, 'age_hours' => null, 'is_stale' => true];
        }

        $ageHours = $latestAt->diffInMinutes(now()) / 60;

        return [
            'latest_at' => $latestAt,
            'age_hours' => round($ageHours, 1),
            'is_stale' => $ageHours > $staleThresholdHours,
        ];
    }

    /**
     * Net component demand against current stock and open purchase orders.
     *
     * Expects aggregated demand (across all categories/SKUs) so shared
     * components are netted only once against available stock.
     *
     * @param  array<int, array{product_id: int, sku: string, name: string, total_quantity: float, procurement_lt: int|null}>  $componentDemand
     * @return array<int, array{product_id: int, sku: string, name: string, gross_need: float, stock_available: float, open_po_qty: float, net_need: float, procurement_lt: int|null}>
     */
    public function net(array $componentDemand): array
    {
        $currentStock = $this->getCurrentStockByProduct();
        $openPoQty = $this->getOpenPoQtyByProduct();

        $result = [];

        foreach ($componentDemand as $component) {
            $pid = $component['product_id'];
            $stock = $currentStock[$pid] ?? 0;
            $openPo = $openPoQty[$pid] ?? 0;
            $grossNeed = $component['total_quantity'];
            $netNeed = max(0, $grossNeed - $stock - $openPo);

            $result[] = [
                'product_id' => $pid,
                'sku' => $component['sku'],
                'name' => $component['name'],
                'gross_need' => $grossNeed,
                'stock_available' => $stock,
                'open_po_qty' => $openPo,
                'net_need' => $netNeed,
                'procurement_lt' => $component['procurement_lt'],
            ];
        }

        return $result;
    }

    /**
     * Net intermediate product demand against current stock.
     *
     * Intermediates are products with a normal BOM that are assembled in-house.
     * If we already have stock of the intermediate, we don't need to produce all
     * of them — only the net shortfall needs production orders.
     *
     * @param  array<int, array{product_id: int, sku: string, name: string, quantity: float, assembly_days: float, bom_type: string}>  $intermediates
     * @return array<int, array{product_id: int, sku: string, name: string, gross_quantity: float, stock_available: float, net_quantity: float, assembly_days: float, bom_type: string}>
     */
    public function netIntermediateDemand(array $intermediates): array
    {
        $currentStock = $this->getCurrentStockByProduct();

        $result = [];

        foreach ($intermediates as $intermediate) {
            $pid = $intermediate['product_id'];
            $stock = $currentStock[$pid] ?? 0;
            $grossQty = $intermediate['quantity'];
            $netQty = max(0, $grossQty - $stock);

            $result[] = [
                'product_id' => $pid,
                'sku' => $intermediate['sku'],
                'name' => $intermediate['name'],
                'gross_quantity' => $grossQty,
                'stock_available' => $stock,
                'net_quantity' => $netQty,
                'assembly_days' => $intermediate['assembly_days'],
                'bom_type' => $intermediate['bom_type'],
            ];
        }

        return $result;
    }

    /**
     * Build a human-readable netting note for a component.
     */
    public function buildNettingNote(array $comp): string
    {
        $parts = ["Need {$comp['gross_need']}"];

        if ($comp['stock_available'] > 0) {
            $parts[] = "stock {$comp['stock_available']}";
        }
        if ($comp['open_po_qty'] > 0) {
            $parts[] = "open PO {$comp['open_po_qty']}";
        }

        $parts[] = "→ order {$comp['net_need']}";

        return implode(', ', $parts);
    }

    /**
     * Look up the most recent supplier for a product based on purchase orders.
     */
    public function getSupplierForProduct(int $productId): string
    {
        $latestPo = OpenPurchaseOrder::where('product_id', $productId)
            ->orderByDesc('date_order')
            ->first();

        return $latestPo?->supplier_name ?? 'Unknown';
    }

    /**
     * Simulate stock month-by-month, accounting for when open POs actually arrive.
     *
     * Instead of deducting total stock + total open POs from total demand (day-0 snapshot),
     * this walks through each month: stock carries forward, POs arrive on their date_planned
     * month, and shortfalls are detected per month.
     *
     * @param  array<int, array<int, float>>  $monthlyDemand  [product_id => [month => demand]]
     * @param  array<int, array{sku: string, name: string, procurement_lt: int|null}>  $componentMeta
     * @param  int  $year  The forecast year (to filter PO arrivals)
     * @return array<int, array{product_id: int, sku: string, name: string, gross_need: float, stock_available: float, open_po_total: float, net_need: float, procurement_lt: int|null, first_shortfall_month: int|null, monthly: array<int, array{demand: float, po_arriving: float, stock_end: float, shortfall: float}>}>
     */
    public function rollingNet(array $monthlyDemand, array $componentMeta, int $year): array
    {
        $currentStock = $this->getCurrentStockByProduct();
        $monthlyArrivals = $this->getMonthlyPoArrivals($year);

        $result = [];

        foreach ($monthlyDemand as $pid => $months) {
            $meta = $componentMeta[$pid] ?? null;

            if (! $meta) {
                continue;
            }

            $runningStock = $currentStock[$pid] ?? 0;
            $initialStock = $runningStock;
            $grossNeed = array_sum($months);
            $openPoTotal = 0;
            $totalShortfall = 0;
            $firstShortfallMonth = null;
            $monthly = [];

            for ($m = 1; $m <= 12; $m++) {
                $demand = $months[$m] ?? 0;
                $poArriving = $monthlyArrivals[$pid][$m] ?? 0;
                $openPoTotal += $poArriving;

                $runningStock += $poArriving;
                $runningStock -= $demand;

                $shortfall = 0;
                if ($runningStock < 0) {
                    $shortfall = abs($runningStock);
                    $totalShortfall += $shortfall;
                    $runningStock = 0;

                    if ($firstShortfallMonth === null) {
                        $firstShortfallMonth = $m;
                    }
                }

                $monthly[$m] = [
                    'demand' => $demand,
                    'po_arriving' => $poArriving,
                    'stock_end' => $runningStock,
                    'shortfall' => $shortfall,
                ];
            }

            $result[] = [
                'product_id' => $pid,
                'sku' => $meta['sku'],
                'name' => $meta['name'],
                'gross_need' => $grossNeed,
                'stock_available' => $initialStock,
                'open_po_total' => $openPoTotal,
                'net_need' => $totalShortfall,
                'procurement_lt' => $meta['procurement_lt'],
                'first_shortfall_month' => $firstShortfallMonth,
                'monthly' => $monthly,
            ];
        }

        return $result;
    }

    /**
     * Group open PO quantities by product and planned arrival month.
     *
     * @return array<int, array<int, float>> [product_id => [month => arriving_qty]]
     */
    private function getMonthlyPoArrivals(int $year): array
    {
        $details = $this->getOpenPoDetailsByProduct();
        $result = [];

        foreach ($details as $productId => $pos) {
            foreach ($pos as $po) {
                if (! $po->date_planned) {
                    continue;
                }

                $plannedDate = CarbonImmutable::parse($po->date_planned);

                if ($plannedDate->year !== $year) {
                    continue;
                }

                $month = $plannedDate->month;
                $result[$productId][$month] = ($result[$productId][$month] ?? 0) + (float) $po->quantity_open;
            }
        }

        return $result;
    }

    /**
     * Clear all caches. Useful when stock state changes between operations.
     */
    public function clearCache(): void
    {
        $this->stockCache = null;
        $this->openPoQtyCache = null;
        $this->openPoDetailsCache = null;
    }

    /**
     * @return array<int, float>
     */
    public function getCurrentStockByProduct(): array
    {
        if ($this->stockCache !== null) {
            return $this->stockCache;
        }

        $rows = DB::select('
            SELECT pss.product_id, pss.qty_free
            FROM product_stock_snapshots pss
            INNER JOIN (
                SELECT product_id, MAX(recorded_at) as max_date
                FROM product_stock_snapshots
                GROUP BY product_id
            ) latest ON latest.product_id = pss.product_id AND latest.max_date = pss.recorded_at
        ');

        $this->stockCache = [];
        foreach ($rows as $row) {
            $this->stockCache[(int) $row->product_id] = (float) $row->qty_free;
        }

        return $this->stockCache;
    }

    /**
     * @return array<int, float>
     */
    public function getOpenPoQtyByProduct(): array
    {
        if ($this->openPoQtyCache !== null) {
            return $this->openPoQtyCache;
        }

        $rows = OpenPurchaseOrder::query()
            ->whereNotNull('product_id')
            ->selectRaw('product_id, SUM(quantity_open) as total_open')
            ->groupBy('product_id')
            ->get();

        $this->openPoQtyCache = [];
        foreach ($rows as $row) {
            $this->openPoQtyCache[(int) $row->product_id] = (float) $row->total_open;
        }

        return $this->openPoQtyCache;
    }

    /**
     * @return array<int, array<int, OpenPurchaseOrder>>
     */
    public function getOpenPoDetailsByProduct(): array
    {
        if ($this->openPoDetailsCache !== null) {
            return $this->openPoDetailsCache;
        }

        $this->openPoDetailsCache = OpenPurchaseOrder::query()
            ->whereNotNull('product_id')
            ->orderBy('date_planned')
            ->get()
            ->groupBy('product_id')
            ->map(fn ($pos) => $pos->all())
            ->all();

        return $this->openPoDetailsCache;
    }
}
