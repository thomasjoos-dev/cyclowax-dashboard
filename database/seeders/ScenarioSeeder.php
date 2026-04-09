<?php

namespace Database\Seeders;

use App\Models\Scenario;
use App\Services\Forecast\Tracking\ScenarioService;
use Illuminate\Database\Seeder;

class ScenarioSeeder extends Seeder
{
    /**
     * Seed the three 2026 forecast scenarios.
     *
     * Idempotent: updates existing scenarios by name, creates new ones if missing.
     */
    public function run(ScenarioService $service): void
    {
        $scenarios = [
            [
                'data' => [
                    'name' => 'conservative',
                    'label' => 'Voorzichtig',
                    'year' => 2026,
                    'description' => '2 kwartalen op 70%, 1 piekmoment. PWK repeat rate ~20%, AOV €85.',
                ],
                'quarters' => [
                    'Q2' => ['acq_rate' => 0.70, 'repeat_rate' => 0.20, 'repeat_aov' => 85],
                    'Q3' => ['acq_rate' => 0.70, 'repeat_rate' => 0.20, 'repeat_aov' => 85],
                    'Q4' => ['acq_rate' => 1.00, 'repeat_rate' => 0.20, 'repeat_aov' => 85],
                ],
            ],
            [
                'data' => [
                    'name' => 'base',
                    'label' => 'Medium',
                    'year' => 2026,
                    'description' => '2 kwartalen op 85%, 2 piekmomenten. PWK repeat ~25%, AOV €95.',
                ],
                'quarters' => [
                    'Q2' => ['acq_rate' => 0.85, 'repeat_rate' => 0.25, 'repeat_aov' => 95],
                    'Q3' => ['acq_rate' => 0.85, 'repeat_rate' => 0.25, 'repeat_aov' => 95],
                    'Q4' => ['acq_rate' => 1.08, 'repeat_rate' => 0.25, 'repeat_aov' => 95],
                ],
            ],
            [
                'data' => [
                    'name' => 'ambitious',
                    'label' => 'Best Case',
                    'year' => 2026,
                    'description' => 'Elk kwartaal op/boven Q1, 3 piekmomenten. Repeat bouwt op van 22% naar 32%, AOV €95-120.',
                ],
                'quarters' => [
                    'Q2' => ['acq_rate' => 1.08, 'repeat_rate' => 0.22, 'repeat_aov' => 95],
                    'Q3' => ['acq_rate' => 1.00, 'repeat_rate' => 0.28, 'repeat_aov' => 110],
                    'Q4' => ['acq_rate' => 1.20, 'repeat_rate' => 0.32, 'repeat_aov' => 120],
                ],
            ],
        ];

        foreach ($scenarios as $scenarioDef) {
            $existing = Scenario::where('name', $scenarioDef['data']['name'])->first();

            if ($existing) {
                $service->updateWithAssumptions($existing, $scenarioDef['data'], $scenarioDef['quarters']);
            } else {
                $service->createWithAssumptions($scenarioDef['data'], $scenarioDef['quarters']);
            }
        }
    }
}
