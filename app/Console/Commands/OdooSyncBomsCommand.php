<?php

namespace App\Console\Commands;

use App\Services\Sync\OdooBomSyncer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('odoo:sync-boms')]
#[Description('Sync BOMs and assembly lead times from Odoo')]
class OdooSyncBomsCommand extends Command
{
    public function handle(OdooBomSyncer $syncer): int
    {
        $this->info('Syncing BOMs from Odoo...');

        try {
            $result = $syncer->sync();

            $this->info("BOMs synced: {$result['boms']}");
            $this->info("BOM lines synced: {$result['lines']}");
            $this->info("Skipped (no local product): {$result['skipped']}");

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Sync failed: {$e->getMessage()}");

            return self::FAILURE;
        }
    }
}
