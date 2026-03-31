<?php

namespace App\Console\Commands;

use App\Services\Scoring\RfmScoringService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('customers:calculate-rfm')]
#[Description('Calculate RFM scores and assign segments for all customers with qualifying orders')]
class CalculateRfmScoresCommand extends Command
{
    public function handle(RfmScoringService $rfm): int
    {
        $this->info('Calculating RFM scores...');

        $result = $rfm->score();
        $scored = $result['scored'];

        if ($scored->isEmpty()) {
            $this->warn('No qualifying customers found.');

            if ($result['cleared'] > 0) {
                $this->info("  Cleared {$result['cleared']} out-of-scope customers.");
            }

            return self::SUCCESS;
        }

        $this->info("  Scored: {$scored->count()} customers");

        if ($result['cleared'] > 0) {
            $this->info("  Cleared {$result['cleared']} out-of-scope customers.");
        }

        if ($rfm->transitionsLogged() > 0) {
            $this->info("  Segment transitions logged: {$rfm->transitionsLogged()}");
        }

        $segments = $scored->groupBy(fn (array $row) => $row['rfm_segment']->value)->map->count()->sortDesc();

        $this->newLine();
        $this->info('  Segment distribution:');

        foreach ($segments as $segment => $count) {
            $pct = round($count / $scored->count() * 100, 1);
            $this->line("    {$segment}: {$count} ({$pct}%)");
        }

        return self::SUCCESS;
    }
}
