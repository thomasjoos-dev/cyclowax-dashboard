<?php

namespace App\Services;

use App\Models\Scenario;
use Illuminate\Support\Collection;

class ScenarioService
{
    /**
     * All active scenarios for a given year, with assumptions eager-loaded.
     *
     * @return Collection<int, Scenario>
     */
    public function forYear(int $year): Collection
    {
        return Scenario::query()
            ->active()
            ->forYear($year)
            ->with('assumptions')
            ->get();
    }

    /**
     * Convert a Scenario's assumptions into the quarters array that ForecastService expects.
     *
     * @return array<string, array{acq_rate: float, repeat_rate: float, repeat_aov: float}>
     */
    public function toForecastInput(Scenario $scenario): array
    {
        $scenario->loadMissing('assumptions');

        $quarters = [];

        foreach ($scenario->assumptions as $assumption) {
            $quarters[$assumption->quarter] = [
                'acq_rate' => (float) $assumption->acq_rate,
                'repeat_rate' => (float) $assumption->repeat_rate,
                'repeat_aov' => (float) $assumption->repeat_aov,
            ];
        }

        return $quarters;
    }

    /**
     * Compare two scenarios side-by-side using ForecastService.
     *
     * @return array{scenario_a: array, scenario_b: array, deltas: array}
     */
    public function compare(Scenario $scenarioA, Scenario $scenarioB, ForecastService $forecast): array
    {
        $resultA = $forecast->calculateScenario($this->toForecastInput($scenarioA));
        $resultB = $forecast->calculateScenario($this->toForecastInput($scenarioB));

        $deltas = [];
        foreach (['new_cust', 'acq_total', 'rep_orders', 'rep_total', 'total'] as $metric) {
            $a = $resultA['totals'][$metric];
            $b = $resultB['totals'][$metric];
            $deltas[$metric] = [
                'a' => $a,
                'b' => $b,
                'diff' => $b - $a,
                'diff_pct' => $a > 0 ? round(($b - $a) * 100 / $a, 1) : 0,
            ];
        }

        return [
            'scenario_a' => $resultA,
            'scenario_b' => $resultB,
            'deltas' => $deltas,
        ];
    }

    /**
     * Create a scenario with its quarterly assumptions in one call.
     *
     * @param  array{name: string, label: string, year: int, description?: string}  $scenarioData
     * @param  array<string, array{acq_rate: float, repeat_rate: float, repeat_aov: float}>  $quarters
     */
    public function createWithAssumptions(array $scenarioData, array $quarters): Scenario
    {
        $scenario = Scenario::create($scenarioData);

        foreach ($quarters as $quarter => $assumptions) {
            $scenario->assumptions()->create([
                'quarter' => $quarter,
                ...$assumptions,
            ]);
        }

        return $scenario->load('assumptions');
    }

    /**
     * Update a scenario and replace its assumptions.
     *
     * @param  array{name?: string, label?: string, year?: int, description?: string}  $scenarioData
     * @param  array<string, array{acq_rate: float, repeat_rate: float, repeat_aov: float}>|null  $quarters
     */
    public function updateWithAssumptions(Scenario $scenario, array $scenarioData, ?array $quarters = null): Scenario
    {
        $scenario->update($scenarioData);

        if ($quarters !== null) {
            $scenario->assumptions()->delete();

            foreach ($quarters as $quarter => $assumptions) {
                $scenario->assumptions()->create([
                    'quarter' => $quarter,
                    ...$assumptions,
                ]);
            }
        }

        return $scenario->load('assumptions');
    }
}
