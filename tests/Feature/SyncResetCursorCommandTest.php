<?php

use App\Models\SyncState;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('resets a stuck cursor for a specific step', function () {
    SyncState::updateOrCreate(
        ['step' => 'klaviyo:sync-campaigns'],
        ['status' => 'idle', 'cursor' => ['enrichment_pending' => true], 'records_synced' => 0],
    );

    $this->artisan('sync:reset-cursor', ['step' => 'klaviyo:sync-campaigns'])
        ->assertSuccessful();

    $state = SyncState::where('step', 'klaviyo:sync-campaigns')->first();
    expect($state->status)->toBe('idle')
        ->and($state->cursor)->toBeNull();
});

it('resets all cursors with --all flag', function () {
    SyncState::updateOrCreate(
        ['step' => 'klaviyo:sync-profiles'],
        ['status' => 'idle', 'cursor' => ['next_url' => 'https://example.com']],
    );
    SyncState::updateOrCreate(
        ['step' => 'klaviyo:sync-campaigns'],
        ['status' => 'running', 'cursor' => ['enrichment_pending' => true]],
    );

    $this->artisan('sync:reset-cursor', ['--all' => true])
        ->assertSuccessful();

    expect(SyncState::whereNotNull('cursor')->count())->toBe(0)
        ->and(SyncState::where('status', 'running')->count())->toBe(0);
});

it('skips steps without cursors or running state', function () {
    SyncState::updateOrCreate(
        ['step' => 'klaviyo:sync-profiles'],
        ['status' => 'completed', 'cursor' => null],
    );

    $this->artisan('sync:reset-cursor', ['--all' => true])
        ->assertSuccessful()
        ->expectsOutputToContain('skipping');
});

it('fails without step argument or --all flag', function () {
    $this->artisan('sync:reset-cursor')
        ->assertFailed();
});

it('warns when no sync states found for step', function () {
    $this->artisan('sync:reset-cursor', ['step' => 'nonexistent:step'])
        ->assertSuccessful()
        ->expectsOutputToContain('No sync states found');
});
