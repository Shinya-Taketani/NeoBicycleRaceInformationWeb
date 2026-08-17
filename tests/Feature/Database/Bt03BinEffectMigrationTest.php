<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Models\BacktestRun;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class Bt03BinEffectMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_the_bt03_table_and_all_required_columns_are_added(): void
    {
        $this->assertTrue(Schema::hasTable('backtest_bin_effects'));
        $this->assertTrue(Schema::hasColumns('backtest_bin_effects', [
            'id', 'backtest_run_id', 'backtest_fold_id', 'backtest_signal_spec_id',
            'source_backtest_run_id', 'source_backtest_fold_id', 'source_baseline_model_id',
            'source_incremental_model_id', 'source_backtest_effect_bin_id', 'cohort_code', 'label_code',
            'bin_index', 'bin_origin', 'bin_kind', 'lower_bound', 'upper_bound', 'category_value',
            'training_sample_count', 'evaluation_status', 'evaluation_sample_count', 'evaluation_race_count',
            'positive_count', 'observed_rate', 'observed_rate_ci_lower', 'observed_rate_ci_upper',
            'baseline_mean_probability', 'incremental_mean_probability', 'baseline_residual_mean',
            'baseline_residual_ci_lower', 'baseline_residual_ci_upper', 'incremental_residual_mean',
            'incremental_residual_ci_lower', 'incremental_residual_ci_upper', 'probability_shift_mean',
            'probability_shift_ci_lower', 'probability_shift_ci_upper', 'log_loss_delta',
            'log_loss_delta_ci_lower', 'log_loss_delta_ci_upper', 'brier_delta', 'brier_delta_ci_lower',
            'brier_delta_ci_upper', 'bootstrap_iterations', 'bootstrap_seed', 'boundaries_hash',
            'effect_hash', 'calculated_at', 'created_at', 'updated_at',
        ]));
        $bt03Tables = array_values(array_filter(array_map(
            fn (string $table): string => str_contains($table, '.') ? substr($table, strrpos($table, '.') + 1) : $table,
            Schema::getTableListing(),
        ), fn (string $table): bool => str_starts_with($table, 'backtest_bin_effect')));
        $this->assertSame(['backtest_bin_effects'], $bt03Tables);
    }

    public function test_all_foreign_keys_reference_backtest_tables_and_required_indexes_exist(): void
    {
        foreach ($this->foreignTables('backtest_bin_effects') as $foreignTable) {
            $this->assertStringStartsWith('backtest_', $foreignTable);
        }
        $this->assertSame([
            'backtest_effect_bins',
            'backtest_folds',
            'backtest_models',
            'backtest_runs',
            'backtest_signal_specs',
        ], $this->foreignTables('backtest_bin_effects'));

        $indexes = collect(Schema::getIndexes('backtest_bin_effects'))->pluck('name')->all();
        $this->assertContains('bt_bin_effects_run_fold_spec_cohort_label_bin_unique', $indexes);
        $this->assertContains('bt_bin_effects_query_index', $indexes);
        $this->assertContains('bt_bin_effects_source_index', $indexes);
        $this->assertContains('backtest_bin_effects_effect_hash_index', $indexes);
    }

    public function test_down_drops_only_bt03_table_and_preserves_existing_rows_and_schema(): void
    {
        $run = BacktestRun::query()->create([
            'run_uuid' => '00000000-0000-4000-8000-000000000301',
            'backtest_code' => 'BT-02',
            'calculation_version' => 'test',
            'status' => 'SUCCEEDED',
            'holdout_policy' => 'BLOCK_AFTER_2025-12-31',
            'source_manifest_version' => 'test',
            'source_manifest_hash' => str_repeat('a', 64),
            'prediction_rule_version' => 'test',
            'parameters' => [],
            'started_at' => now(),
        ]);
        $existingTables = [
            'backtest_runs', 'backtest_folds', 'backtest_models', 'backtest_signal_metrics', 'backtest_effect_bins',
            'statistic_feature_runs', 'players', 'races', 'race_entries', 'race_results', 'race_payouts', 'scraping_fetch_logs',
        ];
        $before = $this->schema($existingTables);
        $migration = $this->migration();

        try {
            $migration->down();
            $this->assertFalse(Schema::hasTable('backtest_bin_effects'));
            $this->assertSame($before, $this->schema($existingTables));
            $this->assertSame($run->id, BacktestRun::query()->findOrFail($run->id)->id);
        } finally {
            $migration->up();
        }

        $this->assertSame($before, $this->schema($existingTables));
        $this->assertSame($run->id, BacktestRun::query()->findOrFail($run->id)->id);
    }

    public function test_postgresql_has_all_bt03_check_constraints(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL constraint test.');
        }
        $constraints = DB::table('information_schema.table_constraints')
            ->where('table_name', 'backtest_bin_effects')
            ->where('constraint_type', 'CHECK')
            ->pluck('constraint_name')
            ->all();

        foreach ([
            'bt_bin_effects_status_check',
            'bt_bin_effects_origin_check',
            'bt_bin_effects_kind_check',
            'bt_bin_effects_origin_shape_check',
            'bt_bin_effects_bin_shape_check',
            'bt_bin_effects_observation_check',
            'bt_bin_effects_bootstrap_check',
            'bt_bin_effects_probability_check',
            'bt_bin_effects_ci_order_check',
            'bt_bin_effects_value_presence_check',
        ] as $constraint) {
            $this->assertContains($constraint, $constraints);
        }
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

    /** @param list<string> $tables @return array<string, array{columns: list<string>, indexes: array<int, mixed>}> */
    private function schema(array $tables): array
    {
        $schema = [];
        foreach ($tables as $table) {
            $schema[$table] = ['columns' => Schema::getColumnListing($table), 'indexes' => Schema::getIndexes($table)];
        }

        return $schema;
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_08_18_000010_create_bt03_bin_effect_tables.php');
    }
}
