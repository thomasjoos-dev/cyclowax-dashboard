<?php

namespace App\Services\Scoring;

use App\Enums\FollowerSegment;
use App\Enums\LifecycleStage;
use App\Models\RiderProfile;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

class FollowerScorer
{
    protected int $scoredCount = 0;

    public function __construct(
        protected SegmentTransitionLogger $transitionLogger,
    ) {}

    /**
     * Calculate engagement scores, intent scores and assign segments for all follower profiles.
     */
    public function score(): int
    {
        $this->scoredCount = 0;

        Log::info('Follower scoring starting');

        // Clear scores for suspect profiles
        RiderProfile::query()
            ->where('lifecycle_stage', LifecycleStage::Follower)
            ->whereHas('klaviyoProfile', fn ($q) => $q->where('is_suspect', true))
            ->whereNotNull('segment')
            ->update(['segment' => null, 'engagement_score' => null, 'intent_score' => null]);

        RiderProfile::query()
            ->where('lifecycle_stage', LifecycleStage::Follower)
            ->whereNotNull('klaviyo_profile_id')
            ->whereHas('klaviyoProfile', fn ($q) => $q->where('is_suspect', false))
            ->with('klaviyoProfile')
            ->chunkById(500, function ($profiles) {
                foreach ($profiles as $profile) {
                    $this->scoreProfile($profile);
                }

                $this->scoredCount += $profiles->count();
            });

        Log::info('Follower scoring completed', [
            'scored' => $this->scoredCount,
            'transitions' => $this->transitionLogger->loggedCount(),
        ]);

        return $this->scoredCount;
    }

    /**
     * Score a single follower profile based on engagement and intent data.
     */
    protected function scoreProfile(RiderProfile $profile): void
    {
        $klaviyo = $profile->klaviyoProfile;

        if (! $klaviyo) {
            return;
        }

        $previousSegment = $profile->segment ? FollowerSegment::tryFrom($profile->segment) : null;

        $engagementScore = $this->calculateEngagementScore($klaviyo);
        $intentScore = $this->calculateIntentScore($klaviyo);
        $segment = $this->determineSegment($klaviyo, $engagementScore, $intentScore);

        $updateData = [
            'engagement_score' => $engagementScore,
            'intent_score' => $intentScore,
            'segment' => $segment->value,
            'previous_segment' => $previousSegment?->value,
        ];

        if ($previousSegment !== $segment) {
            $updateData['segment_changed_at'] = now();
        }

        $profile->update($updateData);

        $this->transitionLogger->logSegmentChange($profile->id, $previousSegment, $segment);
    }

    /**
     * Calculate a weighted engagement score (1-5).
     */
    protected function calculateEngagementScore(mixed $klaviyo): int
    {
        $weights = config('scoring.engagement.weights');

        $received = max((int) $klaviyo->emails_received, 1);
        $clickRate = min((int) $klaviyo->emails_clicked / $received, 1.0);
        $openRate = min((int) $klaviyo->emails_opened / $received, 1.0);
        $siteTier = $this->siteTier((int) $klaviyo->site_visits);
        $recencyScore = $this->recencyScore($klaviyo->last_event_date);

        $rawScore = ($siteTier * $weights['site_visits'])
            + ($clickRate * $weights['email_clicks'])
            + ($openRate * $weights['email_opens'])
            + ($recencyScore * $weights['recency']);

        foreach (config('scoring.engagement.score_thresholds') as $score => $threshold) {
            if ($rawScore >= $threshold) {
                return $score;
            }
        }

        return 1;
    }

    /**
     * Convert site visit count to a 0.0-1.0 tier score.
     */
    protected function siteTier(int $siteVisits): float
    {
        foreach (config('scoring.engagement.site_visit_tiers') as $min => $score) {
            if ($siteVisits >= $min) {
                return $score;
            }
        }

        return 0.0;
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

        foreach (config('scoring.engagement.recency_days') as $maxDays => $score) {
            if ($daysSince <= $maxDays) {
                return $score;
            }
        }

        return 0.0;
    }

    /**
     * Calculate intent score (0-4) based on highest funnel step reached.
     * Halves if the highest event is older than the decay threshold.
     */
    protected function calculateIntentScore(mixed $klaviyo): int
    {
        $baseScore = 0;

        foreach (config('scoring.engagement.intent_funnel') as $step) {
            if ((int) $klaviyo->{$step['field']} >= $step['min']) {
                $baseScore = $step['score'];
                break;
            }
        }

        if ($baseScore === 0) {
            return 0;
        }

        $daysSinceLastEvent = $klaviyo->last_event_date
            ? (int) $klaviyo->last_event_date->diffInDays(now())
            : 999;

        if ($daysSinceLastEvent > config('scoring.engagement.intent_decay_days')) {
            return (int) floor($baseScore / 2);
        }

        return $baseScore;
    }

    /**
     * Determine the follower segment using a waterfall approach.
     */
    protected function determineSegment(mixed $klaviyo, int $engagementScore, int $intentScore): FollowerSegment
    {
        $s = config('scoring.engagement.segments');

        $daysSinceSignup = $klaviyo->klaviyo_created_at
            ? (int) $klaviyo->klaviyo_created_at->diffInDays(now())
            : 999;

        $daysSinceLastEvent = $klaviyo->last_event_date
            ? (int) $klaviyo->last_event_date->diffInDays(now())
            : 999;

        return match (true) {
            $intentScore >= $s['hot_lead_min_intent'] && $daysSinceLastEvent <= $s['hot_lead_max_days'] => FollowerSegment::HotLead,
            $intentScore >= $s['high_potential_min_intent'] && $engagementScore >= $s['high_potential_min_engagement'] && $daysSinceLastEvent <= $s['high_potential_max_days'] => FollowerSegment::HighPotential,
            $daysSinceSignup < $s['new_max_days_since_signup'] => FollowerSegment::New,
            $engagementScore >= $s['engaged_min_engagement'] && $daysSinceLastEvent <= $s['engaged_max_days'] => FollowerSegment::Engaged,
            $daysSinceLastEvent > $s['fading_min_days'] && $daysSinceLastEvent <= $s['fading_max_days'] && ($engagementScore >= $s['fading_min_engagement'] || $intentScore >= $s['fading_min_intent']) => FollowerSegment::Fading,
            default => FollowerSegment::Inactive,
        };
    }
}
