<?php

namespace App\Services\Api;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class ShopifyClient
{
    protected string $endpoint;

    protected string $accessToken;

    public function __construct()
    {
        $store = config('shopify.store');
        $version = config('shopify.api_version');
        $this->accessToken = config('shopify.access_token');

        if (! $store || ! $this->accessToken) {
            throw new RuntimeException('Shopify store or access token is not configured. Check your .env file.');
        }

        $this->endpoint = "https://{$store}/admin/api/{$version}/graphql.json";
    }

    /**
     * Execute a GraphQL query or mutation against the Shopify Admin API.
     *
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     */
    public function query(string $query, array $variables = []): array
    {
        $response = $this->request($query, $variables);
        $data = $response->json();

        if (isset($data['errors'])) {
            Log::error('Shopify GraphQL errors', ['errors' => $data['errors']]);

            throw new RuntimeException('Shopify GraphQL error: '.json_encode($data['errors']));
        }

        return $data;
    }

    /**
     * Start a bulk operation query.
     *
     * @return array{id: string, status: string}
     */
    public function bulkOperation(string $query): array
    {
        $mutation = <<<GRAPHQL
            mutation {
                bulkOperationRunQuery(query: """{$query}""") {
                    bulkOperation {
                        id
                        status
                    }
                    userErrors {
                        field
                        message
                    }
                }
            }
        GRAPHQL;

        $response = $this->query($mutation);

        $result = $response['data']['bulkOperationRunQuery'] ?? [];

        if (! empty($result['userErrors'])) {
            throw new RuntimeException('Shopify bulk operation error: '.json_encode($result['userErrors']));
        }

        return $result['bulkOperation'];
    }

    /**
     * Poll the status of the current bulk operation.
     *
     * @return array{id: string, status: string, url: ?string, errorCode: ?string, objectCount: string}
     */
    public function bulkOperationStatus(): array
    {
        $query = <<<'GRAPHQL'
            {
                currentBulkOperation {
                    id
                    status
                    errorCode
                    objectCount
                    url
                }
            }
        GRAPHQL;

        $response = $this->query($query);

        return $response['data']['currentBulkOperation'] ?? [];
    }

    /**
     * Poll the status of the current bulk mutation operation.
     *
     * @return array{id: string, status: string, errorCode: ?string, objectCount: string}
     */
    public function bulkMutationStatus(): array
    {
        $query = <<<'GRAPHQL'
            {
                currentBulkOperation(type: MUTATION) {
                    id
                    status
                    errorCode
                    objectCount
                }
            }
        GRAPHQL;

        $response = $this->query($query);

        return $response['data']['currentBulkOperation'] ?? [];
    }

    /**
     * Download and parse bulk operation results as a JSONL stream.
     * Uses a temp file to avoid memory exhaustion on large result sets.
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function bulkOperationResults(string $url): \Generator
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'shopify_bulk_');

        try {
            $response = Http::sink($tempFile)->get($url);

            if ($response->failed()) {
                throw new RuntimeException('Failed to download bulk operation results.');
            }

            $handle = fopen($tempFile, 'r');

            while (($line = fgets($handle)) !== false) {
                $line = trim($line);

                if ($line !== '') {
                    yield json_decode($line, true);
                }
            }

            fclose($handle);
        } finally {
            @unlink($tempFile);
        }
    }

    /**
     * Upload a JSONL string via staged uploads and run a bulk mutation.
     *
     * @return array{id: string, status: string}
     */
    public function bulkMutation(string $mutation, string $jsonl): array
    {
        $stagedTarget = $this->stagedUpload($jsonl);

        $wrappedMutation = <<<GRAPHQL
            mutation {
                bulkOperationRunMutation(
                    mutation: "{$mutation}",
                    stagedUploadPath: "{$stagedTarget}"
                ) {
                    bulkOperation {
                        id
                        status
                    }
                    userErrors {
                        field
                        message
                    }
                }
            }
        GRAPHQL;

        $response = $this->query($wrappedMutation);
        $result = $response['data']['bulkOperationRunMutation'] ?? [];

        if (! empty($result['userErrors'])) {
            throw new RuntimeException('Shopify bulk mutation error: '.json_encode($result['userErrors']));
        }

        return $result['bulkOperation'];
    }

    /**
     * Create a staged upload and upload JSONL content.
     *
     * @return string The staged upload path for use in bulk operations.
     */
    protected function stagedUpload(string $jsonl): string
    {
        $mutation = <<<'GRAPHQL'
            mutation ($input: [StagedUploadInput!]!) {
                stagedUploadsCreate(input: $input) {
                    stagedTargets {
                        url
                        resourceUrl
                        parameters {
                            name
                            value
                        }
                    }
                    userErrors {
                        field
                        message
                    }
                }
            }
        GRAPHQL;

        $response = $this->query($mutation, [
            'input' => [[
                'filename' => 'bulk_mutation.jsonl',
                'mimeType' => 'text/jsonl',
                'httpMethod' => 'POST',
                'resource' => 'BULK_MUTATION_VARIABLES',
            ]],
        ]);

        $result = $response['data']['stagedUploadsCreate'] ?? [];

        if (! empty($result['userErrors'])) {
            throw new RuntimeException('Shopify staged upload error: '.json_encode($result['userErrors']));
        }

        $target = $result['stagedTargets'][0];
        $url = $target['url'];

        // Build multipart form: all parameters as fields, then the file
        $request = Http::asMultipart();

        foreach ($target['parameters'] as $param) {
            $request = $request->attach($param['name'], $param['value']);
        }

        $request->attach('file', $jsonl, 'bulk_mutation.jsonl')
            ->post($url)
            ->throw();

        // The stagedUploadPath for bulkOperationRunMutation is the key parameter
        $key = collect($target['parameters'])->firstWhere('name', 'key')['value'];

        return $key;
    }

    /**
     * Execute the HTTP request with rate limit handling.
     */
    protected function request(string $query, array $variables = []): Response
    {
        $maxRetries = 3;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            $response = $this->httpClient()->post($this->endpoint, [
                'query' => $query,
                'variables' => $variables ?: null,
            ]);

            if ($response->failed()) {
                if ($response->status() === 429 && $attempt < $maxRetries) {
                    $retryAfter = (float) $response->header('Retry-After', '2');
                    Log::warning('Shopify rate limited, retrying', [
                        'attempt' => $attempt,
                        'retry_after' => $retryAfter,
                    ]);
                    usleep((int) ($retryAfter * 1_000_000));

                    continue;
                }

                throw new RuntimeException("Shopify API request failed with status {$response->status()}: {$response->body()}");
            }

            // Check cost-based throttling from GraphQL extensions
            $this->handleThrottling($response->json('extensions.cost', []));

            return $response;
        }

        throw new RuntimeException('Shopify API request failed after max retries.');
    }

    /**
     * Handle Shopify's cost-based throttle by sleeping when available points are low.
     *
     * @param  array<string, mixed>  $cost
     */
    protected function handleThrottling(array $cost): void
    {
        if (empty($cost)) {
            return;
        }

        $throttleStatus = $cost['throttleStatus'] ?? [];
        $currentlyAvailable = (float) ($throttleStatus['currentlyAvailable'] ?? 1000);
        $restoreRate = (float) ($throttleStatus['restoreRate'] ?? 50);

        // When available points drop below 20% of max, slow down proactively
        $maxAvailable = (float) ($throttleStatus['maximumAvailable'] ?? 1000);
        $threshold = $maxAvailable * 0.2;

        if ($currentlyAvailable < $threshold && $restoreRate > 0) {
            $sleepSeconds = ($threshold - $currentlyAvailable) / $restoreRate;
            $sleepSeconds = min($sleepSeconds, 10);

            Log::info('Shopify throttle: slowing down', [
                'available' => $currentlyAvailable,
                'threshold' => $threshold,
                'sleep_seconds' => $sleepSeconds,
            ]);

            usleep((int) ($sleepSeconds * 1_000_000));
        }
    }

    /**
     * Build the base HTTP client with authentication headers.
     */
    protected function httpClient(): PendingRequest
    {
        return Http::withHeaders([
            'Content-Type' => 'application/json',
            'X-Shopify-Access-Token' => $this->accessToken,
        ])->timeout(30);
    }
}
