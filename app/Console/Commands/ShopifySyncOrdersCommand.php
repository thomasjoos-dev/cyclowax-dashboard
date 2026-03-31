<?php

namespace App\Console\Commands;

use App\Services\Sync\ShopifyOrderSyncer;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('shopify:sync-orders {--from= : Start date (Y-m-d), defaults to 3 days ago} {--to= : End date (Y-m-d), defaults to today}')]
#[Description('Sync orders from the Shopify Admin API')]
class ShopifySyncOrdersCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(ShopifyOrderSyncer $syncer): int
    {
        $from = $this->option('from')
            ? CarbonImmutable::parse($this->option('from'))->startOfDay()
            : CarbonImmutable::now()->subDays(3)->startOfDay();

        $to = $this->option('to')
            ? CarbonImmutable::parse($this->option('to'))->endOfDay()
            : CarbonImmutable::now()->endOfDay();

        $this->components->info("Syncing orders from {$from->toDateString()} to {$to->toDateString()}...");

        try {
            $count = $syncer->sync($from, $to);

            $this->components->info("Synced {$count} orders.");

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->components->error("Sync failed: {$e->getMessage()}");

            return self::FAILURE;
        }
    }
}
