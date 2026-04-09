<?php

namespace App\Console\Commands;

use App\Services\Scoring\ChannelClassificationService;
use App\Services\Scoring\OrderMarginCalculator;
use App\Services\Sync\LineItemLinker;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

#[Signature('orders:compute-margins {--full : Recompute all orders, not just new ones}')]
#[Description('Link line items to products, compute net revenue/COGS/margin per order, and classify first orders')]
class ComputeOrderMarginsCommand extends Command
{
    public function handle(
        LineItemLinker $linker,
        OrderMarginCalculator $calculator,
        ChannelClassificationService $channelClassifier,
    ): int {
        try {
            $full = (bool) $this->option('full');

            // 1. Link line items to products
            $this->info('Linking line items to products...');
            $stats = $linker->linkAll();
            $total = array_sum($stats) - $stats['cost'];
            $this->info("  Linked: {$total} (SKU: {$stats['sku']}, barcode: {$stats['barcode']}, alias: {$stats['alias']}, title: {$stats['title']}), COGS set: {$stats['cost']}");

            // 2. Compute margins
            $this->info($full ? 'Recomputing ALL order margins...' : 'Computing margins for new orders...');
            $computed = $calculator->computeMargins($full);
            $this->info("  Orders computed: {$computed}");

            // 3. Classify first orders
            $this->info('Classifying first orders...');
            $classified = $calculator->classifyFirstOrders();
            $this->info("  Classified: {$classified} orders");

            // 4. Classify channels
            $this->info('Classifying channel types...');
            $channelCount = $channelClassifier->classifyUnclassifiedOrders();
            $this->info("  Classified: {$channelCount} orders");

            // 5. Classify refined channels
            $this->info($full ? 'Reclassifying ALL refined channels...' : 'Classifying refined channels...');
            $refinedCount = $channelClassifier->classifyRefinedChannels($full);
            $this->info("  Classified: {$refinedCount} orders");

            // 6. Update customer aggregates
            $this->info('Updating customer aggregates...');
            $updated = $calculator->updateCustomerAggregates();
            $this->info("  Customers updated: {$updated}");

            return self::SUCCESS;
        } catch (\Throwable $e) {
            Log::error('ComputeOrderMarginsCommand failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
