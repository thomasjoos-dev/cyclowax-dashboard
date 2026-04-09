<?php

use App\Models\User;

/*
|--------------------------------------------------------------------------
| Unauthenticated access — all API v1 endpoints require auth:sanctum
|--------------------------------------------------------------------------
*/

test('guests receive 401 on api dashboard', function () {
    $this->getJson('/api/v1/dashboard')
        ->assertUnauthorized();
});

test('guests receive 401 on api orders index', function () {
    $this->getJson('/api/v1/orders')
        ->assertUnauthorized();
});

test('guests receive 401 on api customers index', function () {
    $this->getJson('/api/v1/customers')
        ->assertUnauthorized();
});

test('guests receive 401 on api products index', function () {
    $this->getJson('/api/v1/products')
        ->assertUnauthorized();
});

/*
|--------------------------------------------------------------------------
| Authenticated access — endpoints work with valid session
|--------------------------------------------------------------------------
*/

test('authenticated users can access api dashboard', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/api/v1/dashboard')
        ->assertOk();
});

test('authenticated users can access api orders', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/api/v1/orders')
        ->assertOk();
});

test('authenticated users can access api customers', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/api/v1/customers')
        ->assertOk();
});

test('authenticated users can access api products', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/api/v1/products')
        ->assertOk();
});

/*
|--------------------------------------------------------------------------
| Sanctum token authentication
|--------------------------------------------------------------------------
*/

test('api accepts sanctum token authentication', function () {
    $user = User::factory()->create();
    $token = $user->createToken('test-token')->plainTextToken;

    $this->getJson('/api/v1/dashboard', [
        'Authorization' => "Bearer {$token}",
    ])->assertOk();
});

/*
|--------------------------------------------------------------------------
| FormRequest validation — per_page cap
|--------------------------------------------------------------------------
*/

test('orders per_page is capped at 100', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/api/v1/orders?per_page=500')
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation')
        ->assertJsonPath('error.fields.per_page.0', fn (string $v) => str_contains($v, 'per page'));
});

test('customers per_page is capped at 100', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/api/v1/customers?per_page=500')
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation')
        ->assertJsonPath('error.fields.per_page.0', fn (string $v) => str_contains($v, 'per page'));
});

test('products per_page is capped at 100', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/api/v1/products?per_page=500')
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation')
        ->assertJsonPath('error.fields.per_page.0', fn (string $v) => str_contains($v, 'per page'));
});

/*
|--------------------------------------------------------------------------
| FormRequest validation — enum values
|--------------------------------------------------------------------------
*/

test('orders rejects invalid financial_status', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/api/v1/orders?financial_status=HACKED')
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation')
        ->assertJsonPath('error.fields.financial_status.0', fn (string $v) => str_contains($v, 'financial status'));
});

test('products rejects invalid status', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/api/v1/products?status=invalid')
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation')
        ->assertJsonPath('error.fields.status.0', fn (string $v) => ! empty($v));
});

test('dashboard rejects invalid period', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/api/v1/dashboard?period=invalid')
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation')
        ->assertJsonPath('error.fields.period.0', fn (string $v) => ! empty($v));
});

/*
|--------------------------------------------------------------------------
| FormRequest validation — date formats
|--------------------------------------------------------------------------
*/

test('orders rejects invalid date format', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/api/v1/orders?from=not-a-date')
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation')
        ->assertJsonPath('error.fields.from.0', fn (string $v) => ! empty($v));
});

test('orders rejects to before from', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/api/v1/orders?from=2026-03-31&to=2026-01-01')
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation')
        ->assertJsonPath('error.fields.to.0', fn (string $v) => ! empty($v));
});

/*
|--------------------------------------------------------------------------
| Web routes — auth middleware
|--------------------------------------------------------------------------
*/

test('guests are redirected from docs pages', function () {
    $this->get('/docs/api')->assertRedirect(route('login'));
    $this->get('/docs/architecture')->assertRedirect(route('login'));
    $this->get('/docs/styleguide')->assertRedirect(route('login'));
});
