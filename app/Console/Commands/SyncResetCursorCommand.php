<?php

namespace App\Console\Commands;

use App\Models\SyncState;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

#[Signature('sync:reset-cursor {step? : The sync step to reset (e.g. klaviyo:sync-campaigns)} {--all : Reset cursors for all sync steps}')]
#[Description('Reset stale or stuck sync cursors to unblock the pipeline')]
class SyncResetCursorCommand extends Command
{
    public function handle(): int
    {
        if (! $this->argument('step') && ! $this->option('all')) {
            $this->components->error('Provide a step name or use --all to reset all cursors.');

            return self::FAILURE;
        }

        $query = SyncState::query();

        if ($step = $this->argument('step')) {
            $query->where('step', $step);
        }

        $states = $query->get();

        if ($states->isEmpty()) {
            $this->components->warn('No sync states found'.($step ? " for step: {$step}" : '').'.');

            return self::SUCCESS;
        }

        $resetCount = 0;

        foreach ($states as $state) {
            $hasCursor = ! empty($state->cursor);
            $isRunning = $state->status === 'running';

            if (! $hasCursor && ! $isRunning) {
                $this->components->info("[{$state->step}] No cursor or running state — skipping.");

                continue;
            }

            $this->components->warn("[{$state->step}] status={$state->status}, cursor=".json_encode($state->cursor));

            $state->update(['status' => 'idle', 'cursor' => null]);
            $resetCount++;

            Log::info("Sync cursor reset: {$state->step}");
            $this->components->info("[{$state->step}] Reset to idle.");
        }

        $this->newLine();
        $this->components->info("Reset {$resetCount} sync state(s).");

        return self::SUCCESS;
    }
}
