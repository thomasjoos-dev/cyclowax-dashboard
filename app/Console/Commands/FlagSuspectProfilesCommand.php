<?php

namespace App\Console\Commands;

use App\Models\KlaviyoProfile;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

#[Signature('profiles:flag-suspects')]
#[Description('Flag suspect Klaviyo profiles (bots, spam, disposable emails) based on behavioral and email patterns')]
class FlagSuspectProfilesCommand extends Command
{
    /** Disposable/spam email patterns */
    protected const array SUSPECT_EMAIL_PATTERNS = [
        '%example.com',
        '%mailinator%',
        '%guerrillamail%',
        '%tempmail%',
        '%disposable%',
        '%blackhat%',
        'guest@%',
    ];

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->components->info('Flagging suspect profiles...');

        // Reset all flags first (idempotent)
        KlaviyoProfile::query()->where('is_suspect', true)->update([
            'is_suspect' => false,
            'suspect_reason' => null,
        ]);

        $counts = [
            'disposable_email' => $this->flagDisposableEmails(),
            'ghost_checkout' => $this->flagGhostCheckouts(),
            'bot_opens' => $this->flagBotOpens(),
        ];

        $total = array_sum($counts);

        Log::info('Suspect profiles flagged', $counts);

        $this->table(
            ['Rule', 'Flagged'],
            collect($counts)->map(fn ($count, $rule) => [$rule, $count])->values()->toArray(),
        );

        $this->components->info("Flagged {$total} suspect profiles.");

        return self::SUCCESS;
    }

    /**
     * Flag profiles with disposable or spam email domains.
     */
    protected function flagDisposableEmails(): int
    {
        return KlaviyoProfile::query()
            ->where('is_suspect', false)
            ->where(function ($query) {
                foreach (self::SUSPECT_EMAIL_PATTERNS as $pattern) {
                    $query->orWhere(DB::raw('LOWER(email)'), 'like', $pattern);
                }
            })
            ->update([
                'is_suspect' => true,
                'suspect_reason' => 'disposable_email',
            ]);
    }

    /**
     * Flag profiles with ghost checkouts: 3+ checkouts but 0 product views.
     */
    protected function flagGhostCheckouts(): int
    {
        return KlaviyoProfile::query()
            ->where('is_suspect', false)
            ->where('checkouts_started', '>=', 3)
            ->where('product_views', 0)
            ->update([
                'is_suspect' => true,
                'suspect_reason' => 'ghost_checkout',
            ]);
    }

    /**
     * Flag profiles with bot-like open patterns: opens > 5x received.
     */
    protected function flagBotOpens(): int
    {
        return KlaviyoProfile::query()
            ->where('is_suspect', false)
            ->where('emails_received', '>', 0)
            ->whereRaw('emails_opened > emails_received * 5')
            ->update([
                'is_suspect' => true,
                'suspect_reason' => 'bot_opens',
            ]);
    }
}
