<?php

namespace App\Console\Commands;

use App\Services\Scoring\SuspectProfileFlagger;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

#[Signature('profiles:flag-suspects')]
#[Description('Flag suspect Klaviyo profiles (bots, spam, disposable emails) based on behavioral and email patterns')]
class FlagSuspectProfilesCommand extends Command
{
    public function handle(SuspectProfileFlagger $flagger): int
    {
        try {
            $this->components->info('Flagging suspect profiles...');

            $counts = $flagger->flag();
            $total = array_sum($counts);

            Log::info('Suspect profiles flagged', $counts);

            $this->table(
                ['Rule', 'Flagged'],
                collect($counts)->map(fn ($count, $rule) => [$rule, $count])->values()->toArray(),
            );

            $this->components->info("Flagged {$total} suspect profiles.");

            return self::SUCCESS;
        } catch (\Throwable $e) {
            Log::error('FlagSuspectProfilesCommand failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
