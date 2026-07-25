<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Keirin;

use App\Models\Player;
use App\Models\PlayerStatSnapshot;
use App\Models\Race;
use App\Models\RaceEntry;
use App\Models\StatFeatureSnapshot;
use App\Models\StatFeatureValue;
use App\Models\StatisticCalculationRun;
use Database\Seeders\StatFeatureDefinitionSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BuildStat01CommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(StatFeatureDefinitionSeeder::class);
    }

    public function test_date_range_builds_generic_historical_features_and_complete_audit_sources(): void
    {
        $player = $this->player(1);
        PlayerStatSnapshot::query()->create([
            'player_id' => $player->id,
            'basis_date' => '2026-07-25',
            'source_hash' => str_repeat('a', 64),
            'race_score' => '122.00',
            'first_fetched_at' => '2026-07-25 10:00:00+09:00',
            'last_fetched_at' => '2026-07-25 10:00:00+09:00',
        ]);
        $race = $this->race('2024-01-01', ['100.00', '95.00', '90.00', '85.00', '80.00'], $player);
        $this->race('2025-01-01', ['110.00', '105.00', '100.00', '95.00', '90.00']);
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $this->artisan('keirin:statistics:build-stat01', [
            '--from' => '2024-01-01',
            '--to' => '2024-12-31',
            '--chunk' => '2',
        ])->expectsOutputToContain('races=1/1 targets=5 success=5 partial=0 missing=0 invalid=0 failed=0')
            ->assertExitCode(0);

        $run = StatisticCalculationRun::query()->sole();
        $this->assertSame('SUCCEEDED', $run->status);
        $this->assertDatabaseCount('race_entry_snapshots', 5);
        $this->assertDatabaseCount('race_entry_snapshot_sources', 5);
        $this->assertDatabaseCount('stat_feature_definitions', 11);
        $this->assertDatabaseCount('stat_feature_snapshots', 5);
        $this->assertDatabaseCount('stat_feature_values', 55);
        $this->assertDatabaseCount('stat_feature_sources', 25);
        $this->assertDatabaseCount('statistic_run_feature_snapshots', 5);

        $snapshot = StatFeatureSnapshot::query()->where('race_id', $race->id)->oldest('id')->firstOrFail();
        $this->assertSame('RACE_ENTRY', $snapshot->scope_type);
        $this->assertSame('HISTORICAL_RACE_CARD_BACKFILL', $snapshot->input_snapshot_type);
        $this->assertSame('START_TIME', $snapshot->input_as_of_policy);
        $this->assertSame('2024-01-01 12:00:00', $snapshot->input_as_of->format('Y-m-d H:i:s'));
        $this->assertSame('DEGRADED', $snapshot->status);
        $this->assertSame('DEGRADED', $snapshot->data_quality_status);
        $this->assertSame('1.000000', (string) $snapshot->coverage_rate);
        $this->assertDatabaseHas('stat_feature_values', [
            'stat_feature_snapshot_id' => $snapshot->id,
            'feature_code' => 'RACE_SCORE_RAW',
            'value_type' => 'NUMERIC',
            'unit_code' => 'SCORE',
        ]);
        $this->assertDatabaseHas('stat_feature_sources', [
            'stat_feature_snapshot_id' => $snapshot->id,
            'source_role' => 'PRIMARY_INPUT',
            'source_timing_status' => 'SOURCE_LINK_MISSING',
            'scraping_fetch_log_id' => null,
        ]);
        $this->assertSame(4, DB::table('stat_feature_sources')
            ->where('stat_feature_snapshot_id', $snapshot->id)
            ->where('source_role', 'CONTEXT_INPUT')
            ->count());
        $this->assertFalse($this->queriesTable($queries, 'player_stat_snapshots'));
        $this->assertFalse($this->queriesTable($queries, 'race_results'));
        $this->assertFalse($this->queriesTable($queries, 'race_payouts'));
    }

    public function test_sales_close_precedes_start_time_and_race_id_scope_is_supported(): void
    {
        $target = $this->race(
            '2026-07-25',
            ['100.00', '95.00', '90.00', '85.00', '80.00'],
            salesCloseAt: '2026-07-25 11:55:00+09:00',
            scheduledStartAt: '2026-07-25 12:00:00+09:00',
        );
        $this->race('2026-07-25', ['110.00', '105.00', '100.00', '95.00', '90.00']);

        $this->artisan('keirin:statistics:build-stat01', [
            '--race-id' => (string) $target->id,
        ])->assertExitCode(0);

        $this->assertDatabaseCount('stat_feature_snapshots', 5);
        $this->assertSame(
            ['SALES_CLOSE'],
            StatFeatureSnapshot::query()->distinct()->pluck('input_as_of_policy')->all(),
        );
        $this->assertSame(
            [(int) $target->id],
            StatFeatureSnapshot::query()->distinct()->pluck('race_id')->map(fn ($id): int => (int) $id)->all(),
        );
    }

    public function test_dry_run_writes_none_of_the_audit_or_feature_tables(): void
    {
        $this->race('2024-01-01', ['100.00', '95.00', '90.00', '85.00', '80.00']);

        $this->artisan('keirin:statistics:build-stat01', [
            '--from' => '2024-01-01',
            '--to' => '2024-12-31',
            '--dry-run' => true,
        ])->expectsOutputToContain('calculation_run_id=dry-run')
            ->assertExitCode(0);

        foreach ([
            'statistic_calculation_runs',
            'race_entry_snapshots',
            'race_entry_snapshot_sources',
            'stat_feature_snapshots',
            'stat_feature_values',
            'stat_feature_sources',
            'statistic_run_feature_snapshots',
        ] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_identical_reruns_reuse_snapshots_and_values_while_linking_every_run(): void
    {
        $race = $this->race('2024-01-01', ['100.00', '95.00', '90.00', '85.00', '80.00']);
        $arguments = ['--race-id' => (string) $race->id];

        $this->artisan('keirin:statistics:build-stat01', $arguments)->assertExitCode(0);
        $calculatedAt = StatFeatureSnapshot::query()->oldest('id')->value('calculated_at');
        $this->artisan('keirin:statistics:build-stat01', $arguments)->assertExitCode(0);
        $this->artisan('keirin:statistics:build-stat01', [...$arguments, '--recalculate' => true])->assertExitCode(0);

        $this->assertDatabaseCount('statistic_calculation_runs', 3);
        $this->assertDatabaseCount('race_entry_snapshots', 5);
        $this->assertDatabaseCount('stat_feature_snapshots', 5);
        $this->assertDatabaseCount('stat_feature_values', 55);
        $this->assertDatabaseCount('statistic_run_feature_snapshots', 15);
        $this->assertEquals($calculatedAt, StatFeatureSnapshot::query()->oldest('id')->value('calculated_at'));
    }

    public function test_recalculate_detects_value_drift_without_overwriting_audited_values(): void
    {
        $race = $this->race('2024-01-01', ['100.00', '95.00', '90.00', '85.00', '80.00']);
        $arguments = ['--race-id' => (string) $race->id];
        $this->artisan('keirin:statistics:build-stat01', $arguments)->assertExitCode(0);
        $value = StatFeatureValue::query()->where('feature_code', 'RACE_SCORE_RAW')->firstOrFail();
        $value->forceFill(['feature_value_numeric' => 999.0])->save();

        $this->artisan('keirin:statistics:build-stat01', [...$arguments, '--recalculate' => true])
            ->expectsOutputToContain('calculation_version must change')
            ->assertExitCode(1);

        $this->assertSame(999.0, (float) $value->fresh()->feature_value_numeric);
        $this->assertDatabaseCount('stat_feature_snapshots', 5);
        $this->assertSame('FAILED', StatisticCalculationRun::query()->latest('id')->value('status'));
        $this->assertSame(0, StatisticCalculationRun::query()->where('status', 'RUNNING')->count());
    }

    public function test_partial_missing_invalid_and_unavailable_as_of_are_audited(): void
    {
        $partial = $this->race('2024-01-01', ['100.00', null, '0.00', '90.00', '80.00']);
        $blocked = $this->race(
            '2024-01-02',
            ['100.00', '95.00', '90.00', '85.00', '80.00'],
            salesCloseAt: null,
            scheduledStartAt: null,
        );

        $this->artisan('keirin:statistics:build-stat01', [
            '--from' => '2024-01-01',
            '--to' => '2024-01-02',
        ])->expectsOutputToContain('targets=10 success=0 partial=3 missing=6 invalid=1 failed=0')
            ->assertExitCode(0);

        $this->assertDatabaseHas('stat_feature_snapshots', [
            'race_id' => $partial->id,
            'race_entry_id' => $partial->entries()->where('bike_number', 2)->value('id'),
            'status' => 'MISSING_INPUT',
        ]);
        $this->assertDatabaseHas('race_entry_snapshots', [
            'race_id' => $partial->id,
            'bike_number' => 3,
            'race_score_raw_text' => '0.00',
            'race_score' => null,
            'race_score_validation_status' => 'NON_POSITIVE',
        ]);
        $this->assertSame(
            5,
            StatFeatureSnapshot::query()->where('race_id', $blocked->id)->where('status', 'BLOCKED')->count(),
        );
        $this->assertSame(
            ['INPUT_AS_OF_UNAVAILABLE'],
            StatFeatureSnapshot::query()->where('race_id', $blocked->id)->distinct()->pluck('input_as_of_policy')->all(),
        );
    }

    public function test_one_bad_race_continues_but_missing_definitions_is_a_structural_failure(): void
    {
        $badRace = Race::query()->create([
            'source' => 'keirin_jp',
            'external_race_id' => 'stat01:bad',
            'race_date' => '2024-01-01',
            'race_number' => 1,
        ]);
        $goodRace = $this->race('2024-01-02', ['100.00', '95.00', '90.00', '85.00', '80.00']);

        $this->artisan('keirin:statistics:build-stat01', [
            '--from' => '2024-01-01',
            '--to' => '2024-01-02',
            '--chunk' => '1',
        ])->expectsOutputToContain('races=1/2 targets=5 success=5 partial=0 missing=0 invalid=0 failed=1')
            ->expectsOutputToContain("race:{$badRace->id}")
            ->assertExitCode(1);
        $this->assertSame([(int) $goodRace->id], StatFeatureSnapshot::query()->distinct()->pluck('race_id')->map(fn ($id): int => (int) $id)->all());

        DB::table('stat_feature_definitions')->delete();
        $this->artisan('keirin:statistics:build-stat01', ['--race-id' => (string) $goodRace->id])
            ->expectsOutputToContain('feature definitions were missing')
            ->assertExitCode(1);
        $this->assertSame('FAILED', StatisticCalculationRun::query()->latest('id')->value('status'));
        $this->assertSame(0, StatisticCalculationRun::query()->where('status', 'RUNNING')->count());
    }

    public function test_chunking_eager_loads_entries_without_per_race_n_plus_one_queries(): void
    {
        foreach (range(1, 5) as $day) {
            $this->race(sprintf('2024-01-%02d', $day), ['100.00', '95.00', '90.00', '85.00', '80.00']);
        }
        $entryLoadQueries = 0;
        DB::listen(function (QueryExecuted $query) use (&$entryLoadQueries): void {
            $sql = strtolower($query->sql);
            if (str_contains($sql, 'from "race_entries"') && str_contains($sql, '"race_entries"."race_id" in')) {
                $entryLoadQueries++;
            }
        });

        $this->artisan('keirin:statistics:build-stat01', [
            '--from' => '2024-01-01',
            '--to' => '2024-01-05',
            '--chunk' => '2',
        ])->assertExitCode(0);

        $this->assertSame(3, $entryLoadQueries);
        $this->assertDatabaseCount('stat_feature_snapshots', 25);
    }

    public function test_no_targets_is_explicit_and_schema_is_generic_without_score_columns(): void
    {
        $this->artisan('keirin:statistics:build-stat01', [
            '--from' => '2024-01-01',
            '--to' => '2024-12-31',
        ])->expectsOutputToContain('No target races were found.')
            ->assertExitCode(1);
        $this->assertSame('NO_TARGETS', StatisticCalculationRun::query()->sole()->status);

        foreach ([
            'race_entry_snapshots',
            'race_entry_snapshot_sources',
            'stat_feature_definitions',
            'stat_feature_snapshots',
            'stat_feature_values',
            'stat_feature_sources',
            'statistic_run_feature_snapshots',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table));
        }
        $this->assertFalse(Schema::hasTable('statistic_entry_results'));
        foreach (['raw_points', 'confidence', 'effective_points', 'input_snapshot'] as $column) {
            $this->assertFalse(Schema::hasColumn('stat_feature_snapshots', $column));
        }
        $this->assertCount(3, array_filter(
            DB::select("PRAGMA index_list('stat_feature_snapshots')"),
            static fn ($index): bool => str_starts_with($index->name, 'stat_feature_snapshot_') && str_ends_with($index->name, '_unique'),
        ));
        $this->assertCount(2, array_filter(
            DB::select("PRAGMA index_list('stat_feature_values')"),
            static fn ($index): bool => str_starts_with($index->name, 'stat_feature_value_') && str_ends_with($index->name, '_unique'),
        ));
    }

    private function player(int $sequence): Player
    {
        return Player::query()->create([
            'source' => 'keirin_jp',
            'external_player_id' => sprintf('%06d', $sequence),
            'name' => "統計選手{$sequence}",
            'gender' => 'male',
        ]);
    }

    /**
     * @param  list<?string>  $scores
     */
    private function race(
        string $raceDate,
        array $scores,
        ?Player $firstPlayer = null,
        string $fetchedAt = '2026-07-24 12:00:00+09:00',
        ?string $salesCloseAt = null,
        ?string $scheduledStartAt = '2024-01-01 12:00:00+09:00',
    ): Race {
        $sequence = Race::query()->count() + 1;
        $race = Race::query()->create([
            'source' => 'keirin_jp',
            'external_race_id' => sprintf('stat01:%s:%03d', str_replace('-', '', $raceDate), $sequence),
            'race_date' => $raceDate,
            'race_number' => $sequence,
            'sales_close_at' => $salesCloseAt,
            'scheduled_start_at' => $scheduledStartAt === null
                ? null
                : str_replace('2024-01-01', $raceDate, $scheduledStartAt),
            'race_type' => 'Ｓ級予選',
            'entrant_count' => count($scores),
        ]);
        foreach ($scores as $index => $score) {
            RaceEntry::query()->create([
                'race_id' => $race->id,
                'player_id' => $index === 0 ? $firstPlayer?->id : null,
                'external_player_id' => sprintf('%06d', $sequence * 10 + $index),
                'bike_number' => $index + 1,
                'race_score' => $score,
                'fetched_at' => $fetchedAt,
            ]);
        }

        return $race;
    }

    /** @param list<string> $queries */
    private function queriesTable(array $queries, string $table): bool
    {
        return count(array_filter(
            $queries,
            static fn (string $sql): bool => str_contains($sql, 'from "'.$table.'"'),
        )) > 0;
    }
}
