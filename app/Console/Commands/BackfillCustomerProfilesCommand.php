<?php

namespace App\Console\Commands;

use App\Models\ShopifyCustomer;
use App\Services\Api\ShopifyClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

#[Signature('app:backfill-customer-profiles')]
#[Description('Backfill first_name, last_name, locale, tags, email consent and created_at from Shopify for existing customers')]
class BackfillCustomerProfilesCommand extends Command
{
    public function handle(ShopifyClient $shopify): int
    {
        try {
            $total = ShopifyCustomer::query()->whereNull('first_name')->count();

            if ($total === 0) {
                $this->info('All customers already have profile data.');

                return self::SUCCESS;
            }

            $this->info("Backfilling profile data for {$total} customers via Shopify bulk operation...");

            $bulkQuery = <<<'GRAPHQL'
                {
                    customers {
                        edges {
                            node {
                                id
                                firstName
                                lastName
                                locale
                                tags
                                emailMarketingConsent { marketingState }
                                createdAt
                            }
                        }
                    }
                }
            GRAPHQL;

            $operation = $shopify->bulkOperation($bulkQuery);

            Log::info('Customer backfill bulk operation started', ['id' => $operation['id']]);
            $this->info('Bulk operation started, polling for completion...');

            $maxAttempts = 120;

            for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
                sleep(5);
                $status = $shopify->bulkOperationStatus();

                if ($status['status'] === 'COMPLETED') {
                    if (! $status['url']) {
                        $this->warn('Bulk operation completed but no results URL returned.');

                        return self::FAILURE;
                    }

                    $updated = $this->processResults($shopify, $status['url']);
                    $this->info("Backfilled {$updated} customers.");

                    return self::SUCCESS;
                }

                if ($status['status'] === 'FAILED') {
                    $this->error("Bulk operation failed: {$status['errorCode']}");

                    return self::FAILURE;
                }

                if ($attempt % 6 === 0) {
                    $this->info("Still waiting... ({$status['objectCount']} objects processed)");
                }
            }

            $this->error('Bulk operation timed out after 10 minutes.');

            return self::FAILURE;
        } catch (\Throwable $e) {
            Log::error('BackfillCustomerProfilesCommand failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function processResults(ShopifyClient $shopify, string $url): int
    {
        $updated = 0;

        foreach ($shopify->bulkOperationResults($url) as $row) {
            $shopifyId = str_replace('gid://shopify/Customer/', '', $row['id']);

            $customer = ShopifyCustomer::query()->where('shopify_id', $shopifyId)->first();

            if (! $customer) {
                continue;
            }

            $tags = $row['tags'] ?? [];

            $customer->update([
                'first_name' => $row['firstName'] ?? null,
                'last_name' => $row['lastName'] ?? null,
                'locale' => $row['locale'] ?? null,
                'tags' => ! empty($tags) ? (is_array($tags) ? implode(',', $tags) : $tags) : null,
                'email_marketing_consent' => $row['emailMarketingConsent']['marketingState'] ?? null,
                'shopify_created_at' => $row['createdAt'] ?? null,
            ]);

            $updated++;
        }

        return $updated;
    }
}
