<?php

namespace App\Services;

use App\Models\KeyResult;
use App\Models\Objective;
use Illuminate\Support\Collection;

class GoalService
{
    public function __construct(
        private DashboardService $dashboard,
        private ForecastService $forecast,
        private ScenarioService $scenarioService,
    ) {}

    /**
     * Full OKR tree for a year: company objectives → KRs → team objectives → KRs.
     *
     * @return Collection<int, Objective>
     */
    public function dashboard(int $year): Collection
    {
        return Objective::query()
            ->company()
            ->forYear($year)
            ->with([
                'keyResults.childObjective.keyResults',
            ])
            ->orderBy('sort_order')
            ->get();
    }

    /**
     * Update current_value for an auto-tracked key result based on its metric_key.
     */
    public function updateProgress(KeyResult $keyResult): void
    {
        if (! $keyResult->isAutoTracked()) {
            return;
        }

        $value = $this->resolveMetricValue($keyResult->metric_key, $keyResult->quarter);

        if ($value !== null) {
            $keyResult->update(['current_value' => $value]);
        }
    }

    /**
     * Bulk refresh all auto-tracked key results for a given year.
     */
    public function refreshAll(int $year): int
    {
        $keyResults = KeyResult::query()
            ->where('tracking_mode', 'auto')
            ->whereNotNull('metric_key')
            ->whereHas('objective', fn ($q) => $q->where('year', $year))
            ->get();

        $updated = 0;
        foreach ($keyResults as $kr) {
            $value = $this->resolveMetricValue($kr->metric_key, $kr->quarter);
            if ($value !== null) {
                $kr->update(['current_value' => $value]);
                $updated++;
            }
        }

        return $updated;
    }

    /**
     * Company objectives with rollup progress (average of their KR progress values).
     *
     * @return array<int, array{objective: Objective, progress: float, key_results: array}>
     */
    public function companyOverview(int $year): array
    {
        $objectives = Objective::query()
            ->company()
            ->forYear($year)
            ->with('keyResults')
            ->orderBy('sort_order')
            ->get();

        return $objectives->map(function (Objective $objective) {
            $krs = $objective->keyResults->map(fn (KeyResult $kr) => [
                'id' => $kr->id,
                'title' => $kr->title,
                'target' => (float) $kr->target_value,
                'current' => (float) ($kr->current_value ?? 0),
                'unit' => $kr->unit,
                'progress' => $kr->progress(),
            ]);

            $avgProgress = $krs->count() > 0 ? $krs->avg('progress') : 0.0;

            return [
                'objective' => $objective,
                'progress' => round($avgProgress, 3),
                'key_results' => $krs->toArray(),
            ];
        })->toArray();
    }

    /**
     * Compare OKR targets with forecast scenario projections.
     *
     * For each auto-tracked KR, find which scenario's projection is closest to the target.
     *
     * @return array<int, array{key_result: string, target: float, closest_scenario: string, scenario_value: float, current_pace_matches: string}>
     */
    public function compareWithScenarios(int $year): array
    {
        $scenarios = $this->scenarioService->forYear($year);

        if ($scenarios->isEmpty()) {
            return [];
        }

        $projections = [];
        foreach ($scenarios as $scenario) {
            $input = $this->scenarioService->toForecastInput($scenario);
            $result = $this->forecast->calculateScenario($input);
            $projections[$scenario->name] = $result['totals'];
        }

        $keyResults = KeyResult::query()
            ->where('tracking_mode', 'auto')
            ->whereNotNull('metric_key')
            ->whereHas('objective', fn ($q) => $q->where('year', $year))
            ->get();

        $comparisons = [];
        foreach ($keyResults as $kr) {
            $scenarioKey = $this->metricToScenarioKey($kr->metric_key);
            if ($scenarioKey === null) {
                continue;
            }

            $target = (float) $kr->target_value;
            $closestName = null;
            $closestValue = null;
            $closestDiff = PHP_FLOAT_MAX;

            foreach ($projections as $name => $totals) {
                $value = $totals[$scenarioKey] ?? null;
                if ($value === null) {
                    continue;
                }

                $diff = abs($value - $target);
                if ($diff < $closestDiff) {
                    $closestDiff = $diff;
                    $closestName = $name;
                    $closestValue = $value;
                }
            }

            // Determine which scenario the current pace matches
            $currentPaceMatches = null;
            if ($kr->current_value !== null) {
                $yearProgress = $this->yearProgressFraction();
                $annualized = $yearProgress > 0 ? (float) $kr->current_value / $yearProgress : 0;

                $paceDiff = PHP_FLOAT_MAX;
                foreach ($projections as $name => $totals) {
                    $value = $totals[$scenarioKey] ?? null;
                    if ($value === null) {
                        continue;
                    }

                    $diff = abs($value - $annualized);
                    if ($diff < $paceDiff) {
                        $paceDiff = $diff;
                        $currentPaceMatches = $name;
                    }
                }
            }

            $comparisons[] = [
                'key_result' => $kr->title,
                'target' => $target,
                'closest_scenario' => $closestName,
                'scenario_value' => $closestValue,
                'current_pace_matches' => $currentPaceMatches,
            ];
        }

        return $comparisons;
    }

    /**
     * Resolve the current actual value for a given metric key.
     */
    private function resolveMetricValue(string $metricKey, ?string $quarter): ?float
    {
        $year = (int) date('Y');
        [$from, $to] = $quarter
            ? $this->quarterDates($year, $quarter)
            : [$year.'-01-01', ($year + 1).'-01-01'];

        return match ($metricKey) {
            'revenue' => (float) $this->forecast->periodActuals($from, $to)['total_rev'],
            'acq_revenue' => (float) $this->forecast->periodActuals($from, $to)['acq_rev'],
            'rep_revenue' => (float) $this->forecast->periodActuals($from, $to)['rep_rev'],
            'new_customers' => (float) $this->forecast->periodActuals($from, $to)['new_customers'],
            'repeat_orders' => (float) $this->forecast->periodActuals($from, $to)['repeat_orders'],
            'repeat_rate' => $this->calculateRepeatRate($from, $to),
            default => null,
        };
    }

    /**
     * Map a metric_key to the corresponding key in ForecastService totals.
     */
    private function metricToScenarioKey(string $metricKey): ?string
    {
        return match ($metricKey) {
            'revenue' => 'total',
            'new_customers' => 'new_cust',
            'acq_revenue' => 'acq_total',
            'rep_revenue' => 'rep_total',
            'repeat_orders' => 'rep_orders',
            default => null,
        };
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function quarterDates(int $year, string $quarter): array
    {
        return match ($quarter) {
            'Q1' => [$year.'-01-01', $year.'-04-01'],
            'Q2' => [$year.'-04-01', $year.'-07-01'],
            'Q3' => [$year.'-07-01', $year.'-10-01'],
            'Q4' => [$year.'-10-01', ($year + 1).'-01-01'],
            default => [$year.'-01-01', ($year + 1).'-01-01'],
        };
    }

    private function calculateRepeatRate(string $from, string $to): float
    {
        $actuals = $this->forecast->periodActuals($from, $to);
        $totalOrders = $actuals['new_customers'] + $actuals['repeat_orders'];

        if ($totalOrders === 0) {
            return 0.0;
        }

        return round($actuals['repeat_orders'] * 100 / $totalOrders, 1);
    }

    private function yearProgressFraction(): float
    {
        $now = now();
        $startOfYear = $now->copy()->startOfYear();
        $endOfYear = $now->copy()->endOfYear();

        return $startOfYear->diffInDays($now) / $startOfYear->diffInDays($endOfYear);
    }
}
