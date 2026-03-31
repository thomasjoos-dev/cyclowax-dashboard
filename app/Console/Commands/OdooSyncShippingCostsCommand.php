<?php

namespace App\Console\Commands;

use App\Services\Sync\OdooShippingCostSyncer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('odoo:sync-shipping-costs')]
#[Description('Sync shipping carrier names and exact costs from Odoo pickings')]
class OdooSyncShippingCostsCommand extends Command
{
    public function handle(OdooShippingCostSyncer $syncer): int
    {
        $this->info('Syncing shipping costs from Odoo...');

        try {
            $result = $syncer->sync();

            $this->info("Carrier names synced: {$result['carriers']}");
            $this->info("Exact costs synced: {$result['exact_costs']}");

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Sync failed: {$e->getMessage()}");

            return self::FAILURE;
        }
    }
}
