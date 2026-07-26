<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StatisticFeaturePostgreSqlMigrationTest extends TestCase
{
    private int $savepointSequence = 0;

    private int $fixtureSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL catalog and constraint test.');
        }

        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        if (DB::getDriverName() === 'pgsql' && DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        parent::tearDown();
    }

    public function test_race_entry_lifecycle_and_score_observation_columns_are_nullable_timestamptz(): void
    {
        $columns = collect(DB::select(
            <<<'SQL'
                SELECT table_name, column_name, data_type, is_nullable
                FROM information_schema.columns
                WHERE table_schema = current_schema()
                  AND (
                    (table_name = 'race_entries' AND column_name IN ('race_score_fetched_at', 'deleted_at'))
                    OR
                    (table_name = 'race_entry_snapshots' AND column_name IN ('first_observed_at', 'last_observed_at'))
                  )
                SQL,
        ))->keyBy(fn (object $column): string => "{$column->table_name}.{$column->column_name}");

        foreach ([
            'race_entries.race_score_fetched_at',
            'race_entries.deleted_at',
            'race_entry_snapshots.first_observed_at',
            'race_entry_snapshots.last_observed_at',
        ] as $columnName) {
            $column = $columns->get($columnName);
            $this->assertNotNull($column, $columnName);
            $this->assertSame('timestamp with time zone', $column->data_type, $columnName);
            $this->assertSame('YES', $column->is_nullable, $columnName);
        }
    }

    public function test_snapshot_external_player_id_matches_race_entry_identity_column(): void
    {
        $columns = collect(DB::select(
            <<<'SQL'
                SELECT table_name, column_name, data_type, character_maximum_length, is_nullable
                FROM information_schema.columns
                WHERE table_schema = current_schema()
                  AND table_name IN ('race_entries', 'race_entry_snapshots')
                  AND column_name = 'external_player_id'
                SQL,
        ))->keyBy('table_name');

        $entryColumn = $columns->get('race_entries');
        $snapshotColumn = $columns->get('race_entry_snapshots');
        $this->assertNotNull($entryColumn);
        $this->assertNotNull($snapshotColumn);
        $this->assertSame($entryColumn->data_type, $snapshotColumn->data_type);
        $this->assertSame($entryColumn->character_maximum_length, $snapshotColumn->character_maximum_length);
        $this->assertSame('YES', $snapshotColumn->is_nullable);
    }

    public function test_audit_lifecycle_migration_can_rollback_and_reapply_without_rows(): void
    {
        $migration = require database_path('migrations/2026_07_26_000005_add_race_entry_audit_lifecycle_fields.php');

        $migration->down();
        $this->assertFalse(DB::getSchemaBuilder()->hasColumn('race_entry_snapshots', 'external_player_id'));
        $this->assertFalse(DB::getSchemaBuilder()->hasColumn('race_entries', 'race_score_fetched_at'));
        $this->assertFalse(DB::getSchemaBuilder()->hasColumn('race_entries', 'deleted_at'));

        $migration->up();
        $this->assertTrue(DB::getSchemaBuilder()->hasColumn('race_entry_snapshots', 'external_player_id'));
        $this->assertTrue(DB::getSchemaBuilder()->hasColumn('race_entries', 'race_score_fetched_at'));
        $this->assertTrue(DB::getSchemaBuilder()->hasColumn('race_entries', 'deleted_at'));
    }

    public function test_postgresql_indexes_are_unique_valid_partial_and_nulls_not_distinct_where_required(): void
    {
        $expected = [
            'race_entry_snapshots_current_unique',
            'stat_feature_snapshot_race_unique',
            'stat_feature_snapshot_entry_unique',
            'stat_feature_snapshot_pair_unique',
            'stat_feature_value_unwindowed_unique',
            'stat_feature_value_windowed_unique',
        ];
        $definitions = collect(DB::select(
            <<<'SQL'
                SELECT indexname, indexdef
                FROM pg_indexes
                WHERE schemaname = current_schema()
                  AND indexname = ANY (?::text[])
                SQL,
            ['{'.implode(',', $expected).'}'],
        ))->keyBy('indexname');
        $this->assertSame($expected, array_values(array_intersect($expected, $definitions->keys()->all())));

        $catalog = collect(DB::select(
            <<<'SQL'
                SELECT
                    index_class.relname AS index_name,
                    index_data.indisunique,
                    index_data.indisvalid,
                    index_data.indnullsnotdistinct,
                    pg_get_expr(index_data.indpred, index_data.indrelid) AS predicate,
                    pg_get_indexdef(index_data.indexrelid) AS definition
                FROM pg_index AS index_data
                JOIN pg_class AS index_class ON index_class.oid = index_data.indexrelid
                JOIN pg_namespace AS namespace ON namespace.oid = index_class.relnamespace
                WHERE namespace.nspname = current_schema()
                  AND index_class.relname = ANY (?::text[])
                SQL,
            ['{'.implode(',', $expected).'}'],
        ))->keyBy('index_name');

        foreach ($expected as $indexName) {
            $index = $catalog->get($indexName);
            $this->assertNotNull($index, $indexName);
            $this->assertTrue((bool) $index->indisunique, $indexName);
            $this->assertTrue((bool) $index->indisvalid, $indexName);
            $this->assertNotNull($index->predicate, $indexName);
            $this->assertStringContainsString('CREATE UNIQUE INDEX', $definitions->get($indexName)->indexdef);
        }
        foreach ([
            'stat_feature_snapshot_race_unique',
            'stat_feature_snapshot_entry_unique',
            'stat_feature_snapshot_pair_unique',
        ] as $indexName) {
            $this->assertTrue((bool) $catalog->get($indexName)->indnullsnotdistinct, $indexName);
            $this->assertStringContainsString('NULLS NOT DISTINCT', $catalog->get($indexName)->definition);
        }
        $this->assertStringContainsString(
            'is_current',
            $catalog->get('race_entry_snapshots_current_unique')->predicate,
        );
    }

    public function test_postgresql_check_constraints_and_foreign_key_delete_rules_are_valid(): void
    {
        $expectedChecks = [
            'statistic_calculation_runs_status_check',
            'race_entry_snapshots_validation_check',
            'race_entry_snapshots_anomaly_check',
            'race_entry_snapshots_bike_check',
            'stat_feature_snapshots_scope_check',
            'stat_feature_snapshots_status_check',
            'stat_feature_snapshots_quality_check',
            'stat_feature_snapshots_as_of_policy_check',
            'stat_feature_values_value_type_check',
            'stat_feature_values_status_check',
            'stat_feature_values_window_check',
            'stat_feature_values_finite_check',
            'stat_feature_sources_role_check',
        ];
        $checks = collect(DB::select(
            <<<'SQL'
                SELECT constraint_data.conname, constraint_data.convalidated
                FROM pg_constraint AS constraint_data
                JOIN pg_namespace AS namespace ON namespace.oid = constraint_data.connamespace
                WHERE namespace.nspname = current_schema()
                  AND constraint_data.contype = 'c'
                  AND constraint_data.conname = ANY (?::text[])
                SQL,
            ['{'.implode(',', $expectedChecks).'}'],
        ))->keyBy('conname');

        foreach ($expectedChecks as $constraintName) {
            $constraint = $checks->get($constraintName);
            $this->assertNotNull($constraint, $constraintName);
            $this->assertTrue((bool) $constraint->convalidated, $constraintName);
        }

        $expectedForeignKeys = [
            'statistic_calculation_runs.target_race_id' => ['races', 'n'],
            'race_entry_snapshots.race_entry_id' => ['race_entries', 'r'],
            'race_entry_snapshots.race_id' => ['races', 'r'],
            'race_entry_snapshots.player_id' => ['players', 'n'],
            'race_entry_snapshot_sources.race_entry_snapshot_id' => ['race_entry_snapshots', 'c'],
            'race_entry_snapshot_sources.scraping_fetch_log_id' => ['scraping_fetch_logs', 'n'],
            'stat_feature_snapshots.race_id' => ['races', 'r'],
            'stat_feature_snapshots.race_entry_id' => ['race_entries', 'r'],
            'stat_feature_snapshots.player_id' => ['players', 'n'],
            'stat_feature_snapshots.opponent_race_entry_id' => ['race_entries', 'r'],
            'stat_feature_snapshots.opponent_player_id' => ['players', 'n'],
            'stat_feature_values.stat_feature_snapshot_id' => ['stat_feature_snapshots', 'c'],
            'stat_feature_sources.stat_feature_snapshot_id' => ['stat_feature_snapshots', 'c'],
            'stat_feature_sources.race_entry_snapshot_id' => ['race_entry_snapshots', 'r'],
            'stat_feature_sources.scraping_fetch_log_id' => ['scraping_fetch_logs', 'n'],
            'statistic_run_feature_snapshots.calculation_run_id' => ['statistic_calculation_runs', 'c'],
            'statistic_run_feature_snapshots.stat_feature_snapshot_id' => ['stat_feature_snapshots', 'r'],
            'statistic_run_feature_snapshots.race_id' => ['races', 'r'],
        ];
        $foreignKeys = collect(DB::select(
            <<<'SQL'
                SELECT
                    source_table.relname || '.' || source_column.attname AS source_key,
                    target_table.relname AS target_table,
                    constraint_data.confdeltype
                FROM pg_constraint AS constraint_data
                JOIN pg_class AS source_table ON source_table.oid = constraint_data.conrelid
                JOIN pg_class AS target_table ON target_table.oid = constraint_data.confrelid
                JOIN pg_attribute AS source_column
                  ON source_column.attrelid = constraint_data.conrelid
                 AND source_column.attnum = constraint_data.conkey[1]
                JOIN pg_namespace AS namespace ON namespace.oid = source_table.relnamespace
                WHERE namespace.nspname = current_schema()
                  AND constraint_data.contype = 'f'
                  AND source_table.relname IN (
                      'statistic_calculation_runs',
                      'race_entry_snapshots',
                      'race_entry_snapshot_sources',
                      'stat_feature_snapshots',
                      'stat_feature_values',
                      'stat_feature_sources',
                      'statistic_run_feature_snapshots'
                )
                SQL,
        ))->keyBy('source_key');

        foreach ($expectedForeignKeys as $sourceKey => [$targetTable, $deleteRule]) {
            $constraint = $foreignKeys->get($sourceKey);
            $this->assertNotNull($constraint, $sourceKey);
            $this->assertSame($targetTable, $constraint->target_table, $sourceKey);
            $this->assertSame($deleteRule, $constraint->confdeltype, $sourceKey);
        }
    }

    public function test_postgresql_rejects_invalid_rows_and_enforces_null_logical_uniqueness(): void
    {
        [$raceId, $raceEntryId] = $this->raceEntry();
        $currentId = $this->insertRaceEntrySnapshot($raceId, $raceEntryId, 'a', true);

        $this->assertDatabaseRejects(
            fn () => $this->insertRaceEntrySnapshot($raceId, $raceEntryId, 'b', true),
        );
        $this->insertRaceEntrySnapshot($raceId, $raceEntryId, 'b', false);
        $this->insertRaceEntrySnapshot($raceId, $raceEntryId, 'c', false);
        $this->assertSame(
            1,
            DB::table('race_entry_snapshots')
                ->where('race_entry_id', $raceEntryId)
                ->where('is_current', true)
                ->count(),
        );
        $this->assertSame(
            2,
            DB::table('race_entry_snapshots')
                ->where('race_entry_id', $raceEntryId)
                ->where('is_current', false)
                ->count(),
        );

        $featureSnapshotId = $this->insertFeatureSnapshot($raceId, $raceEntryId, 'd');
        $this->assertDatabaseRejects(
            fn () => $this->insertFeatureSnapshot($raceId, $raceEntryId, 'd'),
        );
        $this->insertFeatureSnapshot($raceId, $raceEntryId, 'e');

        $this->assertDatabaseRejects(fn () => $this->insertFeatureValue($featureSnapshotId, [
            'feature_code' => 'INVALID_INTEGER',
            'value_type' => 'INTEGER',
            'feature_value_numeric' => 1.0,
        ]));
        $this->assertDatabaseRejects(fn () => $this->insertFeatureValue($featureSnapshotId, [
            'feature_code' => 'INVALID_NUMERIC',
            'value_type' => 'NUMERIC',
            'feature_value_integer' => 1,
            'feature_value_numeric' => 1.0,
        ]));
        $this->assertDatabaseRejects(fn () => $this->insertFeatureValue($featureSnapshotId, [
            'feature_code' => 'WINDOW_TYPE_ONLY',
            'value_type' => 'NUMERIC',
            'feature_value_numeric' => 1.0,
            'window_type' => 'LAST_RACES',
        ]));
        $this->assertDatabaseRejects(fn () => $this->insertFeatureValue($featureSnapshotId, [
            'feature_code' => 'WINDOW_VALUE_ONLY',
            'value_type' => 'NUMERIC',
            'feature_value_numeric' => 1.0,
            'window_value' => '5',
        ]));
        $this->assertDatabaseRejects(
            fn () => $this->insertNonFiniteFeatureValue($featureSnapshotId, 'NOT_A_NUMBER', 'NaN'),
        );
        $this->assertDatabaseRejects(
            fn () => $this->insertNonFiniteFeatureValue($featureSnapshotId, 'POSITIVE_INFINITY', 'Infinity'),
        );
        $this->assertDatabaseRejects(
            fn () => $this->insertNonFiniteFeatureValue($featureSnapshotId, 'NEGATIVE_INFINITY', '-Infinity'),
        );

        $this->assertDatabaseRejects(
            fn () => $this->insertFeatureSnapshot($raceId, null, 'f'),
        );
        $this->assertDatabaseRejects(
            fn () => $this->insertFeatureSnapshot($raceId, $raceEntryId, 'f', 'INVALID_STATUS'),
        );
        $this->assertDatabaseRejects(fn () => DB::table('stat_feature_sources')->insert([
            'stat_feature_snapshot_id' => $featureSnapshotId,
            'race_entry_snapshot_id' => $currentId,
            'source_role' => 'INVALID_ROLE',
            'source_identity_key' => 'invalid-role',
            'source_type' => 'RACE_ENTRY_SNAPSHOT',
            'source_timing_status' => 'AT_OR_BEFORE_INPUT_AS_OF',
            'created_at' => now(),
        ]));
    }

    /**
     * @return array{int,int}
     */
    private function raceEntry(): array
    {
        $this->fixtureSequence++;
        $now = now();
        $raceId = DB::table('races')->insertGetId([
            'source' => 'postgresql-migration-test',
            'external_race_id' => "postgresql-migration-test:{$this->fixtureSequence}",
            'race_date' => '2026-07-26',
            'race_number' => $this->fixtureSequence,
            'result_status' => 'UNAVAILABLE',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $raceEntryId = DB::table('race_entries')->insertGetId([
            'race_id' => $raceId,
            'external_player_id' => sprintf('%06d', $this->fixtureSequence),
            'bike_number' => 1,
            'race_score' => 100.00,
            'fetched_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [(int) $raceId, (int) $raceEntryId];
    }

    private function insertRaceEntrySnapshot(
        int $raceId,
        int $raceEntryId,
        string $hashCharacter,
        bool $isCurrent,
    ): int {
        $now = now();

        return (int) DB::table('race_entry_snapshots')->insertGetId([
            'race_entry_id' => $raceEntryId,
            'race_id' => $raceId,
            'bike_number' => 1,
            'race_score_raw_text' => '100.00',
            'race_score' => 100.00,
            'race_score_validation_status' => 'VALID',
            'race_score_anomaly_status' => 'NOT_CHECKED',
            'snapshot_type' => 'LEGACY_BACKFILL',
            'input_snapshot_type' => 'HISTORICAL_RACE_CARD_BACKFILL',
            'snapshot_hash' => str_repeat($hashCharacter, 64),
            'first_observed_at' => $now,
            'last_observed_at' => $now,
            'is_current' => $isCurrent,
            'is_complete' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function insertFeatureSnapshot(
        int $raceId,
        ?int $raceEntryId,
        string $hashCharacter,
        string $status = 'VALID',
    ): int {
        $now = now();

        return (int) DB::table('stat_feature_snapshots')->insertGetId([
            'scope_type' => 'RACE_ENTRY',
            'race_id' => $raceId,
            'race_entry_id' => $raceEntryId,
            'stat_code' => 'STAT-01',
            'input_as_of' => null,
            'input_as_of_policy' => 'INPUT_AS_OF_UNAVAILABLE',
            'input_snapshot_type' => 'UNKNOWN_SOURCE_TIMING',
            'input_hash' => str_repeat($hashCharacter, 64),
            'calculation_version' => 'STAT-01-v1',
            'status' => $status,
            'data_quality_status' => 'VALID',
            'calculated_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function insertFeatureValue(int $featureSnapshotId, array $overrides): void
    {
        $now = now();
        DB::table('stat_feature_values')->insert([
            'stat_feature_snapshot_id' => $featureSnapshotId,
            'feature_code' => 'VALID_NUMERIC',
            'value_type' => 'NUMERIC',
            'feature_value_integer' => null,
            'feature_value_numeric' => 1.0,
            'feature_value_text' => null,
            'feature_value_boolean' => null,
            'feature_value_json' => null,
            'window_type' => null,
            'window_value' => null,
            'unit_code' => 'NONE',
            'status' => 'VALID',
            'created_at' => $now,
            'updated_at' => $now,
            ...$overrides,
        ]);
    }

    private function insertNonFiniteFeatureValue(
        int $featureSnapshotId,
        string $featureCode,
        string $value,
    ): void {
        DB::statement(
            <<<'SQL'
                INSERT INTO stat_feature_values (
                    stat_feature_snapshot_id,
                    feature_code,
                    value_type,
                    feature_value_numeric,
                    unit_code,
                    status,
                    created_at,
                    updated_at
                ) VALUES (?, ?, 'NUMERIC', ?::double precision, 'NONE', 'VALID', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                SQL,
            [$featureSnapshotId, $featureCode, $value],
        );
    }

    private function assertDatabaseRejects(Closure $operation): void
    {
        $savepoint = 'constraint_check_'.++$this->savepointSequence;
        DB::statement("SAVEPOINT {$savepoint}");

        try {
            $operation();
        } catch (QueryException) {
            DB::statement("ROLLBACK TO SAVEPOINT {$savepoint}");
            DB::statement("RELEASE SAVEPOINT {$savepoint}");
            $this->addToAssertionCount(1);

            return;
        }

        DB::statement("ROLLBACK TO SAVEPOINT {$savepoint}");
        DB::statement("RELEASE SAVEPOINT {$savepoint}");
        $this->fail('PostgreSQL accepted a row that should violate a database constraint.');
    }
}
