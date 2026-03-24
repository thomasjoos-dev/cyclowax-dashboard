<?php

use App\Services\OdooClient;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'odoo.url' => 'https://test.odoo.com',
        'odoo.database' => 'test_db',
        'odoo.username' => 'admin@test.com',
        'odoo.api_key' => 'test_api_key',
    ]);
});

it('authenticates successfully', function () {
    Http::fake([
        'test.odoo.com/jsonrpc' => Http::sequence()
            ->push(['jsonrpc' => '2.0', 'id' => 1, 'result' => 42]),
    ]);

    $client = app(OdooClient::class);
    $uid = $client->authenticate();

    expect($uid)->toBe(42);
});

it('throws on failed authentication', function () {
    Http::fake([
        'test.odoo.com/jsonrpc' => Http::response([
            'jsonrpc' => '2.0', 'id' => 1, 'result' => false,
        ]),
    ]);

    $client = app(OdooClient::class);
    $client->authenticate();
})->throws(RuntimeException::class, 'Odoo authentication failed');

it('performs search_read successfully', function () {
    Http::fake([
        'test.odoo.com/jsonrpc' => Http::sequence()
            // authenticate
            ->push(['jsonrpc' => '2.0', 'id' => 1, 'result' => 42])
            // search_read
            ->push(['jsonrpc' => '2.0', 'id' => 2, 'result' => [
                ['id' => 1, 'default_code' => 'CYC-WAX-01', 'name' => 'Chain Wax', 'standard_price' => 5.50],
                ['id' => 2, 'default_code' => 'CYC-WAX-02', 'name' => 'Race Wax', 'standard_price' => 7.20],
            ]]),
    ]);

    $client = app(OdooClient::class);
    $products = $client->searchRead(
        'product.product',
        [['default_code', '!=', false]],
        ['default_code', 'name', 'standard_price'],
    );

    expect($products)->toHaveCount(2)
        ->and($products[0]['default_code'])->toBe('CYC-WAX-01')
        ->and($products[1]['standard_price'])->toBe(7.20);
});

it('handles RPC errors gracefully', function () {
    Http::fake([
        'test.odoo.com/jsonrpc' => Http::sequence()
            ->push(['jsonrpc' => '2.0', 'id' => 1, 'result' => 42])
            ->push(['jsonrpc' => '2.0', 'id' => 2, 'error' => [
                'message' => 'Access Denied',
                'data' => ['message' => 'You are not allowed to access this model.'],
            ]]),
    ]);

    $client = app(OdooClient::class);
    $client->searchRead('product.product');
})->throws(RuntimeException::class, 'You are not allowed to access this model');

it('caches uid after first authentication', function () {
    Http::fake([
        'test.odoo.com/jsonrpc' => Http::sequence()
            ->push(['jsonrpc' => '2.0', 'id' => 1, 'result' => 42])
            ->push(['jsonrpc' => '2.0', 'id' => 2, 'result' => []])
            ->push(['jsonrpc' => '2.0', 'id' => 3, 'result' => []]),
    ]);

    $client = app(OdooClient::class);
    $client->searchRead('product.product');
    $client->searchRead('product.template');

    // Only 3 HTTP calls total: 1 auth + 2 search_read (not 2 auth + 2 search_read)
    Http::assertSentCount(3);
});

it('throws when config is missing', function () {
    config(['odoo.url' => null]);
    app(OdooClient::class);
})->throws(RuntimeException::class, 'ODOO_URL is not configured');
