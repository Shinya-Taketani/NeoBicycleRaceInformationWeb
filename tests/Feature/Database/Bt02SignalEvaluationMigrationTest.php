<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Domain\Keirin\Backtest\Repositories\Bt02AuditRepository;
use App\Domain\Keirin\Backtest\Services\Bt02SignalRegistry;
use App\Models\BacktestFold;
use App\Models\BacktestModel;
use App\Models\BacktestRun;
use App\Models\BacktestSignalMetric;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Tests\TestCase;

class Bt02SignalEvaluationMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_exactly_the_four_bt02_tables_and_required_columns_exist(): void
    {
        $this->assertSame([
            'backtest_effect_bins',
            'backtest_models',
            'backtest_signal_metrics',
            'backtest_signal_specs',
        ], $this->bt02Tables());
        $this->assertTrue(Schema::hasColumns('backtest_signal_specs', ['stat_code', 'analysis_role', 'primary_feature_path', 'source_manifest_hash']));
        $this->assertTrue(Schema::hasColumns('backtest_models', ['inner_fit_from', 'inner_validation_to', 'selected_lambda', 'coefficients', 'model_hash', 'prediction_manifest_hash']));
        $this->assertTrue(Schema::hasColumns('backtest_signal_metrics', ['baseline_value', 'incremental_value', 'delta_value', 'ci_lower', 'ci_upper', 'bootstrap_seed']));
        $this->assertTrue(Schema::hasColumns('backtest_effect_bins', ['bin_kind', 'lower_bound', 'upper_bound', 'category_value', 'boundaries_hash']));
    }

    public function test_bt02_foreign_keys_reference_only_existing_backtest_tables(): void
    {
        foreach ($this->bt02Tables() as $table) {
            foreach ($this->foreignTables($table) as $foreignTable) {
                $this->assertStringStartsWith('backtest_', $foreignTable, "{$table} -> {$foreignTable}");
            }
        }
    }

    public function test_down_and_up_change_no_existing_bt01_statistics_or_scraping_schema(): void
    {
        $existingTables = [
            'backtest_runs', 'backtest_folds', 'backtest_feature_sources', 'backtest_predictions', 'backtest_metrics', 'backtest_exclusions',
            'statistic_feature_runs', 'statistic_feature_run_items', 'statistic_feature_results',
            'players', 'races', 'race_entries', 'race_results', 'race_payouts', 'scraping_fetch_logs',
        ];
        $before = $this->schema($existingTables);
        $migration = $this->migration();

        try {
            $migration->down();
            foreach (['backtest_signal_specs', 'backtest_models', 'backtest_signal_metrics', 'backtest_effect_bins'] as $table) {
                $this->assertFalse(Schema::hasTable($table));
            }
            $this->assertSame($before, $this->schema($existingTables));
        } finally {
            $migration->up();
        }
        $this->assertSame($before, $this->schema($existingTables));
    }

    public function test_audit_repository_persists_the_fixed_signal_contract(): void
    {
        $run = BacktestRun::query()->create([
            'run_uuid' => '00000000-0000-4000-8000-000000000201', 'backtest_code' => 'BT-02',
            'calculation_version' => 'BT02-foundation-v1', 'status' => 'RUNNING', 'holdout_policy' => 'BLOCK_AFTER_2025-12-31',
            'source_manifest_version' => 'BT02-STAT-SOURCE-MANIFEST-v1', 'source_manifest_hash' => str_repeat('a', 64),
            'prediction_rule_version' => 'INCREMENTAL_SIGNAL_REFERENCE_MODEL', 'parameters' => [], 'started_at' => now(),
        ]);
        $fold = BacktestFold::query()->create([
            'backtest_run_id' => $run->id, 'fold_code' => 'WF_2023', 'sequence' => 1,
            'train_from' => '2022-01-01', 'train_to' => '2022-12-31', 'evaluation_from' => '2023-01-01', 'evaluation_to' => '2023-12-31',
            'status' => 'RUNNING', 'started_at' => now(),
        ]);
        $repository = new Bt02AuditRepository;
        $startedRun = $repository->startRun([
            'source_fingerprint_version' => 'caller-controlled',
            'content_fingerprint_version' => 'caller-controlled',
        ]);
        $spec = $repository->storeSignalSpec($run, (new Bt02SignalRegistry)->get('STAT-10'));
        $metric = $repository->storeMetric($run, $fold, $spec, [
            'backtest_run_id' => $startedRun->id,
            'backtest_fold_id' => 999999,
            'backtest_signal_spec_id' => 999999,
            'label_code' => 'TOP1',
            'cohort_code' => 'STRICT',
            'metric_code' => 'LOG_LOSS',
            'baseline_value' => 0.5,
            'incremental_value' => 0.4,
            'delta_value' => -0.1,
            'sample_count' => 100,
            'race_count' => 20,
            'calculated_at' => '2000-01-01 00:00:00+00',
        ]);

        $this->assertSame('features.SUMMARY.mean_residual_3_minus_10', $spec->primary_feature_path);
        $this->assertSame(['IN_MEETING_RESULT_CONFIRMATION_NOT_RECONSTRUCTED'], $spec->operational_allowed_quality_reasons);
        $this->assertSame($run->id, $spec->backtest_run_id);
        $this->assertSame('BT02-SOURCE-FINGERPRINT-v1', $startedRun->parameters['source_fingerprint_version']);
        $this->assertSame('BT02-SOURCE-CONTENT-FINGERPRINT-v1-PG18.4', $startedRun->parameters['content_fingerprint_version']);
        $this->assertSame($run->id, $metric->backtest_run_id);
        $this->assertSame($fold->id, $metric->backtest_fold_id);
        $this->assertSame($spec->id, $metric->backtest_signal_spec_id);
        $this->assertNotSame('2000-01-01', $metric->calculated_at?->format('Y-m-d'));
    }

    public function test_postgresql_constraints_reject_invalid_model_role_and_bin_shape(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL constraint test.');
        }
        $constraints = DB::table('information_schema.table_constraints')
            ->whereIn('table_name', $this->bt02Tables())
            ->where('constraint_type', 'CHECK')
            ->pluck('constraint_name')
            ->all();

        $this->assertContains('bt_signal_specs_role_check', $constraints);
        $this->assertContains('bt_models_role_check', $constraints);
        $this->assertContains('bt_models_date_check', $constraints);
        $this->assertContains('bt_effect_bins_shape_check', $constraints);
    }

    public function test_audit_repository_rejects_cross_run_fold_and_spec_ownership_before_writing(): void
    {
        $repository = new Bt02AuditRepository;
        $runA = $repository->startRun(['test' => 'ownership-a']);
        $runB = $repository->startRun(['test' => 'ownership-b']);
        $foldA = $this->createFold($runA, 'WF_A');
        $foldB = $this->createFold($runB, 'WF_B');
        $specA = $repository->storeSignalSpec($runA, (new Bt02SignalRegistry)->get('STAT-10'));
        $specB = $repository->storeSignalSpec($runB, (new Bt02SignalRegistry)->get('STAT-10'));
        $counts = [
            BacktestModel::query()->count(),
            BacktestSignalMetric::query()->count(),
            DB::table('backtest_effect_bins')->count(),
        ];

        foreach ([
            fn () => $repository->storeModel($runA, $foldB, $specA, []),
            fn () => $repository->storeModel($runA, $foldA, $specB, []),
            fn () => $repository->storeMetric($runA, $foldB, $specA, []),
            fn () => $repository->storeMetric($runA, $foldA, $specB, []),
            fn () => $repository->storeEffectBins($runA, $foldB, $specA, 'STRICT', str_repeat('a', 64), []),
            fn () => $repository->storeEffectBins($runA, $foldA, $specB, 'STRICT', str_repeat('a', 64), []),
        ] as $write) {
            $this->assertCallbackThrows($write, LogicException::class);
        }

        $this->assertSame($counts, [
            BacktestModel::query()->count(),
            BacktestSignalMetric::query()->count(),
            DB::table('backtest_effect_bins')->count(),
        ]);
    }

    public function test_paired_artifact_transaction_rolls_back_models_and_metrics_on_late_metric_failure(): void
    {
        $repository = new Bt02AuditRepository;
        $run = $repository->startRun(['test' => 'atomic-rollback']);
        $fold = $this->createFold($run, 'WF_ATOMIC');
        $spec = $repository->storeSignalSpec($run, (new Bt02SignalRegistry)->get('STAT-10'));
        $models = [
            $this->modelArtifact('BASELINE_MATCHED', ['STAT01_RACE_SCORE']),
            $this->modelArtifact('INCREMENTAL', ['STAT01_RACE_SCORE', 'STAT10_SIGNAL']),
        ];
        $metrics = [
            $this->metricArtifact('AUC'),
            $this->metricArtifact('LOG_LOSS'),
            $this->metricArtifact('LOG_LOSS'),
        ];

        $this->assertCallbackThrows(
            fn () => $repository->storePairedEvaluationArtifacts($run, $fold, $spec, $models, $metrics),
            \Throwable::class,
        );

        $this->assertSame(0, BacktestModel::query()->where('backtest_fold_id', $fold->id)->count());
        $this->assertSame(0, BacktestSignalMetric::query()->where('backtest_fold_id', $fold->id)->count());
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

    /** @return list<string> */
    private function bt02Tables(): array
    {
        return array_values(array_filter(
            ['backtest_effect_bins', 'backtest_models', 'backtest_signal_metrics', 'backtest_signal_specs'],
            fn (string $table): bool => Schema::hasTable($table),
        ));
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
        return require database_path('migrations/2026_08_14_000009_create_bt02_signal_evaluation_tables.php');
    }

    private function createFold(BacktestRun $run, string $code): BacktestFold
    {
        return BacktestFold::query()->create([
            'backtest_run_id' => $run->id,
            'fold_code' => $code,
            'sequence' => 1,
            'train_from' => '2022-01-01',
            'train_to' => '2022-12-31',
            'evaluation_from' => '2023-01-01',
            'evaluation_to' => '2023-12-31',
            'status' => 'RUNNING',
            'started_at' => now(),
        ]);
    }

    /** @param list<string> $features @return array<string, mixed> */
    private function modelArtifact(string $role, array $features): array
    {
        return [
            'model_role' => $role,
            'label_code' => 'IS_WIN',
            'cohort_code' => 'STRICT',
            'training_from' => '2022-01-01',
            'training_to' => '2022-12-31',
            'inner_fit_from' => '2022-01-01',
            'inner_fit_to' => '2022-09-30',
            'inner_validation_from' => '2022-10-01',
            'inner_validation_to' => '2022-12-31',
            'feature_names' => $features,
            'scaler_mean' => array_fill(0, count($features), 0.0),
            'scaler_sd' => array_fill(0, count($features), 1.0),
            'lambda_candidates' => [0.0, 0.1],
            'selected_lambda' => 0.1,
            'intercept' => 0.0,
            'coefficients' => array_fill(0, count($features), 0.1),
            'objective_version' => 'TEST-v1',
            'optimizer_version' => 'TEST-v1',
            'probability_semantics' => 'ENTRY_BINARY_NOT_RACE_NORMALIZED',
            'convergence_status' => 'CONVERGED',
            'iterations' => 1,
            'final_objective' => 1.0,
            'model_hash' => hash('sha256', $role),
            'prediction_manifest_hash' => hash('sha256', $role.'-prediction'),
        ];
    }

    /** @return array<string, mixed> */
    private function metricArtifact(string $code): array
    {
        return [
            'label_code' => 'IS_WIN',
            'cohort_code' => 'STRICT',
            'metric_code' => $code,
            'baseline_value' => 0.5,
            'incremental_value' => 0.4,
            'delta_value' => -0.1,
            'ci_lower' => -0.2,
            'ci_upper' => 0.0,
            'sample_count' => 35,
            'race_count' => 5,
            'bootstrap_iterations' => 2000,
            'bootstrap_seed' => 20260812,
            'metadata' => ['test' => true],
        ];
    }

    /** @param class-string<\Throwable> $exceptionClass */
    private function assertCallbackThrows(callable $callback, string $exceptionClass): void
    {
        try {
            $callback();
            $this->fail("Expected {$exceptionClass} was not thrown.");
        } catch (\Throwable $exception) {
            $this->assertInstanceOf($exceptionClass, $exception);
        }
    }
}
