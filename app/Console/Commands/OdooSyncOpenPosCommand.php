<?php

namespace App\Console\Commands;

use App\Services\Sync\OdooOpenPoSyncer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('odoo:sync-open-pos')]
#[Description('Sync open (pending delivery) purchase orders from Odoo')]
class OdooSyncOpenPosCommand extends Command
{
    public function handle(OdooOpenPoSyncer $syncer): int
    {
        $this->info('Syncing open purchase orders from Odoo...');

        try {
            $result = $syncer->sync();

            $this->info("Total PO lines fetched: {$result['total_po_lines']}");
            $this->info("Open PO lines synced: {$result['synced']}");

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Sync failed: {$e->getMessage()}");

            return self::FAILURE;
        }
    }
}
