<?php

namespace App\Console\Commands;

use App\Services\Sync\OdooProductSyncer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('odoo:sync-products')]
#[Description('Sync products from Odoo (COGS, stock, categories) and record stock snapshots')]
class OdooSyncProductsCommand extends Command
{
    public function handle(OdooProductSyncer $syncer): int
    {
        $this->info('Syncing products from Odoo...');

        try {
            $result = $syncer->sync();

            $this->info("Products synced: {$result['products']}");
            $this->info("Stock snapshots recorded: {$result['snapshots']}");

            $this->newLine();
            $this->info('Enriching product types from Shopify line items...');
            $enriched = $syncer->enrichFromShopifyLineItems();
            $this->info("Product types enriched: {$enriched}");

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Sync failed: {$e->getMessage()}");

            return self::FAILURE;
        }
    }
}
