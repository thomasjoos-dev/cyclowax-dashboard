<?php

namespace App\Console\Commands;

use App\Enums\CustomerSegment;
use App\Models\RiderProfile;
use App\Models\ShopifyCustomer;
use App\Services\SegmentTransitionLogger;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

#[Signature('customers:calculate-rfm')]
#[Description('Calculate RFM scores and assign segments for all customers with qualifying orders')]
class CalculateRfmScoresCommand extends Command
{
    /**
     * F-score breakpoints: custom because 78% of customers have only 1 order.
     * Quintile scoring would give 80% of customers F=1.
     *
     * @var array<int, int>
     */
    private const F_BREAKPOINTS = [
        5 => 5,  // 5+ orders
        3 => 4,  // 3-4 orders
        2 => 3,  // 2 orders
        1 => 1,  // 1 order
    ];

    /**
     * Segment rules applied as a waterfall — first match wins.
     * Order matters: specific rules before broad ones.
     *
     * @var list<array{CustomerSegment, callable}>
     */
    private array $segmentRules;

    public function __construct()
    {
        parent::__construct();

        $this->segmentRules = [
            [CustomerSegment::Champion, fn (int $r, int $f, int $m): bool => $r >= 4 && $f >= 4 && $m >= 4],
            [CustomerSegment::AtRisk, fn (int $r, int $f, int $m): bool => $r <= 2 && $f >= 3 && $m >= 3],
            [CustomerSegment::Rising, fn (int $r, int $f, int $m): bool => $r >= 3 && $f >= 2 && $m >= 3],
            [CustomerSegment::Loyal, fn (int $r, int $f, int $m): bool => $f >= 3 && $m >= 2],
            [CustomerSegment::Hunters, fn (int $r, int $f, int $m): bool => $f >= 3 && $m <= 2],
            [CustomerSegment::PromisingFirst, fn (int $r, int $f, int $m): bool => $f === 1 && $m >= 3],
            [CustomerSegment::OneTimer, fn (int $r, int $f, int $m): bool => $f === 1 && $m <= 2],
        ];
    }

    public function handle(): int
    {
        $this->info('Calculating RFM scores...');

        $now = Carbon::now();
        $transitionLogger = new SegmentTransitionLogger;
        $customerData = $this->fetchCustomerData($now);

        if ($customerData->isEmpty()) {
            $this->warn('No qualifying customers found.');
            $this->clearOutOfScopeCustomers(collect(), $now, $transitionLogger);

            return self::SUCCESS;
        }

        $this->info("  Qualifying customers: {$customerData->count()}");

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

        $this->persistScores($scored, $now, $transitionLogger);
        $this->clearOutOfScopeCustomers($scored->pluck('customer_id'), $now, $transitionLogger);
        $this->printSummary($scored);

        if ($transitionLogger->loggedCount() > 0) {
            $this->info("  Segment transitions logged: {$transitionLogger->loggedCount()}");
        }

        return self::SUCCESS;
    }

    /**
     * Fetch aggregated order data per customer, only counting qualifying orders
     * (net_revenue > 0, not refunded/voided).
     *
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
     * Calculate quintile boundaries for a sorted collection of values.
     *
     * @param  Collection<int, float|int>  $sorted
     * @return array<int, float|int>
     */
    private function calculateQuintileBoundaries(Collection $sorted): array
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
     * Score a value against quintile boundaries.
     * When inverted (recency), lower values get higher scores.
     *
     * @param  array<int, float|int>  $boundaries
     */
    private function scoreByQuintile(float|int $value, array $boundaries, bool $invert = false): int
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

    private function scoreFrequency(int $orderCount): int
    {
        foreach (self::F_BREAKPOINTS as $threshold => $score) {
            if ($orderCount >= $threshold) {
                return $score;
            }
        }

        return 1;
    }

    private function assignSegment(int $rScore, int $fScore, int $mScore): CustomerSegment
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
    private function persistScores(Collection $scored, Carbon $now, SegmentTransitionLogger $transitionLogger): void
    {
        $this->info('  Persisting scores...');

        $scored->chunk(500)->each(function (Collection $chunk) use ($now, $transitionLogger) {
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

                    $transitionLogger->logCustomerSegmentChange($profileId, $previousSegment, $row['rfm_segment']);
                }
            }
        });
    }

    /**
     * Clear RFM scores for customers who no longer have qualifying orders
     * (e.g. all orders refunded since last scoring run).
     *
     * @param  Collection<int, int>  $scoredCustomerIds
     */
    private function clearOutOfScopeCustomers(Collection $scoredCustomerIds, Carbon $now, SegmentTransitionLogger $transitionLogger): void
    {
        $outOfScope = ShopifyCustomer::query()
            ->whereNotNull('rfm_segment')
            ->whereNotIn('id', $scoredCustomerIds->toArray())
            ->get(['id', 'rfm_segment']);

        if ($outOfScope->isEmpty()) {
            return;
        }

        $profileIds = RiderProfile::whereIn('shopify_customer_id', $outOfScope->pluck('id'))
            ->pluck('id', 'shopify_customer_id');

        foreach ($outOfScope as $customer) {
            $profileId = $profileIds[$customer->id] ?? null;

            if ($profileId) {
                $transitionLogger->logCustomerSegmentChange($profileId, $customer->rfm_segment, null);
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

        $this->info("  Cleared {$outOfScope->count()} out-of-scope customers.");
    }

    /**
     * @param  Collection<int, array{customer_id: int, r_score: int, f_score: int, m_score: int, rfm_segment: CustomerSegment}>  $scored
     */
    private function printSummary(Collection $scored): void
    {
        $segments = $scored->groupBy(fn (array $row) => $row['rfm_segment']->value)->map->count()->sortDesc();

        $this->newLine();
        $this->info('  Segment distribution:');

        foreach ($segments as $segment => $count) {
            $pct = round($count / $scored->count() * 100, 1);
            $this->line("    {$segment}: {$count} ({$pct}%)");
        }
    }
}
