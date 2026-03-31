<?php

namespace App\Console\Commands;

use App\Services\Api\ShopifyClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('shopify:test')]
#[Description('Test the Shopify Admin API connection')]
class ShopifyTestCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(ShopifyClient $shopify): int
    {
        $this->info('Testing Shopify API connection...');
        $this->newLine();

        try {
            $response = $shopify->query(<<<'GRAPHQL'
                {
                    shop {
                        name
                        email
                        myshopifyDomain
                        plan {
                            displayName
                        }
                    }
                }
            GRAPHQL);

            $shop = $response['data']['shop'];

            $this->components->twoColumnDetail('Store', $shop['name']);
            $this->components->twoColumnDetail('Email', $shop['email']);
            $this->components->twoColumnDetail('Domain', $shop['myshopifyDomain']);
            $this->components->twoColumnDetail('Plan', $shop['plan']['displayName']);

            $this->newLine();
            $this->components->info('Connection successful!');

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->components->error("Connection failed: {$e->getMessage()}");

            return self::FAILURE;
        }
    }
}
