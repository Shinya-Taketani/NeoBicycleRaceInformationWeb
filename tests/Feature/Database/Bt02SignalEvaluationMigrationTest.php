<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Domain\Keirin\Backtest\Repositories\Bt02AuditRepository;
use App\Domain\Keirin\Backtest\Services\Bt02SignalRegistry;
use App\Models\BacktestFold;
use App\Models\BacktestRun;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
}
