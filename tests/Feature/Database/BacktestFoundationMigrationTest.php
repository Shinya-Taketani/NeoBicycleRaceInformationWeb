<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BacktestFoundationMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_the_six_backtest_tables_and_required_columns_are_added(): void
    {
        foreach ($this->backtestTables() as $table) {
            $this->assertTrue(Schema::hasTable($table), $table);
        }
        $this->assertTrue(Schema::hasColumns('backtest_runs', ['source_manifest_version', 'source_manifest_hash', 'holdout_policy', 'prediction_rule_version']));
        $this->assertTrue(Schema::hasColumns('backtest_predictions', ['feature_run_id', 'feature_result_id', 'source_input_hash', 'prediction_hash', 'locked_at']));
        $this->assertTrue(Schema::hasColumns('backtest_metrics', ['cohort_code', 'metric_code', 'numerator', 'denominator', 'sample_count', 'metric_value']));
    }

    public function test_physical_foreign_keys_reference_only_backtest_tables(): void
    {
        foreach ($this->backtestTables() as $table) {
            foreach ($this->foreignTables($table) as $foreignTable) {
                $this->assertStringStartsWith('backtest_', $foreignTable, "{$table} -> {$foreignTable}");
            }
        }
        $this->assertSame(['backtest_runs'], $this->foreignTables('backtest_folds'));
        $this->assertEqualsCanonicalizing(['backtest_folds', 'backtest_runs'], $this->foreignTables('backtest_predictions'));
    }

    public function test_down_drops_only_backtest_tables_and_preserves_source_schema_indexes_and_rows(): void
    {
        DB::table('races')->insert([
            'source' => 'bt-migration-test', 'external_race_id' => 'kept', 'race_date' => '2024-01-01',
            'race_number' => 1, 'race_type' => 'Ａ級予選', 'entrant_count' => 5, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $sourceSchema = $this->sourceSchema();
        $migration = $this->migration();
        $bt02Migration = require database_path('migrations/2026_08_14_000009_create_bt02_signal_evaluation_tables.php');

        $bt02Migration->down();
        try {
            $migration->down();
            foreach ($this->backtestTables() as $table) {
                $this->assertFalse(Schema::hasTable($table));
            }
            $this->assertSame($sourceSchema, $this->sourceSchema());
            $this->assertSame(1, DB::table('races')->where('external_race_id', 'kept')->count());
        } finally {
            $migration->up();
            $bt02Migration->up();
        }
    }

    /** @return array<string, array{columns: list<string>, indexes: array<int, mixed>}> */
    private function sourceSchema(): array
    {
        $result = [];
        foreach (['races', 'race_entries', 'race_results', 'players', 'statistic_feature_runs', 'statistic_feature_results'] as $table) {
            $result[$table] = ['columns' => Schema::getColumnListing($table), 'indexes' => Schema::getIndexes($table)];
        }

        return $result;
    }

    /** @return list<string> */
    private function foreignTables(string $table): array
    {
        if (DB::getDriverName() === 'sqlite') {
            $tables = array_map(fn (object $row): string => (string) $row->table, DB::select("PRAGMA foreign_key_list('{$table}')"));
        } else {
            $tables = array_map(fn (object $row): string => (string) $row->foreign_table_name, DB::select(
                'SELECT ccu.table_name AS foreign_table_name FROM information_schema.table_constraints tc JOIN information_schema.constraint_column_usage ccu ON ccu.constraint_name = tc.constraint_name AND ccu.constraint_schema = tc.constraint_schema WHERE tc.constraint_type = ? AND tc.table_name = ?',
                ['FOREIGN KEY', $table],
            ));
        }
        sort($tables);

        return array_values(array_unique($tables));
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_08_09_000008_create_backtest_foundation_tables.php');
    }

    /** @return list<string> */
    private function backtestTables(): array
    {
        return ['backtest_runs', 'backtest_folds', 'backtest_feature_sources', 'backtest_predictions', 'backtest_metrics', 'backtest_exclusions'];
    }
}
