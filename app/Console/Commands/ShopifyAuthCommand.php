<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

#[Signature('shopify:auth')]
#[Description('One-time OAuth flow to obtain a Shopify Admin API access token')]
class ShopifyAuthCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        try {
            $store = config('shopify.store');
            $clientId = config('shopify.client_id');
            $clientSecret = config('shopify.client_secret');

            if (! $store || ! $clientId || ! $clientSecret) {
                $this->components->error('Missing SHOPIFY_STORE, SHOPIFY_CLIENT_ID, or SHOPIFY_CLIENT_SECRET in .env');

                return self::FAILURE;
            }

            $redirectUri = 'http://localhost:8888/callback';
            $nonce = Str::random(32);
            $scopes = $this->ask(
                'Which scopes do you need? (comma-separated)',
                'read_products,read_orders,read_customers,read_inventory,write_products,read_content,write_content,read_files,write_files,read_themes,write_themes,read_publications,write_translations'
            );

            $authUrl = "https://{$store}/admin/oauth/authorize?"
                .http_build_query([
                    'client_id' => $clientId,
                    'scope' => $scopes,
                    'redirect_uri' => $redirectUri,
                    'state' => $nonce,
                ]);

            $this->newLine();
            $this->components->info('Step 1: Open this URL in your browser:');
            $this->newLine();
            $this->line("  {$authUrl}");
            $this->newLine();
            $this->components->info('Step 2: After authorizing, you will be redirected to a URL like:');
            $this->line("  {$redirectUri}?code=XXXXX&state={$nonce}&...");
            $this->newLine();
            $this->components->info('Step 3: Copy the full redirect URL and paste it below:');

            $callbackUrl = $this->ask('Paste the full callback URL');

            if (! $callbackUrl) {
                $this->components->error('No URL provided.');

                return self::FAILURE;
            }

            // Parse the authorization code from the callback URL
            $parsedUrl = parse_url($callbackUrl);
            parse_str($parsedUrl['query'] ?? '', $params);

            $code = $params['code'] ?? null;
            $state = $params['state'] ?? null;

            if (! $code) {
                $this->components->error('No authorization code found in the URL.');

                return self::FAILURE;
            }

            if ($state !== $nonce) {
                $this->components->warn('State mismatch — proceeding anyway for manual flow.');
            }

            $this->info('Exchanging authorization code for access token...');

            $response = Http::post("https://{$store}/admin/oauth/access_token", [
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'code' => $code,
            ]);

            if ($response->failed()) {
                $this->components->error("Token exchange failed: {$response->body()}");

                return self::FAILURE;
            }

            $accessToken = $response->json('access_token');
            $grantedScopes = $response->json('scope');

            $masked = substr($accessToken, 0, 6).'...'.substr($accessToken, -4);

            $this->newLine();
            $this->components->twoColumnDetail('Access Token', $masked);
            $this->components->twoColumnDetail('Scopes', $grantedScopes);
            $this->newLine();

            if ($this->confirm('Write token to .env automatically?', true)) {
                $envPath = base_path('.env');
                $envContents = file_get_contents($envPath);

                if (str_contains($envContents, 'SHOPIFY_ACCESS_TOKEN=')) {
                    $envContents = preg_replace(
                        '/^SHOPIFY_ACCESS_TOKEN=.*$/m',
                        "SHOPIFY_ACCESS_TOKEN={$accessToken}",
                        $envContents,
                    );
                } else {
                    $envContents .= "\nSHOPIFY_ACCESS_TOKEN={$accessToken}\n";
                }

                file_put_contents($envPath, $envContents);
                $this->components->info("Token written to .env ({$masked})");
            } else {
                $this->components->warn('Copy the token from your terminal — it will not be shown again.');
                $this->line("  SHOPIFY_ACCESS_TOKEN={$accessToken}");
            }

            $this->newLine();
            $this->components->warn('Store this token securely. Do not commit it to version control.');

            return self::SUCCESS;
        } catch (\Throwable $e) {
            Log::error('ShopifyAuthCommand failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
