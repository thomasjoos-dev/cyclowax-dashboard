<?php

use App\Enums\ProductCategory;
use App\Models\Product;
use App\Models\ProductBom;
use App\Models\ProductBomLine;
use App\Models\SupplyProfile;
use App\Services\Forecast\Supply\BomExplosionService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createComponent(string $sku, string $name, ?ProductCategory $category = null): Product
{
    return Product::factory()->create([
        'sku' => $sku,
        'name' => $name,
        'product_category' => $category?->value,
    ]);
}

function createBom(Product $product, string $type = 'normal', float $productQty = 1.0, float $assemblyDays = 0): ProductBom
{
    static $bomCounter = 90000;

    return ProductBom::create([
        'product_id' => $product->id,
        'odoo_bom_id' => ++$bomCounter,
        'bom_type' => $type,
        'product_qty' => $productQty,
        'assembly_lead_time_days' => $assemblyDays,
    ]);
}

function addBomLine(ProductBom $bom, Product $component, float $quantity = 1.0): ProductBomLine
{
    return ProductBomLine::create([
        'bom_id' => $bom->id,
        'component_product_id' => $component->id,
        'quantity' => $quantity,
    ]);
}

// ── Normal 1-level BOM ─────────────────────────────────────────────

it('explodes a simple 1-level BOM to leaf components', function () {
    $finished = createComponent('FIN-001', 'Wax Kit');
    $waxBlock = createComponent('RAW-001', 'Wax Block');
    $applicator = createComponent('RAW-002', 'Applicator');

    $bom = createBom($finished);
    addBomLine($bom, $waxBlock, 2.0);
    addBomLine($bom, $applicator, 1.0);

    $service = app(BomExplosionService::class);
    $result = $service->explode($finished->id);

    expect($result)->toHaveCount(2);

    $waxResult = collect($result)->firstWhere('sku', 'RAW-001');
    $appResult = collect($result)->firstWhere('sku', 'RAW-002');

    expect($waxResult['quantity'])->toBe(2.0)
        ->and($appResult['quantity'])->toBe(1.0);
});

// ── Phantom BOM (doorexplosie) ──────────────────────────────────────

it('traverses phantom BOMs to reach leaf components', function () {
    $finished = createComponent('FIN-002', 'Performance Kit');
    $phantomSub = createComponent('PH-001', 'Wax Assembly');
    $rawA = createComponent('RAW-003', 'Raw Wax');
    $rawB = createComponent('RAW-004', 'Mold');

    // Finished → Phantom sub-assembly
    $finBom = createBom($finished);
    addBomLine($finBom, $phantomSub, 1.0);

    // Phantom sub → leaf components
    $phBom = createBom($phantomSub, 'phantom');
    addBomLine($phBom, $rawA, 3.0);
    addBomLine($phBom, $rawB, 1.0);

    $service = app(BomExplosionService::class);
    $result = $service->explode($finished->id);

    // Phantom is transparent — should get raw materials directly
    expect($result)->toHaveCount(2);

    $rawAResult = collect($result)->firstWhere('sku', 'RAW-003');
    expect($rawAResult['quantity'])->toBe(3.0);
});

// ── 3-level deep nesting ────────────────────────────────────────────

it('handles 3-level deep BOM nesting', function () {
    $finished = createComponent('L3-FIN', 'Finished Product');
    $sub1 = createComponent('L3-SUB1', 'Sub-assembly 1');
    $sub2 = createComponent('L3-SUB2', 'Sub-assembly 2');
    $leaf = createComponent('L3-RAW', 'Raw Material');

    // Level 1: Finished → Phantom sub1
    $finBom = createBom($finished);
    addBomLine($finBom, $sub1, 1.0);

    // Level 2: Sub1 (phantom) → Sub2
    $sub1Bom = createBom($sub1, 'phantom');
    addBomLine($sub1Bom, $sub2, 2.0);

    // Level 3: Sub2 (phantom) → Leaf
    $sub2Bom = createBom($sub2, 'phantom');
    addBomLine($sub2Bom, $leaf, 5.0);

    $service = app(BomExplosionService::class);
    $result = $service->explode($finished->id);

    expect($result)->toHaveCount(1);
    expect($result[0]['sku'])->toBe('L3-RAW')
        ->and($result[0]['quantity'])->toBe(10.0); // 1 × 2 × 5
});

// ── Shared components ───────────────────────────────────────────────

it('aggregates shared components across multiple BOM lines', function () {
    $finished = createComponent('SH-FIN', 'Kit with shared parts');
    $subA = createComponent('SH-SUBA', 'Sub A');
    $subB = createComponent('SH-SUBB', 'Sub B');
    $sharedRaw = createComponent('SH-RAW', 'Shared Raw Material');
    $uniqueRaw = createComponent('SH-UNQ', 'Unique Part');

    // Finished uses both sub-assemblies
    $finBom = createBom($finished);
    addBomLine($finBom, $subA, 1.0);
    addBomLine($finBom, $subB, 1.0);

    // Both sub-assemblies use the same raw material
    $subABom = createBom($subA, 'phantom');
    addBomLine($subABom, $sharedRaw, 3.0);

    $subBBom = createBom($subB, 'phantom');
    addBomLine($subBBom, $sharedRaw, 2.0);
    addBomLine($subBBom, $uniqueRaw, 1.0);

    $service = app(BomExplosionService::class);
    $result = $service->componentDemand([$finished->id => 10]);

    $shared = collect($result)->firstWhere('sku', 'SH-RAW');
    $unique = collect($result)->firstWhere('sku', 'SH-UNQ');

    // 10 kits × (3 + 2) = 50 shared raw, 10 × 1 = 10 unique
    expect($shared['total_quantity'])->toBe(50.0)
        ->and($unique['total_quantity'])->toBe(10.0);
});

// ── Circular BOM detection ──────────────────────────────────────────

it('handles circular BOM references gracefully', function () {
    $productA = createComponent('CIR-A', 'Circular A');
    $productB = createComponent('CIR-B', 'Circular B');

    // A → B → A (circular)
    $bomA = createBom($productA, 'phantom');
    addBomLine($bomA, $productB, 1.0);

    $bomB = createBom($productB, 'phantom');
    addBomLine($bomB, $productA, 1.0);

    $service = app(BomExplosionService::class);
    $result = $service->explode($productA->id);

    // Should not infinite loop — returns empty or partial result
    expect($result)->toBeArray();
});

// ── Quantity scaling ────────────────────────────────────────────────

it('scales quantities correctly with parent quantity multiplier', function () {
    $finished = createComponent('QS-FIN', 'Scaled Product');
    $raw = createComponent('QS-RAW', 'Raw Material');

    $bom = createBom($finished);
    addBomLine($bom, $raw, 4.0);

    $service = app(BomExplosionService::class);
    $result = $service->explode($finished->id, 5.0);

    expect($result)->toHaveCount(1)
        ->and($result[0]['quantity'])->toBe(20.0); // 5 × 4
});

// ── Product qty scaling (BOM yields multiple) ───────────────────────

it('scales correctly when BOM product_qty is greater than 1', function () {
    $finished = createComponent('PQ-FIN', 'Batch Product');
    $raw = createComponent('PQ-RAW', 'Raw Material');

    // BOM produces 10 units, needs 30 raw material
    $bom = createBom($finished, 'normal', productQty: 10.0);
    addBomLine($bom, $raw, 30.0);

    $service = app(BomExplosionService::class);
    $result = $service->explode($finished->id, 5.0);

    // 5 units needed, BOM yields 10, so (30/10) × 5 = 15
    expect($result)->toHaveCount(1)
        ->and($result[0]['quantity'])->toBe(15.0);
});

// ── Normal intermediate stops at intermediate ───────────────────────

it('stops explosion at normal BOM intermediates', function () {
    $finished = createComponent('INT-FIN', 'Top Product');
    $intermediate = createComponent('INT-MID', 'Intermediate');
    $rawUnder = createComponent('INT-RAW', 'Deep Raw');

    // Finished → Intermediate (normal BOM — should stop here)
    $finBom = createBom($finished);
    addBomLine($finBom, $intermediate, 2.0);

    // Intermediate has its own BOM but explosion should NOT traverse it
    $intBom = createBom($intermediate, 'normal');
    addBomLine($intBom, $rawUnder, 5.0);

    $service = app(BomExplosionService::class);
    $result = $service->explode($finished->id);

    // Should stop at intermediate, not reach rawUnder
    expect($result)->toHaveCount(1)
        ->and($result[0]['sku'])->toBe('INT-MID')
        ->and($result[0]['quantity'])->toBe(2.0);
});

// ── Effective lead time ─────────────────────────────────────────────

it('calculates effective lead time through BOM chain', function () {
    $finished = createComponent('LT-FIN', 'Lead Time Product', ProductCategory::WaxTablet);
    $rawFast = createComponent('LT-FAST', 'Fast Raw', ProductCategory::WaxTablet);
    $rawSlow = createComponent('LT-SLOW', 'Slow Raw', ProductCategory::Chain);

    SupplyProfile::create([
        'product_category' => ProductCategory::WaxTablet->value,
        'procurement_lead_time_days' => 14,
        'moq' => 100,
        'buffer_days' => 7,
    ]);
    SupplyProfile::create([
        'product_category' => ProductCategory::Chain->value,
        'procurement_lead_time_days' => 45,
        'moq' => 50,
        'buffer_days' => 7,
    ]);

    $bom = createBom($finished, 'normal', assemblyDays: 5);
    addBomLine($bom, $rawFast, 1.0);
    addBomLine($bom, $rawSlow, 1.0);

    $service = app(BomExplosionService::class);
    $lt = $service->effectiveLeadTime($finished->id);

    // Max(14, 45) + 5 assembly = 50
    expect($lt)->toBe(50.0);
});

// ── Intermediate products discovery ─────────────────────────────────

it('discovers intermediate products in BOM tree', function () {
    $finished = createComponent('IP-FIN', 'Final Assembly');
    $intermediate = createComponent('IP-MID', 'Sub-assembly');
    $raw = createComponent('IP-RAW', 'Raw Part');

    $finBom = createBom($finished, 'normal', assemblyDays: 3);
    addBomLine($finBom, $intermediate, 2.0);

    $intBom = createBom($intermediate, 'normal', assemblyDays: 1);
    addBomLine($intBom, $raw, 4.0);

    $service = app(BomExplosionService::class);
    $intermediates = $service->intermediateProducts($finished->id);

    expect($intermediates)->toHaveCount(1)
        ->and($intermediates[0]['sku'])->toBe('IP-MID')
        ->and($intermediates[0]['quantity'])->toBe(2.0)
        ->and($intermediates[0]['assembly_days'])->toBe(1.0);
});
