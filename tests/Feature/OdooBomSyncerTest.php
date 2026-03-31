<?php

use App\Models\Product;
use App\Models\ProductBom;
use App\Models\ProductBomLine;
use App\Services\Sync\OdooBomSyncer;

beforeEach(function () {
    config([
        'odoo.url' => 'https://test.odoo.com',
        'odoo.database' => 'test_db',
        'odoo.username' => 'admin@test.com',
        'odoo.api_key' => 'test_api_key',
    ]);

    $this->product = Product::factory()->create([
        'sku' => 'CW-WAX-01',
        'name' => 'Chain Wax',
        'odoo_product_id' => 101,
    ]);

    $this->componentProduct = Product::factory()->create([
        'sku' => 'CW-COMP-01',
        'name' => 'Base Wax',
        'odoo_product_id' => 201,
    ]);
});

it('syncs BOMs and lines from Odoo', function () {
    Http::fake([
        'test.odoo.com/jsonrpc' => Http::sequence()
            // authenticate
            ->push(['jsonrpc' => '2.0', 'id' => 1, 'result' => 42])
            // fetch BOMs
            ->push(['jsonrpc' => '2.0', 'id' => 2, 'result' => [
                [
                    'id' => 10,
                    'product_tmpl_id' => [50, 'Chain Wax Template'],
                    'product_id' => [101, 'Chain Wax'],
                    'product_qty' => 1.0,
                    'type' => 'normal',
                ],
            ]])
            // fetch BOM lines
            ->push(['jsonrpc' => '2.0', 'id' => 3, 'result' => [
                [
                    'id' => 100,
                    'bom_id' => [10, 'BOM/001'],
                    'product_id' => [201, 'Base Wax'],
                    'product_qty' => 2.5,
                ],
                [
                    'id' => 101,
                    'bom_id' => [10, 'BOM/001'],
                    'product_id' => [999, 'Unknown Component'],
                    'product_qty' => 1.0,
                ],
            ]])
            // fetch MOs — empty
            ->push(['jsonrpc' => '2.0', 'id' => 4, 'result' => []])
            // fetch product.product for template map
            ->push(['jsonrpc' => '2.0', 'id' => 5, 'result' => [
                ['id' => 101, 'product_tmpl_id' => [50, 'Chain Wax Template']],
                ['id' => 201, 'product_tmpl_id' => [60, 'Base Wax Template']],
            ]]),
    ]);

    $syncer = app(OdooBomSyncer::class);
    $result = $syncer->sync();

    expect($result['boms'])->toBe(1)
        ->and($result['lines'])->toBe(1); // only 1 line, unknown component skipped

    $bom = ProductBom::query()->where('odoo_bom_id', 10)->first();
    expect($bom)->not->toBeNull()
        ->and($bom->product_id)->toBe($this->product->id)
        ->and($bom->bom_type)->toBe('normal')
        ->and((float) $bom->product_qty)->toBe(1.0)
        ->and((float) $bom->assembly_lead_time_days)->toBe(0.0);

    $lines = ProductBomLine::query()->where('bom_id', $bom->id)->get();
    expect($lines)->toHaveCount(1)
        ->and($lines->first()->component_product_id)->toBe($this->componentProduct->id)
        ->and((float) $lines->first()->quantity)->toBe(2.5);
});

it('handles Odoo API failure gracefully', function () {
    Http::fake([
        'test.odoo.com/jsonrpc' => Http::sequence()
            // authenticate
            ->push(['jsonrpc' => '2.0', 'id' => 1, 'result' => 42])
            // fetch BOMs fails
            ->push(['jsonrpc' => '2.0', 'id' => 2, 'error' => [
                'message' => 'Access Denied',
                'data' => ['message' => 'You are not allowed to access this model.'],
            ]]),
    ]);

    $syncer = app(OdooBomSyncer::class);
    $result = $syncer->sync();

    expect($result)->toBe(['boms' => 0, 'lines' => 0, 'skipped' => 0]);
});

it('runs the odoo:sync-boms command successfully', function () {
    Http::fake([
        'test.odoo.com/jsonrpc' => Http::sequence()
            ->push(['jsonrpc' => '2.0', 'id' => 1, 'result' => 42])
            ->push(['jsonrpc' => '2.0', 'id' => 2, 'result' => []])
            ->push(['jsonrpc' => '2.0', 'id' => 3, 'result' => []])
            ->push(['jsonrpc' => '2.0', 'id' => 4, 'result' => []])
            ->push(['jsonrpc' => '2.0', 'id' => 5, 'result' => []]),
    ]);

    $this->artisan('odoo:sync-boms')
        ->assertSuccessful()
        ->expectsOutputToContain('BOMs synced: 0');
});
