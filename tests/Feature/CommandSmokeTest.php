<?php

use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
});

it('runs health:check without errors', function () {
    $this->artisan('health:check')
        ->assertSuccessful();
});

it('runs sync:status without errors', function () {
    $this->artisan('sync:status')
        ->assertSuccessful();
});

it('runs orders:compute-margins without errors on empty data', function () {
    $this->artisan('orders:compute-margins')
        ->assertSuccessful();
});

it('runs customers:calculate-rfm without errors on empty data', function () {
    $this->artisan('customers:calculate-rfm')
        ->assertSuccessful();
});

it('runs profiles:score-followers without errors on empty data', function () {
    $this->artisan('profiles:score-followers')
        ->assertSuccessful();
});

it('runs products:classify-portfolio without errors on empty data', function () {
    $this->artisan('products:classify-portfolio')
        ->assertSuccessful();
});
