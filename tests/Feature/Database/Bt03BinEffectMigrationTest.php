<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Models\BacktestRun;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
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
        sort($bt03Tables);
        $this->assertSame(['backtest_bin_effect_scopes', 'backtest_bin_effects'], $bt03Tables);
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
            'bt_bin_effects_training_count_check',
            'bt_bin_effects_bin_shape_check',
            'bt_bin_effects_observation_check',
            'bt_bin_effects_positive_count_check',
            'bt_bin_effects_bootstrap_check',
            'bt_bin_effects_probability_check',
            'bt_bin_effects_ci_order_check',
            'bt_bin_effects_value_presence_check',
        ] as $constraint) {
            $this->assertContains($constraint, $constraints);
        }
    }

    public function test_postgresql_check_constraints_accept_valid_rows_and_reject_invalid_rows(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL actual constraint test.');
        }
        $ids = $this->postgresFixture();
        $training = $this->validObservedTrainingRow($ids);
        DB::table('backtest_bin_effects')->insert($training);

        $unseen = array_replace($training, [
            'label_code' => 'IS_TOP2',
            'source_backtest_effect_bin_id' => null,
            'bin_index' => 0,
            'bin_origin' => 'UNSEEN_CATEGORY',
            'bin_kind' => 'CATEGORY',
            'lower_bound' => null,
            'upper_bound' => null,
            'category_value' => null,
            'training_sample_count' => 0,
        ]);
        DB::table('backtest_bin_effects')->insert($unseen);

        $this->assertDatabaseHas('backtest_bin_effects', [
            'label_code' => 'IS_WIN',
            'bin_origin' => 'TRAINING_BIN',
            'training_sample_count' => 100,
        ]);
        $this->assertDatabaseHas('backtest_bin_effects', [
            'label_code' => 'IS_TOP2',
            'bin_origin' => 'UNSEEN_CATEGORY',
            'source_backtest_effect_bin_id' => null,
            'training_sample_count' => 0,
        ]);

        $noEvaluation = $this->noEvaluationRow($training, 'EMPTY_VALUE');
        $noEvaluation['baseline_residual_mean'] = 0.1;
        foreach ([
            [array_replace($training, ['label_code' => 'NEGATIVE_POSITIVE', 'positive_count' => -1]), 'bt_bin_effects_positive_count_check'],
            [array_replace($training, ['label_code' => 'TRAINING_ZERO', 'training_sample_count' => 0]), 'bt_bin_effects_training_count_check'],
            [array_replace($unseen, ['label_code' => 'UNSEEN_POSITIVE', 'training_sample_count' => 1]), 'bt_bin_effects_training_count_check'],
            [array_replace($training, ['label_code' => 'NEGATIVE_SEED', 'bootstrap_seed' => -1]), 'bt_bin_effects_bootstrap_check'],
            [$noEvaluation, 'bt_bin_effects_value_presence_check'],
            [array_replace($training, ['label_code' => 'OBSERVED_NULL', 'brier_delta' => null]), 'bt_bin_effects_value_presence_check'],
        ] as [$invalid, $constraint]) {
            $this->assertPostgresRejects($invalid, $constraint);
        }
    }

    /** @return array{target_run: int, target_fold: int, target_spec: int, source_run: int, source_fold: int, baseline_model: int, incremental_model: int, source_bin: int} */
    private function postgresFixture(): array
    {
        $now = now();
        $run = fn (string $uuid, string $code): int => (int) DB::table('backtest_runs')->insertGetId([
            'run_uuid' => $uuid,
            'backtest_code' => $code,
            'calculation_version' => 'test',
            'status' => 'SUCCEEDED',
            'holdout_policy' => 'BLOCK_AFTER_2025-12-31',
            'source_manifest_version' => 'test',
            'source_manifest_hash' => str_repeat('a', 64),
            'prediction_rule_version' => 'test',
            'parameters' => '{}',
            'started_at' => $now,
            'finished_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $targetRun = $run('00000000-0000-4000-8000-000000000311', 'BT-03');
        $sourceRun = $run('00000000-0000-4000-8000-000000000312', 'BT-02');
        $fold = fn (int $runId, string $code): int => (int) DB::table('backtest_folds')->insertGetId([
            'backtest_run_id' => $runId,
            'fold_code' => $code,
            'sequence' => 1,
            'train_from' => '2022-01-01',
            'train_to' => '2022-12-31',
            'evaluation_from' => '2023-01-01',
            'evaluation_to' => '2023-12-31',
            'status' => 'SUCCEEDED',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $targetFold = $fold($targetRun, 'BT03_WF_2023');
        $sourceFold = $fold($sourceRun, 'WF_2023');
        $spec = fn (int $runId): int => (int) DB::table('backtest_signal_specs')->insertGetId([
            'backtest_run_id' => $runId,
            'stat_code' => 'STAT-07',
            'subject_type' => 'ENTRY',
            'analysis_role' => 'ENTRY_INCREMENTAL',
            'primary_feature_code' => 'DELTA_MEAN_RESIDUAL',
            'primary_feature_path' => null,
            'transform_code' => 'IDENTITY',
            'strict_policy_version' => 'test',
            'operational_policy_version' => 'test',
            'operational_allowed_quality_reasons' => '[]',
            'source_manifest_version' => 'test',
            'source_manifest_hash' => str_repeat('b', 64),
            'parameters' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $targetSpec = $spec($targetRun);
        $sourceSpec = $spec($sourceRun);
        $model = fn (string $role): int => (int) DB::table('backtest_models')->insertGetId([
            'backtest_run_id' => $sourceRun,
            'backtest_fold_id' => $sourceFold,
            'backtest_signal_spec_id' => $sourceSpec,
            'model_role' => $role,
            'label_code' => 'IS_WIN',
            'cohort_code' => 'STRICT',
            'training_from' => '2022-01-01',
            'training_to' => '2022-12-31',
            'inner_fit_from' => '2022-01-01',
            'inner_fit_to' => '2022-09-30',
            'inner_validation_from' => '2022-10-01',
            'inner_validation_to' => '2022-12-31',
            'feature_names' => '["STAT01_RACE_SCORE"]',
            'scaler_mean' => '{"STAT01_RACE_SCORE":80}',
            'scaler_sd' => '{"STAT01_RACE_SCORE":10}',
            'lambda_candidates' => '[0.1]',
            'selected_lambda' => 0.1,
            'intercept' => 0.0,
            'coefficients' => '[0.2]',
            'objective_version' => 'test',
            'optimizer_version' => 'test',
            'probability_semantics' => 'ENTRY_BINARY_NOT_RACE_NORMALIZED',
            'convergence_status' => 'CONVERGED_GRADIENT',
            'iterations' => 1,
            'final_objective' => 0.5,
            'model_hash' => str_repeat($role === 'BASELINE_MATCHED' ? 'c' : 'd', 64),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $baseline = $model('BASELINE_MATCHED');
        $incremental = $model('INCREMENTAL');
        $sourceBin = (int) DB::table('backtest_effect_bins')->insertGetId([
            'backtest_run_id' => $sourceRun,
            'backtest_fold_id' => $sourceFold,
            'backtest_signal_spec_id' => $sourceSpec,
            'cohort_code' => 'STRICT',
            'bin_index' => 1,
            'bin_kind' => 'NUMERIC_RANGE',
            'lower_bound' => null,
            'upper_bound' => 0.0,
            'category_value' => null,
            'training_sample_count' => 100,
            'boundaries_hash' => str_repeat('e', 64),
            'metadata' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [
            'target_run' => $targetRun,
            'target_fold' => $targetFold,
            'target_spec' => $targetSpec,
            'source_run' => $sourceRun,
            'source_fold' => $sourceFold,
            'baseline_model' => $baseline,
            'incremental_model' => $incremental,
            'source_bin' => $sourceBin,
        ];
    }

    /** @param array{target_run: int, target_fold: int, target_spec: int, source_run: int, source_fold: int, baseline_model: int, incremental_model: int, source_bin: int} $ids @return array<string, mixed> */
    private function validObservedTrainingRow(array $ids): array
    {
        return [
            'backtest_run_id' => $ids['target_run'],
            'backtest_fold_id' => $ids['target_fold'],
            'backtest_signal_spec_id' => $ids['target_spec'],
            'source_backtest_run_id' => $ids['source_run'],
            'source_backtest_fold_id' => $ids['source_fold'],
            'source_baseline_model_id' => $ids['baseline_model'],
            'source_incremental_model_id' => $ids['incremental_model'],
            'source_backtest_effect_bin_id' => $ids['source_bin'],
            'cohort_code' => 'STRICT',
            'label_code' => 'IS_WIN',
            'bin_index' => 1,
            'bin_origin' => 'TRAINING_BIN',
            'bin_kind' => 'NUMERIC_RANGE',
            'lower_bound' => null,
            'upper_bound' => 0.0,
            'category_value' => null,
            'training_sample_count' => 100,
            'evaluation_status' => 'OBSERVED',
            'evaluation_sample_count' => 20,
            'evaluation_race_count' => 4,
            'positive_count' => 3,
            'observed_rate' => 0.15,
            'observed_rate_ci_lower' => 0.05,
            'observed_rate_ci_upper' => 0.25,
            'baseline_mean_probability' => 0.12,
            'incremental_mean_probability' => 0.14,
            'baseline_residual_mean' => 0.03,
            'baseline_residual_ci_lower' => -0.01,
            'baseline_residual_ci_upper' => 0.07,
            'incremental_residual_mean' => 0.01,
            'incremental_residual_ci_lower' => -0.03,
            'incremental_residual_ci_upper' => 0.05,
            'probability_shift_mean' => 0.02,
            'probability_shift_ci_lower' => 0.0,
            'probability_shift_ci_upper' => 0.04,
            'log_loss_delta' => -0.01,
            'log_loss_delta_ci_lower' => -0.02,
            'log_loss_delta_ci_upper' => 0.0,
            'brier_delta' => -0.005,
            'brier_delta_ci_lower' => -0.01,
            'brier_delta_ci_upper' => 0.0,
            'bootstrap_iterations' => 10,
            'bootstrap_seed' => 1,
            'boundaries_hash' => str_repeat('e', 64),
            'effect_hash' => str_repeat('f', 64),
            'calculated_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /** @param array<string, mixed> $observed @return array<string, mixed> */
    private function noEvaluationRow(array $observed, string $label): array
    {
        return array_replace($observed, array_fill_keys([
            'observed_rate', 'observed_rate_ci_lower', 'observed_rate_ci_upper',
            'baseline_mean_probability', 'incremental_mean_probability',
            'baseline_residual_mean', 'baseline_residual_ci_lower', 'baseline_residual_ci_upper',
            'incremental_residual_mean', 'incremental_residual_ci_lower', 'incremental_residual_ci_upper',
            'probability_shift_mean', 'probability_shift_ci_lower', 'probability_shift_ci_upper',
            'log_loss_delta', 'log_loss_delta_ci_lower', 'log_loss_delta_ci_upper',
            'brier_delta', 'brier_delta_ci_lower', 'brier_delta_ci_upper',
        ], null), [
            'label_code' => $label,
            'evaluation_status' => 'NO_EVALUATION_ROWS',
            'evaluation_sample_count' => 0,
            'evaluation_race_count' => 0,
            'positive_count' => 0,
        ]);
    }

    /** @param array<string, mixed> $row */
    private function assertPostgresRejects(array $row, string $constraint): void
    {
        try {
            DB::transaction(fn () => DB::table('backtest_bin_effects')->insert($row));
            $this->fail("Expected {$constraint} to reject the row.");
        } catch (QueryException $exception) {
            $this->assertStringContainsString($constraint, $exception->getMessage());
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
