<?php

namespace App\Services;

use App\Enums\CustomerSegment;
use App\Enums\FollowerSegment;
use App\Enums\LifecycleStage;
use App\Models\SegmentTransition;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class SegmentTransitionLogger
{
    protected int $loggedCount = 0;

    /**
     * Log a follower segment change.
     */
    public function logSegmentChange(
        int $riderProfileId,
        ?FollowerSegment $from,
        ?FollowerSegment $to,
    ): void {
        if ($from === $to) {
            return;
        }

        SegmentTransition::create([
            'rider_profile_id' => $riderProfileId,
            'type' => 'segment_change',
            'from_segment' => $from?->value,
            'to_segment' => $to?->value,
            'occurred_at' => Carbon::now(),
        ]);

        $this->loggedCount++;
    }

    /**
     * Log a customer RFM segment change.
     */
    public function logCustomerSegmentChange(
        int $riderProfileId,
        ?CustomerSegment $from,
        ?CustomerSegment $to,
    ): void {
        if ($from === $to) {
            return;
        }

        SegmentTransition::create([
            'rider_profile_id' => $riderProfileId,
            'type' => 'segment_change',
            'from_segment' => $from?->value,
            'to_segment' => $to?->value,
            'occurred_at' => Carbon::now(),
        ]);

        $this->loggedCount++;
    }

    /**
     * Log a lifecycle stage change (follower → customer).
     */
    public function logLifecycleChange(
        int $riderProfileId,
        LifecycleStage $from,
        LifecycleStage $to,
        ?string $lastFollowerSegment = null,
    ): void {
        if ($from === $to) {
            return;
        }

        SegmentTransition::create([
            'rider_profile_id' => $riderProfileId,
            'type' => 'lifecycle_change',
            'from_lifecycle' => $from->value,
            'to_lifecycle' => $to->value,
            'from_segment' => $lastFollowerSegment,
            'occurred_at' => Carbon::now(),
        ]);

        $this->loggedCount++;
    }

    public function loggedCount(): int
    {
        return $this->loggedCount;
    }
}
