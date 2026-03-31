<?php

namespace App\Services\Analysis;

use App\Models\RiderProfile;
use Illuminate\Support\Facades\DB;

class SegmentMovementService
{
    /**
     * Segment transition matrix: from → to with counts.
     *
     * @return array<int, array{from_segment: string, to_segment: string, count: int}>
     */
    public function flowMatrix(string $from, string $to, string $type = 'segment_change'): array
    {
        return DB::select('
            SELECT
                from_segment,
                to_segment,
                COUNT(*) as count
            FROM segment_transitions
            WHERE type = ?
                AND occurred_at >= ? AND occurred_at < ?
                AND from_segment IS NOT NULL
                AND to_segment IS NOT NULL
            GROUP BY from_segment, to_segment
            ORDER BY count DESC
        ', [$type, $from, $to]);
    }

    /**
     * Current segment distribution snapshot from rider_profiles.
     * Works immediately without needing transition history.
     *
     * @return array{followers: array, customers: array}
     */
    public function currentDistribution(): array
    {
        $followers = RiderProfile::query()
            ->where('lifecycle_stage', 'follower')
            ->whereNotNull('segment')
            ->selectRaw('segment, COUNT(*) as count')
            ->groupBy('segment')
            ->orderByDesc('count')
            ->get()
            ->toArray();

        $customers = DB::select('
            SELECT
                sc.rfm_segment as segment,
                COUNT(*) as count
            FROM shopify_customers sc
            WHERE sc.rfm_segment IS NOT NULL
            GROUP BY sc.rfm_segment
            ORDER BY count DESC
        ');

        return [
            'followers' => $followers,
            'customers' => array_map(fn ($r) => ['segment' => $r->segment, 'count' => (int) $r->count], $customers),
        ];
    }

    /**
     * Segment distribution over time, derived from transition history.
     * Returns monthly snapshots showing segment counts.
     *
     * @return array<int, array{month: string, segment: string, net_change: int}>
     */
    public function transitionVolume(int $months = 6): array
    {
        $since = now()->subMonths($months)->startOfMonth()->toDateString();

        return DB::select("
            SELECT
                strftime('%Y-%m', occurred_at) as month,
                type,
                COUNT(*) as transitions
            FROM segment_transitions
            WHERE occurred_at >= ?
            GROUP BY month, type
            ORDER BY month
        ", [$since]);
    }

    /**
     * Risk indicators: flag if at_risk or inactive segments are growing.
     * Compares current distribution to distribution from N days ago using transitions.
     *
     * @return array{at_risk_customers: int, at_risk_pct: float, inactive_followers: int, inactive_followers_pct: float, recent_downgrades: int}
     */
    public function riskIndicators(int $lookbackDays = 30): array
    {
        $since = now()->subDays($lookbackDays)->toDateString();

        // Current at_risk customers
        $atRisk = DB::selectOne("
            SELECT COUNT(*) as count
            FROM shopify_customers
            WHERE rfm_segment = 'at_risk'
        ");

        $totalCustomers = DB::selectOne('
            SELECT COUNT(*) as count
            FROM shopify_customers
            WHERE rfm_segment IS NOT NULL
        ');

        // Current inactive followers
        $inactiveFollowers = DB::selectOne("
            SELECT COUNT(*) as count
            FROM rider_profiles
            WHERE lifecycle_stage = 'follower' AND segment = 'inactive'
        ");

        $totalFollowers = DB::selectOne("
            SELECT COUNT(*) as count
            FROM rider_profiles
            WHERE lifecycle_stage = 'follower' AND segment IS NOT NULL
        ");

        // Recent downgrades (transitions to worse segments)
        $downgrades = DB::selectOne("
            SELECT COUNT(*) as count
            FROM segment_transitions
            WHERE occurred_at >= ?
                AND type = 'segment_change'
                AND to_segment IN ('at_risk', 'inactive', 'fading', 'one_timer')
                AND from_segment NOT IN ('at_risk', 'inactive', 'fading', 'one_timer')
        ", [$since]);

        $totalCust = (int) $totalCustomers->count;
        $totalFoll = (int) $totalFollowers->count;

        return [
            'at_risk_customers' => (int) $atRisk->count,
            'at_risk_pct' => $totalCust > 0 ? round((int) $atRisk->count * 100 / $totalCust, 1) : 0,
            'inactive_followers' => (int) $inactiveFollowers->count,
            'inactive_followers_pct' => $totalFoll > 0 ? round((int) $inactiveFollowers->count * 100 / $totalFoll, 1) : 0,
            'recent_downgrades' => (int) $downgrades->count,
        ];
    }
}
