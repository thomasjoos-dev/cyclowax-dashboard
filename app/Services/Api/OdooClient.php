<?php

namespace App\Services\Api;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class OdooClient
{
    protected string $url;

    protected string $database;

    protected string $username;

    protected string $apiKey;

    protected ?int $uid = null;

    protected int $requestId = 0;

    public function __construct()
    {
        $this->url = config('odoo.url') ?? throw new RuntimeException('ODOO_URL is not configured.');
        $this->database = config('odoo.database') ?? throw new RuntimeException('ODOO_DATABASE is not configured.');
        $this->username = config('odoo.username') ?? throw new RuntimeException('ODOO_USERNAME is not configured.');
        $this->apiKey = config('odoo.api_key') ?? throw new RuntimeException('ODOO_API_KEY is not configured.');
    }

    /**
     * Authenticate with Odoo and return the user ID.
     * Caches the uid for the lifetime of this instance.
     */
    public function authenticate(): int
    {
        if ($this->uid !== null) {
            return $this->uid;
        }

        $result = $this->jsonRpc('common', 'authenticate', [
            $this->database,
            $this->username,
            $this->apiKey,
            [],
        ]);

        if (! is_int($result) || $result <= 0) {
            throw new RuntimeException('Odoo authentication failed. Check credentials and database name.');
        }

        $this->uid = $result;

        Log::info('Odoo authenticated', ['uid' => $this->uid]);

        return $this->uid;
    }

    /**
     * Execute a method on an Odoo model via execute_kw.
     *
     * @param  array<int, mixed>  $args
     * @param  array<string, mixed>  $kwargs
     */
    public function execute(string $model, string $method, array $args = [], array $kwargs = []): mixed
    {
        $uid = $this->authenticate();

        return $this->jsonRpc('object', 'execute_kw', [
            $this->database,
            $uid,
            $this->apiKey,
            $model,
            $method,
            $args,
            $kwargs,
        ]);
    }

    /**
     * Convenience method for search_read on a model.
     *
     * @param  array<int, array<int, mixed>>  $domain
     * @param  array<int, string>  $fields
     * @return array<int, array<string, mixed>>
     */
    public function searchRead(string $model, array $domain = [], array $fields = [], int $limit = 0, int $offset = 0): array
    {
        $kwargs = ['fields' => $fields];

        if ($limit > 0) {
            $kwargs['limit'] = $limit;
        }

        if ($offset > 0) {
            $kwargs['offset'] = $offset;
        }

        return $this->execute($model, 'search_read', [$domain], $kwargs);
    }

    /**
     * Count records matching a domain.
     *
     * @param  array<int, array<int, mixed>>  $domain
     */
    public function searchCount(string $model, array $domain = []): int
    {
        return $this->execute($model, 'search_count', [$domain]);
    }

    /**
     * Make a JSON-RPC call to the Odoo API.
     *
     * @param  array<int, mixed>  $args
     */
    protected function jsonRpc(string $service, string $method, array $args): mixed
    {
        $payload = [
            'jsonrpc' => '2.0',
            'method' => 'call',
            'id' => ++$this->requestId,
            'params' => [
                'service' => $service,
                'method' => $method,
                'args' => $args,
            ],
        ];

        $response = $this->request($payload);

        if (isset($response['error'])) {
            $message = $response['error']['data']['message'] ?? $response['error']['message'] ?? 'Unknown Odoo error';

            Log::error('Odoo RPC error', [
                'service' => $service,
                'method' => $method,
                'error' => $message,
            ]);

            throw new RuntimeException("Odoo RPC error: {$message}");
        }

        return $response['result'] ?? null;
    }

    /**
     * Send an HTTP request with retry logic.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function request(array $payload): array
    {
        $maxAttempts = 3;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $response = Http::timeout(30)
                    ->acceptJson()
                    ->post("{$this->url}/jsonrpc", $payload);

                if ($response->serverError()) {
                    Log::warning('Odoo server error', [
                        'status' => $response->status(),
                        'attempt' => $attempt,
                    ]);

                    if ($attempt < $maxAttempts) {
                        sleep(min($attempt * 2, 10));

                        continue;
                    }

                    throw new RuntimeException("Odoo server error: HTTP {$response->status()}");
                }

                return $response->json();
            } catch (RuntimeException $e) {
                throw $e;
            } catch (\Exception $e) {
                Log::warning('Odoo request failed', [
                    'error' => $e->getMessage(),
                    'attempt' => $attempt,
                ]);

                if ($attempt >= $maxAttempts) {
                    throw new RuntimeException("Odoo request failed after {$maxAttempts} attempts: {$e->getMessage()}");
                }

                sleep(min($attempt * 2, 10));
            }
        }

        throw new RuntimeException('Odoo request failed: exhausted all retry attempts.');
    }
}
