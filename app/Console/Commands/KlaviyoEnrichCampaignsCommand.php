<?php

namespace App\Console\Commands;

use App\Services\Sync\KlaviyoCampaignSyncer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('klaviyo:enrich-campaigns {--limit=20 : Maximum campaigns to enrich per run}')]
#[Description('Enrich sent campaigns with metrics from the Klaviyo Reporting API')]
class KlaviyoEnrichCampaignsCommand extends Command
{
    public function handle(KlaviyoCampaignSyncer $syncer): int
    {
        $limit = (int) $this->option('limit');

        $this->components->info("Enriching up to {$limit} campaigns...");

        try {
            $result = $syncer->enrichCampaigns($limit);

            if ($result['enriched'] === 0 && $result['remaining'] === 0) {
                $this->components->info('All campaigns are already enriched.');

                return self::SUCCESS;
            }

            $this->components->info("Enriched {$result['enriched']} campaigns. Remaining: {$result['remaining']}.");

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->components->error("Enrichment failed: {$e->getMessage()}");

            return self::FAILURE;
        }
    }
}
