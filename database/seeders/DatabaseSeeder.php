<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Order matters: Scenarios before ProductMix and RegionalScenario.
     * All seeders are idempotent — safe to run multiple times.
     */
    public function run(): void
    {
        $this->call([
            UserSeeder::class,
            SupplyProfileSeeder::class,
            ScenarioSeeder::class,
            ScenarioProductMixSeeder::class,
            RegionalScenarioSeeder::class,
            DemandEventSeeder::class,
        ]);
    }
}
