<?php

namespace App\Console\Commands;

use App\Models\SyncState;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('sync:status')]
#[Description('Display sync pipeline status: all steps with last sync time and age')]
class SyncStatusCommand extends Command
{
    public function handle(): int
    {
        $states = SyncState::orderBy('last_synced_at', 'desc')->get();

        if ($states->isEmpty()) {
            $this->components->warn('No sync states found. Run sync:all first.');

            return self::SUCCESS;
        }

        $this->table(
            ['Step', 'Status', 'Last Synced', 'Age', 'Duration', 'Records', 'Full Sync'],
            $states->map(fn (SyncState $s) => [
                $s->step,
                $s->status,
                $s->last_synced_at?->format('Y-m-d H:i:s') ?? 'never',
                $s->last_synced_at?->diffForHumans() ?? '-',
                $s->duration_seconds ? round($s->duration_seconds, 1).'s' : '-',
                $s->records_synced ?? '-',
                $s->was_full_sync ? 'yes' : 'no',
            ]),
        );

        $staleCount = $states->filter(fn (SyncState $s) => $s->last_synced_at && $s->last_synced_at->lt(now()->subHours(25)))->count();

        if ($staleCount > 0) {
            $this->components->warn("{$staleCount} step(s) older than 25 hours.");
        } else {
            $this->components->info('All sync steps are fresh.');
        }

        return self::SUCCESS;
    }
}
