<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One-time migration seeder: copies all data from local SQLite to PostgreSQL.
 *
 * Reads from the 'sqlite' connection, writes to the default (pgsql) connection.
 * Disables foreign key checks during import and re-enables after.
 * Processes tables in dependency order to respect foreign key constraints.
 */
class SqliteToPostgresSeeder extends Seeder
{
    /**
     * Tables to skip (Laravel infrastructure or auto-managed).
     */
    private const SKIP_TABLES = [
        'migrations',
        'cache',
        'cache_locks',
        'jobs',
        'job_batches',
        'failed_jobs',
        'sessions',
        'password_reset_tokens',
        'personal_access_tokens',
    ];

    /**
     * Tables in dependency order (parents before children).
     * Tables not listed here are processed after these, alphabetically.
     */
    private const ORDERED_TABLES = [
        'users',
        'shopify_customers',
        'shopify_products',
        'products',
        'shopify_orders',
        'shopify_line_items',
        'product_stock_snapshots',
        'product_boms',
        'product_bom_lines',
        'open_purchase_orders',
        'ad_spends',
        'ad_spend_records',
        'klaviyo_profiles',
        'klaviyo_campaigns',
        'rider_profiles',
        'segment_transitions',
        'scenarios',
        'scenario_assumptions',
        'scenario_product_mixes',
        'seasonal_indices',
        'demand_events',
        'demand_event_categories',
        'forecast_snapshots',
        'purchase_calendar_runs',
        'purchase_calendar_events',
        'supply_profiles',
        'sync_states',
        'objectives',
        'key_results',
    ];

    public function run(): void
    {
        $sqlitePath = database_path('database.sqlite');

        if (! file_exists($sqlitePath)) {
            $this->command->error('SQLite database not found at: '.$sqlitePath);

            return;
        }

        // Configure a temporary SQLite connection pointing to the existing file
        config(['database.connections.sqlite_source' => [
            'driver' => 'sqlite',
            'database' => $sqlitePath,
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]]);

        $tables = $this->getOrderedTables();
        $totalRows = 0;

        // Disable foreign key checks for bulk import
        DB::statement('SET session_replication_role = replica;');

        foreach ($tables as $table) {
            $count = $this->migrateTable($table);
            $totalRows += $count;
        }

        // Re-enable foreign key checks
        DB::statement('SET session_replication_role = DEFAULT;');

        // Reset sequences to match imported data
        $this->resetSequences($tables);

        $this->command->newLine();
        $this->command->info("Migration complete: {$totalRows} rows across ".count($tables).' tables.');
    }

    /**
     * Get tables in dependency order, adding any unlisted tables at the end.
     *
     * @return array<string>
     */
    private function getOrderedTables(): array
    {
        $allTables = array_map(
            fn (string $name) => str_contains($name, '.') ? substr($name, strpos($name, '.') + 1) : $name,
            Schema::connection('sqlite_source')->getTableListing(),
        );

        // Filter out skip tables and SQLite internals
        $allTables = array_filter($allTables, function (string $table) {
            return ! in_array($table, self::SKIP_TABLES)
                && ! str_starts_with($table, 'sqlite_');
        });

        $ordered = [];

        // First: tables in explicit order
        foreach (self::ORDERED_TABLES as $table) {
            if (in_array($table, $allTables)) {
                $ordered[] = $table;
            }
        }

        // Then: any remaining tables not yet included
        foreach ($allTables as $table) {
            if (! in_array($table, $ordered)) {
                $ordered[] = $table;
            }
        }

        return $ordered;
    }

    /**
     * Copy all rows from a SQLite table to PostgreSQL in chunks.
     */
    private function migrateTable(string $table): int
    {
        if (! Schema::hasTable($table)) {
            $this->command->warn("  Skipping {$table}: table does not exist in PostgreSQL.");

            return 0;
        }

        $sourceCount = DB::connection('sqlite_source')->table($table)->count();

        if ($sourceCount === 0) {
            return 0;
        }

        // Truncate target table to allow re-running
        DB::table($table)->truncate();

        $chunkSize = 500;
        $migrated = 0;

        DB::connection('sqlite_source')
            ->table($table)
            ->orderBy((DB::connection('sqlite_source')->getSchemaBuilder()->getColumns($table)[0]['name'] ?? 'rowid'))
            ->chunk($chunkSize, function ($rows) use ($table, &$migrated) {
                $records = $rows->map(fn ($row) => (array) $row)->all();
                DB::table($table)->insert($records);
                $migrated += count($records);
            });

        $this->command->line("  ✓ {$table}: {$migrated} rows");

        return $migrated;
    }

    /**
     * Reset PostgreSQL auto-increment sequences to match the max ID in each table.
     *
     * @param  array<string>  $tables
     */
    private function resetSequences(array $tables): void
    {
        foreach ($tables as $table) {
            // Query pg_catalog for actual sequences tied to this table
            $sequences = DB::select("
                SELECT column_name, pg_get_serial_sequence(?, column_name) AS seq_name
                FROM information_schema.columns
                WHERE table_name = ? AND table_schema = 'public'
            ", [$table, $table]);

            foreach ($sequences as $seq) {
                if (! $seq->seq_name) {
                    continue;
                }

                $maxId = DB::table($table)->max($seq->column_name);

                if ($maxId !== null) {
                    DB::statement('SELECT setval(?, ?, true)', [$seq->seq_name, $maxId]);
                    $this->command->line("  ↻ {$table}.{$seq->column_name} sequence → {$maxId}");
                }
            }
        }
    }
}
