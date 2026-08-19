<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Domain\Keirin\Backtest\Services\Bt03ProductionAdvisoryLock;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class Bt03BinEffectScopeMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_scope_ledger_schema_has_required_columns_and_indexes(): void
    {
        $this->assertTrue(Schema::hasColumns('backtest_bin_effect_scopes', [
            'id', 'backtest_run_id', 'backtest_fold_id', 'backtest_signal_spec_id',
            'source_backtest_run_id', 'source_backtest_fold_id', 'source_backtest_signal_spec_id',
            'cohort_code', 'status', 'attempt_count', 'expected_training_bin_count',
            'source_boundaries_hash', 'bootstrap_iterations', 'bootstrap_seed',
            'evaluation_row_count', 'evaluation_race_count', 'unseen_row_count', 'effect_count',
            'spool_byte_count', 'maximum_bin_sample_count', 'maximum_bin_race_count',
            'effect_manifest_hash', 'error_summary', 'last_interruption_summary',
            'failure_history',
            'started_at', 'finished_at', 'created_at', 'updated_at',
        ]));
        $indexes = collect(Schema::getIndexes('backtest_bin_effect_scopes'))->pluck('name')->all();
        $this->assertContains('bt_bin_effect_scopes_run_fold_spec_cohort_unique', $indexes);
        $this->assertContains('bt_bin_effect_scopes_run_status_index', $indexes);
        $this->assertContains('bt_bin_effect_scopes_source_index', $indexes);
    }

    public function test_sqlite_lock_contract_rejects_reentry_and_allows_reacquisition(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('SQLite advisory-lock contract test.');
        }
        $lock = new Bt03ProductionAdvisoryLock;
        $lock->acquire();
        try {
            try {
                $lock->acquire();
                $this->fail('The same executor must not acquire its lock twice.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('already held', $exception->getMessage());
            }
        } finally {
            $lock->release();
        }
        $lock->acquire();
        $lock->release();
        $this->addToAssertionCount(1);
    }

    public function test_postgresql_lifecycle_checks_accept_valid_states_and_reject_invalid_states(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL scope lifecycle constraint test.');
        }
        foreach (['PENDING', 'RUNNING', 'SUCCEEDED', 'FAILED'] as $status) {
            DB::table('backtest_bin_effect_scopes')->insert($this->scopeRow($status));
        }
        $this->assertSame(4, DB::table('backtest_bin_effect_scopes')->count());

        foreach ([
            array_replace($this->scopeRow('PENDING'), ['status' => 'UNKNOWN']),
            array_replace($this->scopeRow('RUNNING'), ['attempt_count' => 0]),
            array_replace($this->scopeRow('SUCCEEDED'), ['effect_manifest_hash' => null]),
            array_replace($this->scopeRow('FAILED'), ['error_summary' => null]),
        ] as $invalid) {
            try {
                DB::transaction(fn () => DB::table('backtest_bin_effect_scopes')->insert($invalid));
                $this->fail('PostgreSQL must reject an invalid scope lifecycle row.');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_postgresql_count_and_succeeded_effect_contracts_reject_invalid_rows(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL scope count constraint test.');
        }
        $constraints = DB::table('information_schema.table_constraints')
            ->where('table_name', 'backtest_bin_effect_scopes')
            ->where('constraint_type', 'CHECK')
            ->pluck('constraint_name')
            ->all();
        foreach ([
            'bt_bin_effect_scopes_failure_history_check',
            'bt_bin_effect_scopes_counts_check',
            'bt_bin_effect_scopes_effect_count_check',
            'bt_bin_effect_scopes_succeeded_counts_check',
        ] as $constraint) {
            $this->assertContains($constraint, $constraints);
        }

        foreach ([
            array_replace($this->scopeRow('RUNNING'), ['attempt_count' => -1]),
            array_replace($this->scopeRow('RUNNING'), ['evaluation_row_count' => -1]),
            array_replace($this->scopeRow('RUNNING'), ['evaluation_row_count' => 1, 'evaluation_race_count' => -1]),
            array_replace($this->scopeRow('RUNNING'), ['evaluation_row_count' => 1, 'evaluation_race_count' => 2]),
            array_replace($this->scopeRow('RUNNING'), ['evaluation_row_count' => 1, 'unseen_row_count' => -1]),
            array_replace($this->scopeRow('RUNNING'), ['evaluation_row_count' => 1, 'unseen_row_count' => 2]),
            array_replace($this->scopeRow('RUNNING'), ['effect_count' => -1]),
            array_replace($this->scopeRow('RUNNING'), ['spool_byte_count' => -1]),
            array_replace($this->scopeRow('RUNNING'), ['evaluation_row_count' => 1, 'maximum_bin_sample_count' => -1]),
            array_replace($this->scopeRow('RUNNING'), ['evaluation_row_count' => 1, 'maximum_bin_sample_count' => 2]),
            array_replace($this->scopeRow('RUNNING'), ['evaluation_row_count' => 1, 'evaluation_race_count' => 1, 'maximum_bin_race_count' => -1]),
            array_replace($this->scopeRow('RUNNING'), ['evaluation_row_count' => 2, 'evaluation_race_count' => 1, 'maximum_bin_race_count' => 2]),
            array_replace($this->scopeRow('SUCCEEDED'), ['effect_count' => 2]),
            array_replace($this->scopeRow('SUCCEEDED'), ['effect_count' => 4]),
            array_replace($this->scopeRow('SUCCEEDED'), ['maximum_bin_sample_count' => 0]),
            array_replace($this->scopeRow('RUNNING'), ['failure_history' => '{}']),
        ] as $invalid) {
            try {
                DB::transaction(fn () => DB::table('backtest_bin_effect_scopes')->insert($invalid));
                $this->fail('PostgreSQL must reject invalid BT-03 scope counts.');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_postgresql_advisory_lock_rejects_second_connection_then_releases(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL advisory-lock concurrency test.');
        }
        $default = DB::getDefaultConnection();
        config()->set('database.connections.bt03_lock_second', config("database.connections.{$default}"));
        DB::purge('bt03_lock_second');
        $first = new Bt03ProductionAdvisoryLock;
        $second = new Bt03ProductionAdvisoryLock('bt03_lock_second');

        $first->acquire();
        try {
            try {
                $second->acquire();
                $this->fail('A second PostgreSQL session must not acquire the Production lock.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('already running', $exception->getMessage());
            }
        } finally {
            $first->release();
        }
        $second->acquire();
        $second->release();
        $this->addToAssertionCount(1);
    }

    /** @return array<string, mixed> */
    private function scopeRow(string $status): array
    {
        [$run, $fold, $spec] = $this->targetFixture();
        $now = now();
        $row = [
            'backtest_run_id' => $run,
            'backtest_fold_id' => $fold,
            'backtest_signal_spec_id' => $spec,
            'source_backtest_run_id' => $run,
            'source_backtest_fold_id' => $fold,
            'source_backtest_signal_spec_id' => $spec,
            'cohort_code' => 'STRICT',
            'status' => $status,
            'attempt_count' => 0,
            'expected_training_bin_count' => 1,
            'source_boundaries_hash' => str_repeat('a', 64),
            'bootstrap_iterations' => 2000,
            'bootstrap_seed' => 20260812,
            'evaluation_row_count' => 0,
            'evaluation_race_count' => 0,
            'unseen_row_count' => 0,
            'effect_count' => 0,
            'spool_byte_count' => 0,
            'maximum_bin_sample_count' => 0,
            'maximum_bin_race_count' => 0,
            'effect_manifest_hash' => null,
            'error_summary' => null,
            'last_interruption_summary' => null,
            'failure_history' => '[]',
            'started_at' => null,
            'finished_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ];
        if ($status !== 'PENDING') {
            $row['attempt_count'] = 1;
            $row['started_at'] = $now;
        }
        if ($status === 'SUCCEEDED') {
            $row['evaluation_row_count'] = 10;
            $row['evaluation_race_count'] = 2;
            $row['effect_count'] = 3;
            $row['spool_byte_count'] = 99;
            $row['maximum_bin_sample_count'] = 10;
            $row['maximum_bin_race_count'] = 2;
            $row['effect_manifest_hash'] = str_repeat('b', 64);
            $row['finished_at'] = $now;
        } elseif ($status === 'FAILED') {
            $row['error_summary'] = 'expected failure';
            $row['finished_at'] = $now;
        }

        return $row;
    }

    /** @return array{int, int, int} */
    private function targetFixture(): array
    {
        $now = now();
        $run = (int) DB::table('backtest_runs')->insertGetId([
            'run_uuid' => fake()->uuid(),
            'backtest_code' => 'BT-03',
            'calculation_version' => 'test',
            'status' => 'RUNNING',
            'holdout_policy' => 'BLOCK_AFTER_2025-12-31',
            'source_manifest_version' => 'test',
            'source_manifest_hash' => str_repeat('c', 64),
            'prediction_rule_version' => 'test',
            'parameters' => '{}',
            'started_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $fold = (int) DB::table('backtest_folds')->insertGetId([
            'backtest_run_id' => $run,
            'fold_code' => 'TEST_'.fake()->unique()->numberBetween(1, 1000000),
            'sequence' => 1,
            'train_from' => '2022-01-01',
            'train_to' => '2022-12-31',
            'evaluation_from' => '2023-01-01',
            'evaluation_to' => '2023-12-31',
            'status' => 'RUNNING',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $spec = (int) DB::table('backtest_signal_specs')->insertGetId([
            'backtest_run_id' => $run,
            'stat_code' => 'STAT-'.fake()->unique()->numberBetween(100, 999999),
            'subject_type' => 'ENTRY',
            'analysis_role' => 'ENTRY_INCREMENTAL',
            'primary_feature_code' => 'TEST',
            'transform_code' => 'IDENTITY',
            'strict_policy_version' => 'test',
            'operational_policy_version' => 'test',
            'operational_allowed_quality_reasons' => '[]',
            'source_manifest_version' => 'test',
            'source_manifest_hash' => str_repeat('d', 64),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [$run, $fold, $spec];
    }
}
