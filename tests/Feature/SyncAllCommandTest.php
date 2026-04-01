<?php

use App\Models\SyncState;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('fails when shopify credentials are missing', function () {
    config(['shopify.store' => null, 'shopify.access_token' => null]);
    config(['odoo.url' => 'https://odoo.test', 'odoo.api_key' => 'key']);
    config(['klaviyo.api_key' => 'pk_test']);

    $this->artisan('sync:all')
        ->assertFailed()
        ->expectsOutputToContain('Missing credentials');
});

it('fails when odoo credentials are missing', function () {
    config(['shopify.store' => 'test.myshopify.com', 'shopify.access_token' => 'token']);
    config(['odoo.url' => null, 'odoo.api_key' => null]);
    config(['klaviyo.api_key' => 'pk_test']);

    $this->artisan('sync:all')
        ->assertFailed()
        ->expectsOutputToContain('Missing credentials');
});

it('fails when klaviyo credentials are missing', function () {
    config(['shopify.store' => 'test.myshopify.com', 'shopify.access_token' => 'token']);
    config(['odoo.url' => 'https://odoo.test', 'odoo.api_key' => 'key']);
    config(['klaviyo.api_key' => null]);

    $this->artisan('sync:all')
        ->assertFailed()
        ->expectsOutputToContain('Missing credentials');
});

it('resets stale sync states at pipeline start', function () {
    config(['shopify.store' => 'test.myshopify.com', 'shopify.access_token' => 'token']);
    config(['odoo.url' => 'https://odoo.test', 'odoo.api_key' => 'key']);
    config(['klaviyo.api_key' => 'pk_test']);

    SyncState::updateOrCreate(
        ['step' => 'klaviyo:sync-profiles'],
        ['status' => 'running', 'started_at' => now()->subMinutes(10)],
    );

    // Pipeline will fail at first step (no real API), but stale state should be reset first
    $this->artisan('sync:all');

    $state = SyncState::where('step', 'klaviyo:sync-profiles')->first();
    expect($state->status)->not->toBe('running');
});
