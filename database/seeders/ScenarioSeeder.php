<?php

namespace Database\Seeders;

use App\Services\ScenarioService;
use Illuminate\Database\Seeder;

class ScenarioSeeder extends Seeder
{
    /**
     * Seed the three 2026 forecast scenarios previously hardcoded
     * in GenerateForecastReportCommand::buildScenarios().
     */
    public function run(ScenarioService $service): void
    {
        $service->createWithAssumptions(
            [
                'name' => 'conservative',
                'label' => 'Voorzichtig',
                'year' => 2026,
                'description' => '2 kwartalen op 70%, 1 piekmoment. PWK repeat rate ~20%, AOV €85.',
            ],
            [
                'Q2' => ['acq_rate' => 0.70, 'repeat_rate' => 0.20, 'repeat_aov' => 85],
                'Q3' => ['acq_rate' => 0.70, 'repeat_rate' => 0.20, 'repeat_aov' => 85],
                'Q4' => ['acq_rate' => 1.00, 'repeat_rate' => 0.20, 'repeat_aov' => 85],
            ],
        );

        $service->createWithAssumptions(
            [
                'name' => 'base',
                'label' => 'Medium',
                'year' => 2026,
                'description' => '2 kwartalen op 85%, 2 piekmomenten. PWK repeat ~25%, AOV €95.',
            ],
            [
                'Q2' => ['acq_rate' => 0.85, 'repeat_rate' => 0.25, 'repeat_aov' => 95],
                'Q3' => ['acq_rate' => 0.85, 'repeat_rate' => 0.25, 'repeat_aov' => 95],
                'Q4' => ['acq_rate' => 1.08, 'repeat_rate' => 0.25, 'repeat_aov' => 95],
            ],
        );

        $service->createWithAssumptions(
            [
                'name' => 'ambitious',
                'label' => 'Best Case',
                'year' => 2026,
                'description' => 'Elk kwartaal op/boven Q1, 3 piekmomenten. Repeat bouwt op van 22% naar 32%, AOV €95-120.',
            ],
            [
                'Q2' => ['acq_rate' => 1.08, 'repeat_rate' => 0.22, 'repeat_aov' => 95],
                'Q3' => ['acq_rate' => 1.00, 'repeat_rate' => 0.28, 'repeat_aov' => 110],
                'Q4' => ['acq_rate' => 1.20, 'repeat_rate' => 0.32, 'repeat_aov' => 120],
            ],
        );
    }
}
