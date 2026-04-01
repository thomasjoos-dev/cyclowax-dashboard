<?php

namespace App\Console\Commands;

use App\Models\SupplyProfile;
use App\Services\Forecast\SupplyProfileAnalyzer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('forecast:sync-supply-profiles {--dry-run : Show analysis without updating profiles}')]
#[Description('Analyze Odoo purchase orders to calculate lead times, MOQs and order frequency per product category')]
class SyncSupplyProfilesCommand extends Command
{
    public function handle(SupplyProfileAnalyzer $analyzer): int
    {
        $this->info('Fetching purchase order data from Odoo...');

        $analysis = $analyzer->analyze();

        if (empty($analysis)) {
            $this->error('No purchase order data found linked to known products.');

            return self::FAILURE;
        }

        // Show analysis results
        $this->info('Analysis results ('.collect($analysis)->sum('sample_size').' PO lines matched):');
        $this->newLine();

        $rows = [];
        foreach ($analysis as $categoryValue => $metrics) {
            $current = SupplyProfile::where('product_category', $categoryValue)->first();

            $rows[] = [
                $categoryValue,
                $metrics['sample_size'],
                $current?->procurement_lead_time_days ?? '-',
                $metrics['procurement_lead_time_days'] !== null ? $metrics['procurement_lead_time_days'].'d' : 'n/a',
                $current?->moq ?? '-',
                $metrics['moq'] !== null ? $metrics['moq'] : 'n/a',
                $metrics['order_frequency_days'] !== null ? $metrics['order_frequency_days'].'d' : 'n/a',
                implode(', ', array_slice($metrics['suppliers'], 0, 2))
                    .(count($metrics['suppliers']) > 2 ? ' +'.(count($metrics['suppliers']) - 2) : ''),
            ];
        }

        $this->table(
            ['Category', 'PO Lines', 'Current LT', 'Calculated LT', 'Current MOQ', 'Calculated MOQ', 'Order Freq', 'Suppliers'],
            $rows,
        );

        if ($this->option('dry-run')) {
            $this->info('Dry run — no changes made.');

            return self::SUCCESS;
        }

        // Update profiles
        $changes = $analyzer->updateProfiles($analysis);

        if (empty($changes)) {
            $this->info('No changes needed — profiles already match.');
        } else {
            $this->info('Updated '.count($changes).' supply profiles.');
            $this->warn('Updated profiles need re-validation by procurement. Mark as validated via the dashboard or tinker.');
        }

        return self::SUCCESS;
    }
}
