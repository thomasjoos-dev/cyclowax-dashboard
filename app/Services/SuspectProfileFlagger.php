<?php

namespace App\Services;

use App\Models\KlaviyoProfile;
use Illuminate\Support\Facades\DB;

class SuspectProfileFlagger
{
    /**
     * Flag all suspect profiles. Resets existing flags first (idempotent).
     *
     * @return array<string, int> Counts per rule
     */
    public function flag(): array
    {
        $this->resetFlags();

        return [
            'disposable_email' => $this->flagDisposableEmails(),
            'ghost_checkout' => $this->flagGhostCheckouts(),
            'bot_opens' => $this->flagBotOpens(),
        ];
    }

    private function resetFlags(): void
    {
        KlaviyoProfile::query()->where('is_suspect', true)->update([
            'is_suspect' => false,
            'suspect_reason' => null,
        ]);
    }

    private function flagDisposableEmails(): int
    {
        return KlaviyoProfile::query()
            ->where('is_suspect', false)
            ->where(function ($query) {
                foreach (config('scoring.suspect.email_patterns') as $pattern) {
                    $query->orWhere(DB::raw('LOWER(email)'), 'like', $pattern);
                }
            })
            ->update([
                'is_suspect' => true,
                'suspect_reason' => 'disposable_email',
            ]);
    }

    private function flagGhostCheckouts(): int
    {
        return KlaviyoProfile::query()
            ->where('is_suspect', false)
            ->where('checkouts_started', '>=', config('scoring.suspect.ghost_checkout_threshold'))
            ->where('product_views', config('scoring.suspect.ghost_checkout_max_views'))
            ->update([
                'is_suspect' => true,
                'suspect_reason' => 'ghost_checkout',
            ]);
    }

    private function flagBotOpens(): int
    {
        return KlaviyoProfile::query()
            ->where('is_suspect', false)
            ->where('emails_received', '>', 0)
            ->whereRaw('emails_opened > emails_received * ?', [config('scoring.suspect.bot_open_multiplier')])
            ->update([
                'is_suspect' => true,
                'suspect_reason' => 'bot_opens',
            ]);
    }
}
