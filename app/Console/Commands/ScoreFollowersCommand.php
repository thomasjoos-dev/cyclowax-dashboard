<?php

namespace App\Console\Commands;

use App\Models\CustomerProfile;
use App\Services\FollowerScorer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

#[Signature('profiles:score-followers')]
#[Description('Calculate engagement scores and assign segments for follower profiles')]
class ScoreFollowersCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(FollowerScorer $scorer): int
    {
        $this->components->info('Scoring follower profiles...');

        try {
            $count = $scorer->score();

            $this->components->info("Scored {$count} follower profiles.");

            $this->printSegmentDistribution();

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->components->error("Scoring failed: {$e->getMessage()}");

            return self::FAILURE;
        }
    }

    /**
     * Print a summary of follower segment distribution.
     */
    protected function printSegmentDistribution(): void
    {
        $segments = CustomerProfile::query()
            ->where('lifecycle_stage', 'follower')
            ->whereNotNull('follower_segment')
            ->select('follower_segment', DB::raw('COUNT(*) as count'))
            ->groupBy('follower_segment')
            ->orderByDesc('count')
            ->get();

        if ($segments->isEmpty()) {
            return;
        }

        $this->newLine();

        $rows = $segments->map(fn ($s) => [$s->follower_segment, number_format($s->count)])->toArray();

        $this->table(['Segment', 'Count'], $rows);
    }
}
