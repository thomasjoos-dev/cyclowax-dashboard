<?php

use App\Models\User;

it('loads the home page without auth', function () {
    $this->get('/')->assertOk();
});

it('loads authenticated pages', function (string $uri) {
    $user = User::factory()->create([
        'email_verified_at' => now(),
    ]);

    $this->actingAs($user)
        ->get($uri)
        ->assertOk();
})->with([
    'dashboard' => '/dashboard',
    'docs/api' => '/docs/api',
    'docs/architecture' => '/docs/architecture',
    'docs/styleguide' => '/docs/styleguide',
    'settings/profile' => '/settings/profile',
    'settings/appearance' => '/settings/appearance',
]);

it('redirects settings/security to password confirmation', function () {
    $user = User::factory()->create([
        'email_verified_at' => now(),
    ]);

    $this->actingAs($user)
        ->get('/settings/security')
        ->assertRedirect();
});

it('returns 404 for non-existent pages', function () {
    $user = User::factory()->create([
        'email_verified_at' => now(),
    ]);

    $this->actingAs($user)
        ->get('/this-page-does-not-exist')
        ->assertNotFound();
});
