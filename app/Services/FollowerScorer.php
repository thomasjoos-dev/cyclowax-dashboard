<?php

namespace App\Services;

use App\Models\CustomerProfile;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

class FollowerScorer
{
    protected int $scoredCount = 0;

    /**
     * Calculate engagement scores, intent scores and assign segments for all follower profiles.
     */
    public function score(): int
    {
        $this->scoredCount = 0;

        Log::info('Follower scoring starting');

        // Clear scores for suspect profiles
        CustomerProfile::query()
            ->where('lifecycle_stage', 'follower')
            ->whereHas('klaviyoProfile', fn ($q) => $q->where('is_suspect', true))
            ->whereNotNull('follower_segment')
            ->update(['follower_segment' => null, 'engagement_score' => null, 'intent_score' => null]);

        CustomerProfile::query()
            ->where('lifecycle_stage', 'follower')
            ->whereNotNull('klaviyo_profile_id')
            ->whereHas('klaviyoProfile', fn ($q) => $q->where('is_suspect', false))
            ->with('klaviyoProfile')
            ->chunkById(500, function ($profiles) {
                foreach ($profiles as $profile) {
                    $this->scoreProfile($profile);
                }

                $this->scoredCount += $profiles->count();
            });

        Log::info('Follower scoring completed', ['scored' => $this->scoredCount]);

        return $this->scoredCount;
    }

    /**
     * Score a single follower profile based on engagement and intent data.
     */
    protected function scoreProfile(CustomerProfile $profile): void
    {
        $klaviyo = $profile->klaviyoProfile;

        if (! $klaviyo) {
            return;
        }

        $engagementScore = $this->calculateEngagementScore($klaviyo);
        $intentScore = $this->calculateIntentScore($klaviyo);
        $segment = $this->determineSegment($klaviyo, $engagementScore, $intentScore);

        $profile->update([
            'engagement_score' => $engagementScore,
            'intent_score' => $intentScore,
            'follower_segment' => $segment,
        ]);
    }

    /**
     * Calculate a weighted engagement score (1-5).
     * Weights: site visits 35%, email clicks 30%, email opens 20%, recency 15%.
     */
    protected function calculateEngagementScore(mixed $klaviyo): int
    {
        $received = max((int) $klaviyo->emails_received, 1);
        $clickRate = min((int) $klaviyo->emails_clicked / $received, 1.0);
        $openRate = min((int) $klaviyo->emails_opened / $received, 1.0);
        $siteTier = $this->siteTier((int) $klaviyo->site_visits);
        $recencyScore = $this->recencyScore($klaviyo->last_event_date);

        $rawScore = ($siteTier * 0.35) + ($clickRate * 0.30) + ($openRate * 0.20) + ($recencyScore * 0.15);

        return match (true) {
            $rawScore >= 0.60 => 5,
            $rawScore >= 0.40 => 4,
            $rawScore >= 0.25 => 3,
            $rawScore >= 0.10 => 2,
            default => 1,
        };
    }

    /**
     * Convert site visit count to a 0.0-1.0 tier score.
     */
    protected function siteTier(int $siteVisits): float
    {
        return match (true) {
            $siteVisits >= 11 => 1.0,
            $siteVisits >= 6 => 0.8,
            $siteVisits >= 3 => 0.6,
            $siteVisits >= 1 => 0.3,
            default => 0.0,
        };
    }

    /**
     * Convert last_event_date to a 0.0-1.0 recency score.
     */
    protected function recencyScore(?CarbonImmutable $lastEventDate): float
    {
        if (! $lastEventDate) {
            return 0.0;
        }

        $daysSince = (int) $lastEventDate->diffInDays(now());

        return match (true) {
            $daysSince <= 7 => 1.0,
            $daysSince <= 30 => 0.7,
            $daysSince <= 90 => 0.3,
            default => 0.0,
        };
    }

    /**
     * Calculate intent score (0-4) based on highest funnel step reached.
     * Halves if the highest event is older than 30 days.
     */
    protected function calculateIntentScore(mixed $klaviyo): int
    {
        $baseScore = match (true) {
            (int) $klaviyo->checkouts_started > 0 => 4,
            (int) $klaviyo->cart_adds > 0 => 3,
            (int) $klaviyo->product_views > 0 => 2,
            (int) $klaviyo->site_visits > 0 => 1,
            default => 0,
        };

        if ($baseScore === 0) {
            return 0;
        }

        // Halve the score if last event is older than 30 days
        $daysSinceLastEvent = $klaviyo->last_event_date
            ? (int) $klaviyo->last_event_date->diffInDays(now())
            : 999;

        if ($daysSinceLastEvent > 30) {
            return (int) floor($baseScore / 2);
        }

        return $baseScore;
    }

    /**
     * Determine the follower segment using a waterfall approach.
     */
    protected function determineSegment(mixed $klaviyo, int $engagementScore, int $intentScore): string
    {
        $daysSinceSignup = $klaviyo->klaviyo_created_at
            ? (int) $klaviyo->klaviyo_created_at->diffInDays(now())
            : 999;

        $daysSinceLastEvent = $klaviyo->last_event_date
            ? (int) $klaviyo->last_event_date->diffInDays(now())
            : 999;

        return match (true) {
            $daysSinceSignup < 30 => 'new',
            $intentScore >= 3 && $daysSinceLastEvent <= 30 => 'hot_lead',
            $intentScore >= 2 && $engagementScore >= 3 && $daysSinceLastEvent <= 30 => 'high_potential',
            $engagementScore >= 3 && $daysSinceLastEvent <= 30 => 'engaged',
            $daysSinceLastEvent > 30 && $daysSinceLastEvent <= 90 && ($engagementScore >= 2 || $intentScore >= 1) => 'fading',
            default => 'inactive',
        };
    }
}
