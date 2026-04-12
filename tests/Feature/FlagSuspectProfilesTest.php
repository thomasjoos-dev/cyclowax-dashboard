<?php

use App\Models\KlaviyoProfile;

it('flags disposable email domains', function () {
    KlaviyoProfile::factory()->create(['email' => 'bot@example.com']);
    KlaviyoProfile::factory()->create(['email' => 'spam@mailinator.com']);
    KlaviyoProfile::factory()->create(['email' => 'guest@whatever.com']);
    KlaviyoProfile::factory()->create(['email' => 'legit@gmail.com']);

    $this->artisan('profiles:flag-suspects')->assertSuccessful();

    expect(KlaviyoProfile::where('is_suspect', true)->count())->toBe(3)
        ->and(KlaviyoProfile::where('email', 'legit@gmail.com')->first()->is_suspect)->toBeFalse();
});

it('flags ghost checkouts', function () {
    KlaviyoProfile::factory()->create([
        'email' => 'ghost@test.com',
        'checkouts_started' => 5,
        'product_views' => 0,
    ]);
    KlaviyoProfile::factory()->create([
        'email' => 'real@test.com',
        'checkouts_started' => 3,
        'product_views' => 2,
    ]);

    $this->artisan('profiles:flag-suspects')->assertSuccessful();

    expect(KlaviyoProfile::where('email', 'ghost@test.com')->first()->is_suspect)->toBeTrue()
        ->and(KlaviyoProfile::where('email', 'ghost@test.com')->first()->suspect_reason)->toBe('ghost_checkout')
        ->and(KlaviyoProfile::where('email', 'real@test.com')->first()->is_suspect)->toBeFalse();
});

it('flags bot opens', function () {
    KlaviyoProfile::factory()->create([
        'email' => 'scanner@test.com',
        'emails_received' => 10,
        'emails_opened' => 60,
    ]);
    KlaviyoProfile::factory()->create([
        'email' => 'normal@test.com',
        'emails_received' => 10,
        'emails_opened' => 15,
    ]);

    $this->artisan('profiles:flag-suspects')->assertSuccessful();

    expect(KlaviyoProfile::where('email', 'scanner@test.com')->first()->is_suspect)->toBeTrue()
        ->and(KlaviyoProfile::where('email', 'scanner@test.com')->first()->suspect_reason)->toBe('bot_opens')
        ->and(KlaviyoProfile::where('email', 'normal@test.com')->first()->is_suspect)->toBeFalse();
});

it('is idempotent and resets flags on rerun', function () {
    $profile = KlaviyoProfile::factory()->create(['email' => 'bot@example.com']);

    $this->artisan('profiles:flag-suspects')->assertSuccessful();
    expect($profile->refresh()->is_suspect)->toBeTrue();

    // Stel dat het email ondertussen veranderd is (niet meer verdacht)
    $profile->update(['email' => 'legit@gmail.com']);

    $this->artisan('profiles:flag-suspects')->assertSuccessful();
    expect($profile->refresh()->is_suspect)->toBeFalse();
});

it('does not flag profiles below ghost checkout threshold', function () {
    KlaviyoProfile::factory()->create([
        'email' => 'edge@test.com',
        'checkouts_started' => 2,
        'product_views' => 0,
    ]);

    $this->artisan('profiles:flag-suspects')->assertSuccessful();

    expect(KlaviyoProfile::where('email', 'edge@test.com')->first()->is_suspect)->toBeFalse();
});
