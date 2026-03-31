<?php

namespace App\Services;

use App\Enums\CustomerSegment;
use App\Models\RiderProfile;
use App\Models\ShopifyCustomer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class RfmScoringService
{
    /**
     * @var list<array{CustomerSegment, callable}>
     */
    private array $segmentRules;

    public function __construct(
        private SegmentTransitionLogger $transitionLogger,
    ) {
        $this->segmentRules = $this->buildSegmentRules();
    }

    /**
     * Calculate RFM scores and persist them.
     *
     * @return array{scored: Collection, cleared: int}
     */
    public function score(): array
    {
        $now = Carbon::now();
        $customerData = $this->fetchCustomerData($now);

        if ($customerData->isEmpty()) {
            $cleared = $this->clearOutOfScopeCustomers(collect(), $now);

            return ['scored' => collect(), 'cleared' => $cleared];
        }

        $rQuintiles = $this->calculateQuintileBoundaries($customerData->pluck('days_since_last')->sort()->values());
        $mQuintiles = $this->calculateQuintileBoundaries($customerData->pluck('total_net_revenue')->sort()->values());

        $scored = $customerData->map(function (object $row) use ($rQuintiles, $mQuintiles) {
            $rScore = $this->scoreByQuintile($row->days_since_last, $rQuintiles, invert: true);
            $fScore = $this->scoreFrequency($row->qualifying_order_count);
            $mScore = $this->scoreByQuintile($row->total_net_revenue, $mQuintiles);
            $segment = $this->assignSegment($rScore, $fScore, $mScore);

            return [
                'customer_id' => $row->customer_id,
                'r_score' => $rScore,
                'f_score' => $fScore,
                'm_score' => $mScore,
                'rfm_segment' => $segment,
            ];
        });

        $this->persistScores($scored, $now);
        $cleared = $this->clearOutOfScopeCustomers($scored->pluck('customer_id'), $now);

        return ['scored' => $scored, 'cleared' => $cleared];
    }

    public function transitionsLogged(): int
    {
        return $this->transitionLogger->loggedCount();
    }

    /**
     * @return Collection<int, object>
     */
    private function fetchCustomerData(Carbon $now): Collection
    {
        return DB::table('shopify_orders')
            ->select([
                'customer_id',
                DB::raw("CAST(julianday('{$now->toDateString()}') - julianday(MAX(ordered_at)) AS INTEGER) as days_since_last"),
                DB::raw('COUNT(*) as qualifying_order_count'),
                DB::raw('ROUND(SUM(net_revenue), 2) as total_net_revenue'),
            ])
            ->whereNotIn('financial_status', ['REFUNDED', 'VOIDED'])
            ->where('net_revenue', '>', 0)
            ->whereNotNull('customer_id')
            ->groupBy('customer_id')
            ->get();
    }

    /**
     * @param  Collection<int, float|int>  $sorted
     * @return array<int, float|int>
     */
    public function calculateQuintileBoundaries(Collection $sorted): array
    {
        $count = $sorted->count();
        $boundaries = [];

        for ($i = 1; $i <= 4; $i++) {
            $index = max(0, (int) floor($count * $i / 5) - 1);
            $boundaries[$i] = $sorted[$index];
        }

        return $boundaries;
    }

    /**
     * @param  array<int, float|int>  $boundaries
     */
    public function scoreByQuintile(float|int $value, array $boundaries, bool $invert = false): int
    {
        if ($invert) {
            if ($value <= $boundaries[1]) {
                return 5;
            }
            if ($value <= $boundaries[2]) {
                return 4;
            }
            if ($value <= $boundaries[3]) {
                return 3;
            }
            if ($value <= $boundaries[4]) {
                return 2;
            }

            return 1;
        }

        if ($value <= $boundaries[1]) {
            return 1;
        }
        if ($value <= $boundaries[2]) {
            return 2;
        }
        if ($value <= $boundaries[3]) {
            return 3;
        }
        if ($value <= $boundaries[4]) {
            return 4;
        }

        return 5;
    }

    public function scoreFrequency(int $orderCount): int
    {
        foreach (config('scoring.rfm.f_breakpoints') as $threshold => $score) {
            if ($orderCount >= $threshold) {
                return $score;
            }
        }

        return 1;
    }

    public function assignSegment(int $rScore, int $fScore, int $mScore): CustomerSegment
    {
        foreach ($this->segmentRules as [$segment, $rule]) {
            if ($rule($rScore, $fScore, $mScore)) {
                return $segment;
            }
        }

        return CustomerSegment::NewCustomer;
    }

    /**
     * @param  Collection<int, array{customer_id: int, r_score: int, f_score: int, m_score: int, rfm_segment: CustomerSegment}>  $scored
     */
    private function persistScores(Collection $scored, Carbon $now): void
    {
        $scored->chunk(500)->each(function (Collection $chunk) use ($now) {
            $customerIds = $chunk->pluck('customer_id')->toArray();

            $previousSegments = ShopifyCustomer::whereIn('id', $customerIds)
                ->pluck('rfm_segment', 'id');

            $riderProfiles = RiderProfile::whereIn('shopify_customer_id', $customerIds)
                ->pluck('segment', 'shopify_customer_id')
                ->toArray();

            $profileIds = RiderProfile::whereIn('shopify_customer_id', $customerIds)
                ->pluck('id', 'shopify_customer_id');

            foreach ($chunk as $row) {
                $previousSegment = $previousSegments[$row['customer_id']] ?? null;

                ShopifyCustomer::where('id', $row['customer_id'])
                    ->update([
                        'r_score' => $row['r_score'],
                        'f_score' => $row['f_score'],
                        'm_score' => $row['m_score'],
                        'rfm_segment' => $row['rfm_segment'],
                        'previous_rfm_segment' => $previousSegment,
                        'rfm_scored_at' => $now,
                    ]);

                $profileId = $profileIds[$row['customer_id']] ?? null;

                if ($profileId) {
                    $previousRiderSegment = $riderProfiles[$row['customer_id']] ?? null;
                    $newSegmentValue = $row['rfm_segment']->value;

                    $updateData = [
                        'segment' => $newSegmentValue,
                        'previous_segment' => $previousRiderSegment,
                    ];

                    if ($previousRiderSegment !== $newSegmentValue) {
                        $updateData['segment_changed_at'] = $now;
                    }

                    RiderProfile::where('id', $profileId)->update($updateData);

                    $this->transitionLogger->logCustomerSegmentChange($profileId, $previousSegment, $row['rfm_segment']);
                }
            }
        });
    }

    /**
     * @param  Collection<int, int>  $scoredCustomerIds
     */
    private function clearOutOfScopeCustomers(Collection $scoredCustomerIds, Carbon $now): int
    {
        $outOfScope = ShopifyCustomer::query()
            ->whereNotNull('rfm_segment')
            ->whereNotIn('id', $scoredCustomerIds->toArray())
            ->get(['id', 'rfm_segment']);

        if ($outOfScope->isEmpty()) {
            return 0;
        }

        $profileIds = RiderProfile::whereIn('shopify_customer_id', $outOfScope->pluck('id'))
            ->pluck('id', 'shopify_customer_id');

        foreach ($outOfScope as $customer) {
            $profileId = $profileIds[$customer->id] ?? null;

            if ($profileId) {
                $this->transitionLogger->logCustomerSegmentChange($profileId, $customer->rfm_segment, null);
            }
        }

        ShopifyCustomer::whereIn('id', $outOfScope->pluck('id'))
            ->update([
                'r_score' => null,
                'f_score' => null,
                'm_score' => null,
                'rfm_segment' => null,
                'previous_rfm_segment' => null,
                'rfm_scored_at' => $now,
            ]);

        return $outOfScope->count();
    }

    /**
     * @return list<array{CustomerSegment, callable}>
     */
    private function buildSegmentRules(): array
    {
        $rules = [];

        foreach (config('scoring.rfm.segment_rules') as $segmentKey => $conditions) {
            $segment = CustomerSegment::from($segmentKey);

            $rules[] = [$segment, function (int $r, int $f, int $m) use ($conditions): bool {
                $values = ['r' => $r, 'f' => $f, 'm' => $m];

                foreach ($conditions as $dimension => $expression) {
                    if (! $this->evaluateCondition($values[$dimension], $expression)) {
                        return false;
                    }
                }

                return true;
            }];
        }

        return $rules;
    }

    private function evaluateCondition(int $value, string $expression): bool
    {
        if (! preg_match('/^([<>=!]+)\s*(\d+)$/', trim($expression), $matches)) {
            return false;
        }

        $operator = $matches[1];
        $threshold = (int) $matches[2];

        return match ($operator) {
            '>=' => $value >= $threshold,
            '<=' => $value <= $threshold,
            '>' => $value > $threshold,
            '<' => $value < $threshold,
            '=' => $value === $threshold,
            '!=' => $value !== $threshold,
            default => false,
        };
    }
}
