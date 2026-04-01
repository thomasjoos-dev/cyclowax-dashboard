<?php

use App\Models\OpenPurchaseOrder;
use App\Models\Product;
use App\Models\ProductStockSnapshot;
use App\Services\Forecast\Supply\ComponentNettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createProductWithStock(string $sku, float $stock, float $openPo = 0): Product
{
    $product = Product::factory()->create(['sku' => $sku]);

    ProductStockSnapshot::factory()->create([
        'product_id' => $product->id,
        'qty_free' => $stock,
        'qty_on_hand' => $stock,
        'qty_forecasted' => $stock,
        'recorded_at' => now(),
    ]);

    if ($openPo > 0) {
        OpenPurchaseOrder::create([
            'odoo_po_line_id' => random_int(10000, 99999),
            'po_reference' => 'PO-TEST-'.random_int(100, 999),
            'product_id' => $product->id,
            'odoo_product_id' => random_int(1000, 9999),
            'product_name' => $product->sku,
            'supplier_name' => 'Test Supplier',
            'quantity_ordered' => $openPo,
            'quantity_received' => 0,
            'quantity_open' => $openPo,
            'unit_price' => 1.00,
            'date_order' => now()->subDays(10)->format('Y-m-d'),
            'date_planned' => now()->addDays(30)->format('Y-m-d'),
            'state' => 'purchase',
        ]);
    }

    return $product;
}

function buildDemand(Product $product, float $quantity, ?int $lt = 45): array
{
    return [
        'product_id' => $product->id,
        'sku' => $product->sku,
        'name' => $product->name ?? $product->sku,
        'total_quantity' => $quantity,
        'procurement_lt' => $lt,
    ];
}

test('net deducts stock and open POs from gross need', function () {
    $product = createProductWithStock('COMP-A', stock: 100, openPo: 50);

    $service = app(ComponentNettingService::class);
    $result = $service->net([buildDemand($product, 300)]);

    expect($result)->toHaveCount(1);
    expect($result[0]['gross_need'])->toBe(300.0);
    expect($result[0]['stock_available'])->toBe(100.0);
    expect($result[0]['open_po_qty'])->toBe(50.0);
    expect($result[0]['net_need'])->toBe(150.0);
});

test('net need is zero when stock and open POs cover demand', function () {
    $product = createProductWithStock('COMP-B', stock: 500, openPo: 200);

    $service = app(ComponentNettingService::class);
    $result = $service->net([buildDemand($product, 600)]);

    expect((float) $result[0]['net_need'])->toBe(0.0);
});

test('net need is never negative', function () {
    $product = createProductWithStock('COMP-C', stock: 1000);

    $service = app(ComponentNettingService::class);
    $result = $service->net([buildDemand($product, 200)]);

    expect((float) $result[0]['net_need'])->toBe(0.0);
});

test('shared component across categories is netted only once', function () {
    // This is the core fix: a shared component used by two categories
    // should have stock deducted only once, not per category
    $sharedComponent = createProductWithStock('SHARED-WAX', stock: 500);

    // Category A needs 400, Category B needs 300 → total = 700
    $categoryADemand = [buildDemand($sharedComponent, 400)];
    $categoryBDemand = [buildDemand($sharedComponent, 300)];

    // Aggregate demand across categories (like PurchaseCalendarService now does)
    $aggregated = [];
    foreach ([$categoryADemand, $categoryBDemand] as $catDemand) {
        foreach ($catDemand as $comp) {
            $pid = $comp['product_id'];
            if (! isset($aggregated[$pid])) {
                $aggregated[$pid] = $comp;
            } else {
                $aggregated[$pid]['total_quantity'] += $comp['total_quantity'];
            }
        }
    }

    $service = app(ComponentNettingService::class);
    $result = $service->net(array_values($aggregated));

    // Total gross = 700, stock = 500, net = 200 (not 400+300-500-500 = 200+0 = 200 by coincidence,
    // but the important thing is stock is read once and applied to the total)
    expect($result)->toHaveCount(1);
    expect($result[0]['gross_need'])->toBe(700.0);
    expect($result[0]['stock_available'])->toBe(500.0);
    expect($result[0]['net_need'])->toBe(200.0);
});

test('instance cache is used for repeated calls within same service instance', function () {
    $product = createProductWithStock('COMP-CACHE', stock: 100);

    $service = app(ComponentNettingService::class);

    // First call populates cache
    $result1 = $service->net([buildDemand($product, 50)]);

    // Modify stock directly in DB (simulating external change)
    ProductStockSnapshot::where('product_id', $product->id)->update(['qty_free' => 999]);

    // Second call should use cached stock (still 100)
    $result2 = $service->net([buildDemand($product, 50)]);
    expect($result2[0]['stock_available'])->toBe(100.0);

    // After clearCache, should pick up new value
    $service->clearCache();
    $result3 = $service->net([buildDemand($product, 50)]);
    expect($result3[0]['stock_available'])->toBe(999.0);
});

test('new service instance starts with fresh cache', function () {
    $product = createProductWithStock('COMP-FRESH', stock: 200);

    $service1 = new ComponentNettingService;
    $result1 = $service1->net([buildDemand($product, 100)]);
    expect($result1[0]['stock_available'])->toBe(200.0);

    // Update stock
    ProductStockSnapshot::where('product_id', $product->id)->update(['qty_free' => 50]);

    // New instance should see updated stock (no static cache issue)
    $service2 = new ComponentNettingService;
    $result2 = $service2->net([buildDemand($product, 100)]);
    expect($result2[0]['stock_available'])->toBe(50.0);
});

test('multiple components are netted independently', function () {
    $compA = createProductWithStock('MULTI-A', stock: 100);
    $compB = createProductWithStock('MULTI-B', stock: 0, openPo: 200);

    $service = app(ComponentNettingService::class);
    $result = $service->net([
        buildDemand($compA, 150),
        buildDemand($compB, 300),
    ]);

    expect($result)->toHaveCount(2);
    expect($result[0]['net_need'])->toBe(50.0);  // 150 - 100
    expect($result[1]['net_need'])->toBe(100.0); // 300 - 0 - 200
});

test('stockFreshness returns stale when no snapshots exist', function () {
    $service = app(ComponentNettingService::class);
    $result = $service->stockFreshness();

    expect($result['latest_at'])->toBeNull();
    expect($result['is_stale'])->toBeTrue();
});

test('stockFreshness returns fresh for recent snapshots', function () {
    $product = createProductWithStock('FRESH-TEST', stock: 100);

    $service = app(ComponentNettingService::class);
    $result = $service->stockFreshness();

    expect($result['is_stale'])->toBeFalse();
    expect($result['age_hours'])->toBeLessThan(1);
});

// --- Intermediate netting tests ---

function buildIntermediate(Product $product, float $quantity, float $assemblyDays = 2.0): array
{
    return [
        'product_id' => $product->id,
        'sku' => $product->sku,
        'name' => $product->name ?? $product->sku,
        'quantity' => $quantity,
        'assembly_days' => $assemblyDays,
        'bom_type' => 'normal',
    ];
}

test('intermediate netting deducts stock from production quantity', function () {
    $intermediate = createProductWithStock('INTER-A', stock: 80);

    $service = app(ComponentNettingService::class);
    $result = $service->netIntermediateDemand([
        buildIntermediate($intermediate, 200),
    ]);

    expect($result)->toHaveCount(1);
    expect($result[0]['gross_quantity'])->toBe(200.0);
    expect($result[0]['stock_available'])->toBe(80.0);
    expect($result[0]['net_quantity'])->toBe(120.0);
    expect($result[0]['assembly_days'])->toBe(2.0);
});

test('intermediate netting returns zero when stock covers demand', function () {
    $intermediate = createProductWithStock('INTER-B', stock: 500);

    $service = app(ComponentNettingService::class);
    $result = $service->netIntermediateDemand([
        buildIntermediate($intermediate, 200),
    ]);

    expect((float) $result[0]['net_quantity'])->toBe(0.0);
    expect($result[0]['stock_available'])->toBe(500.0);
});

test('intermediate netting handles multiple intermediates independently', function () {
    $interA = createProductWithStock('INTER-M1', stock: 50);
    $interB = createProductWithStock('INTER-M2', stock: 300);

    $service = app(ComponentNettingService::class);
    $result = $service->netIntermediateDemand([
        buildIntermediate($interA, 100),
        buildIntermediate($interB, 200),
    ]);

    expect($result)->toHaveCount(2);
    expect($result[0]['net_quantity'])->toBe(50.0);   // 100 - 50
    expect((float) $result[1]['net_quantity'])->toBe(0.0);    // 200 - 300 → 0
});

test('intermediate netting shares stock cache with component netting', function () {
    // Both net() and netIntermediateDemand() should use the same stock cache
    $product = createProductWithStock('SHARED-CACHE', stock: 150);

    $service = app(ComponentNettingService::class);

    // Call net() first to populate cache
    $service->net([buildDemand($product, 100)]);

    // Update stock in DB
    ProductStockSnapshot::where('product_id', $product->id)->update(['qty_free' => 999]);

    // netIntermediateDemand should still use cached value (150)
    $result = $service->netIntermediateDemand([
        buildIntermediate($product, 200),
    ]);

    expect($result[0]['stock_available'])->toBe(150.0);
});

// --- Rolling netting tests ---

function createOpenPo(Product $product, float $qty, string $datePlanned): void
{
    OpenPurchaseOrder::create([
        'odoo_po_line_id' => random_int(10000, 99999),
        'po_reference' => 'PO-TEST-'.random_int(100, 999),
        'product_id' => $product->id,
        'odoo_product_id' => random_int(1000, 9999),
        'product_name' => $product->sku,
        'supplier_name' => 'Test Supplier',
        'quantity_ordered' => $qty,
        'quantity_received' => 0,
        'quantity_open' => $qty,
        'unit_price' => 1.00,
        'date_order' => now()->subDays(10)->format('Y-m-d'),
        'date_planned' => $datePlanned,
        'state' => 'purchase',
    ]);
}

function buildMeta(Product $product, ?int $lt = 45): array
{
    return [
        'sku' => $product->sku,
        'name' => $product->name ?? $product->sku,
        'procurement_lt' => $lt,
    ];
}

test('rolling net detects shortfall in correct month', function () {
    // Stock = 200, demand = 100/month → shortfall in month 3
    $product = createProductWithStock('ROLL-A', stock: 200);

    $service = app(ComponentNettingService::class);
    $result = $service->rollingNet(
        [$product->id => [1 => 100, 2 => 100, 3 => 100, 4 => 100]],
        [$product->id => buildMeta($product)],
        2026,
    );

    expect($result)->toHaveCount(1);
    $comp = $result[0];

    expect($comp['stock_available'])->toBe(200.0);
    expect($comp['gross_need'])->toBe(400);
    expect($comp['first_shortfall_month'])->toBe(3);
    expect($comp['net_need'])->toEqual(200); // shortfall in month 3 (100) + month 4 (100)

    // Month-by-month verification
    expect($comp['monthly'][1]['stock_end'])->toEqual(100);
    expect($comp['monthly'][1]['shortfall'])->toEqual(0);
    expect($comp['monthly'][2]['stock_end'])->toEqual(0);
    expect($comp['monthly'][2]['shortfall'])->toEqual(0);
    expect($comp['monthly'][3]['stock_end'])->toEqual(0);
    expect($comp['monthly'][3]['shortfall'])->toEqual(100);
    expect($comp['monthly'][4]['stock_end'])->toEqual(0);
    expect($comp['monthly'][4]['shortfall'])->toEqual(100);
});

test('rolling net accounts for PO arrivals in correct month', function () {
    // Stock = 100, demand = 80/month, PO of 200 arriving in month 3
    $product = createProductWithStock('ROLL-PO', stock: 100);
    createOpenPo($product, 200, '2026-03-15');

    $service = app(ComponentNettingService::class);
    $result = $service->rollingNet(
        [$product->id => [1 => 80, 2 => 80, 3 => 80, 4 => 80]],
        [$product->id => buildMeta($product)],
        2026,
    );

    $comp = $result[0];

    expect($comp['open_po_total'])->toBe(200.0);

    // Month 1: 100 - 80 = 20
    expect($comp['monthly'][1]['stock_end'])->toEqual(20);
    // Month 2: 20 - 80 = -60, shortfall = 60
    expect($comp['monthly'][2]['shortfall'])->toEqual(60);
    expect($comp['first_shortfall_month'])->toBe(2);
    // Month 3: 0 + 200 (PO) - 80 = 120
    expect($comp['monthly'][3]['po_arriving'])->toEqual(200);
    expect($comp['monthly'][3]['stock_end'])->toEqual(120);
    expect($comp['monthly'][3]['shortfall'])->toEqual(0);
    // Month 4: 120 - 80 = 40
    expect($comp['monthly'][4]['stock_end'])->toEqual(40);
});

test('rolling net shows no shortfall when stock covers all months', function () {
    $product = createProductWithStock('ROLL-OK', stock: 1000);

    $service = app(ComponentNettingService::class);
    $result = $service->rollingNet(
        [$product->id => [1 => 50, 2 => 50, 3 => 50]],
        [$product->id => buildMeta($product)],
        2026,
    );

    $comp = $result[0];

    expect($comp['net_need'])->toEqual(0);
    expect($comp['first_shortfall_month'])->toBeNull();
    expect($comp['monthly'][3]['stock_end'])->toEqual(850);
});

test('rolling net aggregates shared component demand across categories', function () {
    // Shared component: category A needs 60/month in M1-M6, category B needs 40/month in M1-M6
    // Total = 100/month, stock = 300 → shortfall starts month 4
    $shared = createProductWithStock('ROLL-SHARED', stock: 300);

    $monthlyDemand = [];
    for ($m = 1; $m <= 6; $m++) {
        $monthlyDemand[$shared->id][$m] = 100; // pre-aggregated (60 + 40)
    }

    $service = app(ComponentNettingService::class);
    $result = $service->rollingNet(
        $monthlyDemand,
        [$shared->id => buildMeta($shared)],
        2026,
    );

    $comp = $result[0];

    expect($comp['first_shortfall_month'])->toBe(4);
    expect($comp['net_need'])->toBe(300.0); // months 4,5,6: 100 each
    expect($comp['monthly'][3]['stock_end'])->toBe(0.0);
});

test('rolling net ignores POs from different year', function () {
    $product = createProductWithStock('ROLL-YEAR', stock: 50);
    createOpenPo($product, 500, '2027-02-15'); // wrong year

    $service = app(ComponentNettingService::class);
    $result = $service->rollingNet(
        [$product->id => [1 => 100]],
        [$product->id => buildMeta($product)],
        2026,
    );

    $comp = $result[0];

    expect($comp['open_po_total'])->toEqual(0);
    expect($comp['monthly'][1]['po_arriving'])->toEqual(0);
    expect($comp['monthly'][1]['shortfall'])->toEqual(50);
});

test('buildNettingNote formats correctly', function () {
    $service = app(ComponentNettingService::class);

    $note = $service->buildNettingNote([
        'gross_need' => 500,
        'stock_available' => 200,
        'open_po_qty' => 100,
        'net_need' => 200,
    ]);

    expect($note)->toBe('Need 500, stock 200, open PO 100, → order 200');
});
