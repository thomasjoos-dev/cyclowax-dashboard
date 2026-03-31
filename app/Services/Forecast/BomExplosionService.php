<?php

namespace App\Services\Forecast;

use App\Models\Product;
use App\Models\ProductBom;
use App\Models\ProductBomLine;
use App\Models\SupplyProfile;
use Illuminate\Support\Collection;

class BomExplosionService
{
    /** @var array<int, ProductBom|null> */
    private array $bomCache = [];

    /** @var array<int, Collection<int, ProductBomLine>> */
    private array $linesCache = [];

    /** @var array<int, Product> */
    private array $productCache = [];

    /** @var array<string, int> */
    private array $procurementLtCache = [];

    /**
     * Recursively explode a product's BOM to leaf components.
     *
     * Returns a flat list of raw material components with aggregated quantities.
     * Phantom BOMs are traversed automatically. Normal BOMs stop at the intermediate product
     * (which has its own stock and assembly step).
     *
     * @return array<int, array{product_id: int, sku: string, name: string, quantity: float, procurement_lt: int|null}>
     */
    public function explode(int $productId, float $parentQty = 1.0): array
    {
        return $this->doExplode($productId, $parentQty, 0, []);
    }

    /**
     * Calculate the effective lead time for a product (max component chain + assembly).
     *
     * Walks the full BOM tree, calculating the longest path from raw material procurement
     * through all assembly steps to the finished product.
     */
    public function effectiveLeadTime(int $productId): float
    {
        return $this->calcEffectiveLt($productId, 0, []);
    }

    /**
     * Aggregate component demand across multiple SKUs.
     *
     * Takes a map of [product_id => quantity] (from SkuMixService::distribute)
     * and returns aggregated component demand across all products.
     *
     * @param  array<int, int>  $skuQuantities  [product_id => quantity]
     * @return array<int, array{product_id: int, sku: string, name: string, total_quantity: float, procurement_lt: int|null}>
     */
    public function componentDemand(array $skuQuantities): array
    {
        $aggregated = [];

        foreach ($skuQuantities as $productId => $quantity) {
            $components = $this->explode($productId, $quantity);

            foreach ($components as $component) {
                $pid = $component['product_id'];

                if (! isset($aggregated[$pid])) {
                    $aggregated[$pid] = [
                        'product_id' => $pid,
                        'sku' => $component['sku'],
                        'name' => $component['name'],
                        'total_quantity' => 0,
                        'procurement_lt' => $component['procurement_lt'],
                    ];
                }

                $aggregated[$pid]['total_quantity'] += $component['quantity'];
            }
        }

        // Sort by procurement lead time descending (longest first)
        uasort($aggregated, fn (array $a, array $b) => ($b['procurement_lt'] ?? 0) <=> ($a['procurement_lt'] ?? 0));

        return array_values($aggregated);
    }

    /**
     * Identify intermediate products (normal BOMs) that need production orders.
     *
     * Returns all normal-BOM products in the BOM tree with their assembly times.
     *
     * @return array<int, array{product_id: int, sku: string, name: string, quantity: float, assembly_days: float, bom_type: string}>
     */
    public function intermediateProducts(int $productId, float $parentQty = 1.0): array
    {
        return $this->findIntermediates($productId, $parentQty, 0, []);
    }

    /**
     * Backwards schedule: given a need date, when must each step happen?
     *
     * @return array<int, array{product_id: int, sku: string, name: string, event_type: string, date: string, quantity: float, depends_on: array<int>}>
     */
    public function backwardsSchedule(int $productId, string $needDate, float $quantity): array
    {
        $events = [];
        $this->buildSchedule($productId, $needDate, $quantity, $events, 0, []);

        usort($events, fn (array $a, array $b) => $a['date'] <=> $b['date']);

        return $events;
    }

    /**
     * Recursive BOM explosion.
     *
     * @param  array<int, true>  $visited
     * @return array<int, array{product_id: int, sku: string, name: string, quantity: float, procurement_lt: int|null}>
     */
    private function doExplode(int $productId, float $parentQty, int $depth, array $visited): array
    {
        if ($depth > 10 || isset($visited[$productId])) {
            return [];
        }

        $visited[$productId] = true;
        $bom = $this->getBom($productId);

        if (! $bom) {
            // Leaf node: raw material
            $product = $this->getProduct($productId);

            if (! $product) {
                return [];
            }

            return [[
                'product_id' => $productId,
                'sku' => $product->sku ?? '',
                'name' => $product->name ?? '',
                'quantity' => $parentQty,
                'procurement_lt' => $this->getProcurementLt($productId),
            ]];
        }

        $lines = $this->getBomLines($bom->id);
        $results = [];

        foreach ($lines as $line) {
            $componentQty = ($line->quantity / $bom->product_qty) * $parentQty;
            $childBom = $this->getBom($line->component_product_id);

            if ($childBom && $childBom->bom_type === 'phantom') {
                // Phantom: recurse through
                $results = array_merge(
                    $results,
                    $this->doExplode($line->component_product_id, $componentQty, $depth + 1, $visited),
                );
            } elseif ($childBom && $childBom->bom_type === 'normal') {
                // Normal intermediate: stop here (it has its own stock)
                $product = $this->getProduct($line->component_product_id);

                if ($product) {
                    $results[] = [
                        'product_id' => $line->component_product_id,
                        'sku' => $product->sku ?? '',
                        'name' => $product->name ?? '',
                        'quantity' => $componentQty,
                        'procurement_lt' => null, // not directly purchased
                    ];
                }
            } else {
                // No BOM: raw material leaf
                $results = array_merge(
                    $results,
                    $this->doExplode($line->component_product_id, $componentQty, $depth + 1, $visited),
                );
            }
        }

        return $results;
    }

    /**
     * Calculate effective lead time recursively.
     *
     * @param  array<int, true>  $visited
     */
    private function calcEffectiveLt(int $productId, int $depth, array $visited): float
    {
        if ($depth > 10 || isset($visited[$productId])) {
            return 0;
        }

        $visited[$productId] = true;
        $bom = $this->getBom($productId);

        if (! $bom) {
            // Leaf: procurement lead time only
            return (float) ($this->getProcurementLt($productId) ?? 0);
        }

        $lines = $this->getBomLines($bom->id);
        $maxChildLt = 0;

        foreach ($lines as $line) {
            $childLt = $this->calcEffectiveLt($line->component_product_id, $depth + 1, $visited);

            if ($childLt > $maxChildLt) {
                $maxChildLt = $childLt;
            }
        }

        return $maxChildLt + (float) $bom->assembly_lead_time_days;
    }

    /**
     * Find all intermediate products (normal BOMs) in the tree.
     *
     * @param  array<int, true>  $visited
     * @return array<int, array{product_id: int, sku: string, name: string, quantity: float, assembly_days: float, bom_type: string}>
     */
    private function findIntermediates(int $productId, float $parentQty, int $depth, array $visited): array
    {
        if ($depth > 10 || isset($visited[$productId])) {
            return [];
        }

        $visited[$productId] = true;
        $bom = $this->getBom($productId);

        if (! $bom) {
            return [];
        }

        $results = [];

        // This product itself is an intermediate if it's a normal BOM and not the root call
        if ($depth > 0 && $bom->bom_type === 'normal') {
            $product = $this->getProduct($productId);

            if ($product) {
                $results[] = [
                    'product_id' => $productId,
                    'sku' => $product->sku ?? '',
                    'name' => $product->name ?? '',
                    'quantity' => $parentQty,
                    'assembly_days' => (float) $bom->assembly_lead_time_days,
                    'bom_type' => $bom->bom_type,
                ];
            }
        }

        $lines = $this->getBomLines($bom->id);

        foreach ($lines as $line) {
            $componentQty = ($line->quantity / $bom->product_qty) * $parentQty;
            $results = array_merge(
                $results,
                $this->findIntermediates($line->component_product_id, $componentQty, $depth + 1, $visited),
            );
        }

        return $results;
    }

    /**
     * Build backwards schedule events recursively.
     *
     * @param  array<int, array{product_id: int, sku: string, name: string, event_type: string, date: string, quantity: float, depends_on: array<int>}>  $events
     * @param  array<int, true>  $visited
     */
    private function buildSchedule(int $productId, string $needDate, float $quantity, array &$events, int $depth, array $visited): void
    {
        if ($depth > 10 || isset($visited[$productId])) {
            return;
        }

        $visited[$productId] = true;
        $bom = $this->getBom($productId);
        $product = $this->getProduct($productId);

        if (! $product) {
            return;
        }

        if (! $bom) {
            // Leaf: needs a purchase order
            $lt = (int) ($this->getProcurementLt($productId) ?? 0);
            $orderDate = date('Y-m-d', strtotime("{$needDate} -{$lt} days"));

            $events[] = [
                'product_id' => $productId,
                'sku' => $product->sku ?? '',
                'name' => $product->name ?? '',
                'event_type' => 'purchase',
                'date' => $orderDate,
                'quantity' => $quantity,
                'depends_on' => [],
            ];

            $events[] = [
                'product_id' => $productId,
                'sku' => $product->sku ?? '',
                'name' => $product->name ?? '',
                'event_type' => 'receipt',
                'date' => $needDate,
                'quantity' => $quantity,
                'depends_on' => [],
            ];

            return;
        }

        $assemblyDays = (int) ceil((float) $bom->assembly_lead_time_days);

        // Production done by needDate, starts assemblyDays before
        $productionStart = date('Y-m-d', strtotime("{$needDate} -{$assemblyDays} days"));
        $componentsNeeded = $productionStart; // components must arrive before production starts

        if ($assemblyDays > 0 || $bom->bom_type === 'normal') {
            $events[] = [
                'product_id' => $productId,
                'sku' => $product->sku ?? '',
                'name' => $product->name ?? '',
                'event_type' => 'production_start',
                'date' => $productionStart,
                'quantity' => $quantity,
                'depends_on' => [],
            ];

            $events[] = [
                'product_id' => $productId,
                'sku' => $product->sku ?? '',
                'name' => $product->name ?? '',
                'event_type' => 'production_done',
                'date' => $needDate,
                'quantity' => $quantity,
                'depends_on' => [],
            ];
        }

        // Recurse into components
        $lines = $this->getBomLines($bom->id);

        foreach ($lines as $line) {
            $componentQty = ($line->quantity / $bom->product_qty) * $quantity;
            $this->buildSchedule($line->component_product_id, $componentsNeeded, $componentQty, $events, $depth + 1, $visited);
        }
    }

    private function getBom(int $productId): ?ProductBom
    {
        if (! array_key_exists($productId, $this->bomCache)) {
            $this->bomCache[$productId] = ProductBom::where('product_id', $productId)->first();
        }

        return $this->bomCache[$productId];
    }

    /**
     * @return Collection<int, ProductBomLine>
     */
    private function getBomLines(int $bomId): Collection
    {
        if (! isset($this->linesCache[$bomId])) {
            $this->linesCache[$bomId] = ProductBomLine::where('bom_id', $bomId)->get();
        }

        return $this->linesCache[$bomId];
    }

    private function getProduct(int $productId): ?Product
    {
        if (! array_key_exists($productId, $this->productCache)) {
            $this->productCache[$productId] = Product::find($productId);
        }

        return $this->productCache[$productId];
    }

    /**
     * Get procurement lead time for a product from the category-level supply profile.
     */
    private function getProcurementLt(int $productId): ?int
    {
        $product = $this->getProduct($productId);

        if (! $product || ! $product->product_category) {
            return null;
        }

        $category = $product->product_category->value;

        if (! isset($this->procurementLtCache[$category])) {
            $profile = SupplyProfile::where('product_category', $category)->first();
            $this->procurementLtCache[$category] = $profile?->procurement_lead_time_days ?? 0;
        }

        return $this->procurementLtCache[$category] ?: null;
    }
}
