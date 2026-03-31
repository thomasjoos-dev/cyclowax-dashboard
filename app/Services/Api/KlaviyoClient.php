<?php

namespace App\Services\Api;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class KlaviyoClient
{
    protected const string BASE_URL = 'https://a.klaviyo.com/api';

    protected string $apiKey;

    protected string $revision;

    public function __construct()
    {
        $apiKey = config('klaviyo.api_key');

        if (! $apiKey) {
            throw new RuntimeException('Klaviyo API key is not configured. Check your .env file.');
        }

        $this->apiKey = $apiKey;
        $this->revision = config('klaviyo.revision', '2024-10-15');
    }

    /**
     * Perform a GET request against the Klaviyo API.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function get(string $endpoint, array $query = []): array
    {
        $url = self::BASE_URL.'/'.ltrim($endpoint, '/');

        $response = $this->requestWithRetry('GET', $url, $query);

        return $response->json();
    }

    /**
     * Perform a POST request against the Klaviyo API.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function post(string $endpoint, array $data = []): array
    {
        $url = self::BASE_URL.'/'.ltrim($endpoint, '/');

        $response = $this->requestWithRetry('POST', $url, $data);

        return $response->json();
    }

    /**
     * Paginate through all results of a GET endpoint using cursor-based pagination.
     *
     * @param  array<string, mixed>  $query
     * @return \Generator<int, array<string, mixed>>
     */
    public function paginate(string $endpoint, array $query = []): \Generator
    {
        $url = self::BASE_URL.'/'.ltrim($endpoint, '/');

        do {
            $response = $this->requestWithRetry('GET', $url, $query);
            $json = $response->json();
            unset($response);

            $items = $json['data'] ?? [];
            $nextUrl = $json['links']['next'] ?? null;
            unset($json);

            foreach ($items as $item) {
                yield $item;
            }
            unset($items);

            if ($nextUrl) {
                // Parse the next URL and extract query params to avoid double-encoding
                // of brackets in parameter names (e.g. page[cursor] → page%5Bcursor%5D).
                $parsed = parse_url($nextUrl);
                $url = $parsed['scheme'].'://'.$parsed['host'].($parsed['path'] ?? '');
                parse_str($parsed['query'] ?? '', $query);
            }
        } while ($nextUrl);
    }

    /**
     * Paginate through results page by page, exposing the cursor for resumability.
     * Each yielded value contains the page items and the next URL for cursor storage.
     *
     * @param  array<string, mixed>  $query
     * @return \Generator<int, array{items: array<int, array<string, mixed>>, next_url: ?string}>
     */
    public function paginatePages(string $endpoint, array $query = [], ?string $startUrl = null): \Generator
    {
        $url = $startUrl ?? (self::BASE_URL.'/'.ltrim($endpoint, '/'));

        if ($startUrl) {
            $parsed = parse_url($startUrl);
            $url = $parsed['scheme'].'://'.$parsed['host'].($parsed['path'] ?? '');
            parse_str($parsed['query'] ?? '', $query);
        }

        do {
            $response = $this->requestWithRetry('GET', $url, $query);
            $json = $response->json();
            unset($response);

            $items = $json['data'] ?? [];
            $nextUrl = $json['links']['next'] ?? null;
            unset($json);

            yield ['items' => $items, 'next_url' => $nextUrl];
            unset($items);

            if ($nextUrl) {
                $parsed = parse_url($nextUrl);
                $url = $parsed['scheme'].'://'.$parsed['host'].($parsed['path'] ?? '');
                parse_str($parsed['query'] ?? '', $query);
            }
        } while ($nextUrl);
    }

    /**
     * Execute an HTTP request with rate limit retry handling.
     *
     * @param  array<string, mixed>  $data
     */
    protected function requestWithRetry(string $method, string $url, array $data = []): Response
    {
        $maxRetries = 3;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                $response = match ($method) {
                    'GET' => $this->httpClient()->get($url, $data),
                    'POST' => $this->httpClient()->post($url, $data),
                    default => throw new RuntimeException("Unsupported HTTP method: {$method}"),
                };
            } catch (ConnectionException $e) {
                if ($attempt < $maxRetries) {
                    Log::warning('Klaviyo connection error, retrying', [
                        'attempt' => $attempt,
                        'error' => $e->getMessage(),
                        'url' => $url,
                    ]);
                    sleep(5 * $attempt);

                    continue;
                }

                throw new RuntimeException("Klaviyo API connection failed after {$maxRetries} attempts: {$e->getMessage()}");
            }

            if ($response->successful()) {
                return $response;
            }

            if ($response->status() === 429 && $attempt < $maxRetries) {
                $retryAfter = (int) $response->header('Retry-After', '10');
                Log::warning('Klaviyo rate limited, retrying', [
                    'attempt' => $attempt,
                    'retry_after' => $retryAfter,
                    'url' => $url,
                ]);
                sleep($retryAfter);

                continue;
            }

            throw new RuntimeException(
                "Klaviyo API request failed with status {$response->status()}: {$response->body()}"
            );
        }

        throw new RuntimeException('Klaviyo API request failed after max retries.');
    }

    /**
     * Build the base HTTP client with authentication and revision headers.
     */
    protected function httpClient(): PendingRequest
    {
        return Http::withHeaders([
            'Authorization' => "Klaviyo-API-Key {$this->apiKey}",
            'revision' => $this->revision,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ])->timeout(60);
    }
}
