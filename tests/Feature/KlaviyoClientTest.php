<?php

use App\Services\KlaviyoClient;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'klaviyo.api_key' => 'pk_test_123',
        'klaviyo.revision' => '2024-10-15',
    ]);
});

it('sends correct authentication and revision headers', function () {
    Http::fake([
        'a.klaviyo.com/api/*' => Http::response(['data' => []]),
    ]);

    $client = app(KlaviyoClient::class);
    $client->get('profiles');

    Http::assertSent(function ($request) {
        return $request->hasHeader('Authorization', 'Klaviyo-API-Key pk_test_123')
            && $request->hasHeader('revision', '2024-10-15');
    });
});

it('performs a GET request successfully', function () {
    Http::fake([
        'a.klaviyo.com/api/profiles*' => Http::response([
            'data' => [
                ['id' => 'abc123', 'type' => 'profile', 'attributes' => ['email' => 'test@cyclowax.cc']],
            ],
            'links' => ['next' => null],
        ]),
    ]);

    $client = app(KlaviyoClient::class);
    $result = $client->get('profiles');

    expect($result['data'])->toHaveCount(1)
        ->and($result['data'][0]['attributes']['email'])->toBe('test@cyclowax.cc');
});

it('performs a POST request successfully', function () {
    Http::fake([
        'a.klaviyo.com/api/campaign-values-reports*' => Http::response([
            'data' => ['type' => 'campaign-values-report', 'attributes' => ['results' => []]],
        ]),
    ]);

    $client = app(KlaviyoClient::class);
    $result = $client->post('campaign-values-reports', ['data' => ['type' => 'campaign-values-report']]);

    expect($result['data']['type'])->toBe('campaign-values-report');
});

it('paginates through all results', function () {
    Http::fake([
        'a.klaviyo.com/api/profiles*' => Http::sequence()
            ->push([
                'data' => [
                    ['id' => '1', 'type' => 'profile'],
                    ['id' => '2', 'type' => 'profile'],
                ],
                'links' => ['next' => 'https://a.klaviyo.com/api/profiles?page[cursor]=abc'],
            ])
            ->push([
                'data' => [
                    ['id' => '3', 'type' => 'profile'],
                ],
                'links' => ['next' => null],
            ]),
    ]);

    $client = app(KlaviyoClient::class);
    $results = iterator_to_array($client->paginate('profiles'));

    expect($results)->toHaveCount(3)
        ->and($results[0]['id'])->toBe('1')
        ->and($results[2]['id'])->toBe('3');
});

it('retries on 429 rate limit', function () {
    Http::fake([
        'a.klaviyo.com/api/profiles*' => Http::sequence()
            ->push(['errors' => [['status' => 429]]], 429, ['Retry-After' => '1'])
            ->push(['data' => [['id' => '1']]], 200),
    ]);

    $client = app(KlaviyoClient::class);
    $result = $client->get('profiles');

    expect($result['data'])->toHaveCount(1);
    Http::assertSentCount(2);
});

it('throws on non-429 error', function () {
    Http::fake([
        'a.klaviyo.com/api/profiles*' => Http::response(['errors' => [['detail' => 'Forbidden']]], 403),
    ]);

    $client = app(KlaviyoClient::class);
    $client->get('profiles');
})->throws(RuntimeException::class, 'Klaviyo API request failed with status 403');

it('throws when api key is missing', function () {
    config(['klaviyo.api_key' => null]);
    app(KlaviyoClient::class);
})->throws(RuntimeException::class, 'Klaviyo API key is not configured');
