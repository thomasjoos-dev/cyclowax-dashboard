<?php

use App\Models\ShopifyCustomer;

it('detects gender for customers with a first name', function () {
    ShopifyCustomer::factory()->create(['first_name' => 'Thomas', 'country_code' => 'NL', 'gender' => null]);
    ShopifyCustomer::factory()->create(['first_name' => 'Anna', 'country_code' => 'NL', 'gender' => null]);

    $this->artisan('app:detect-customer-gender')
        ->assertSuccessful();

    expect(ShopifyCustomer::where('first_name', 'Thomas')->first()->gender)->toBe('male')
        ->and(ShopifyCustomer::where('first_name', 'Anna')->first()->gender)->toBe('female');
});

it('skips customers that already have a gender', function () {
    ShopifyCustomer::factory()->create([
        'first_name' => 'Thomas',
        'gender' => 'female',
        'gender_probability' => 1.0,
    ]);

    $this->artisan('app:detect-customer-gender')
        ->assertSuccessful();

    expect(ShopifyCustomer::first()->gender)->toBe('female');
});

it('re-detects all customers with --force flag', function () {
    ShopifyCustomer::factory()->create([
        'first_name' => 'Thomas',
        'country_code' => 'NL',
        'gender' => 'female',
        'gender_probability' => 1.0,
    ]);

    $this->artisan('app:detect-customer-gender --force')
        ->assertSuccessful();

    expect(ShopifyCustomer::first()->gender)->toBe('male');
});

it('sets unknown for unrecognized names', function () {
    ShopifyCustomer::factory()->create(['first_name' => 'Xyzzyplugh', 'gender' => null]);

    $this->artisan('app:detect-customer-gender')
        ->assertSuccessful();

    $customer = ShopifyCustomer::first();

    expect($customer->gender)->toBe('unknown')
        ->and($customer->gender_probability)->toBe(0.0);
});

it('skips customers without a first name', function () {
    ShopifyCustomer::factory()->create(['first_name' => null, 'gender' => null]);
    ShopifyCustomer::factory()->create(['first_name' => '', 'gender' => null]);

    $this->artisan('app:detect-customer-gender')
        ->assertSuccessful();

    expect(ShopifyCustomer::whereNotNull('gender')->count())->toBe(0);
});
