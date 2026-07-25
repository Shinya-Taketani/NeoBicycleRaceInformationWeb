<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Keirin;

use App\Models\Player;
use App\Models\PlayerStatSnapshot;
use App\Models\Race;
use App\Models\RaceEntry;
use App\Models\StatisticCalculationRun;
use App\Models\StatisticEntryResult;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BuildStat01CommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_date_range_builds_historical_results_with_audit_fields_and_null_points(): void
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
        $queriedSql = [];
        DB::listen(function (QueryExecuted $query) use (&$queriedSql): void {
            $queriedSql[] = strtolower($query->sql);
        });

        $this->artisan('keirin:statistics:build-stat01', [
            '--from' => '2024-01-01',
            '--to' => '2024-12-31',
            '--chunk' => '2',
        ])->expectsOutputToContain('races=1/1 targets=5 success=5 partial=0 missing=0 invalid=0 failed=0')
            ->assertExitCode(0);

        $run = StatisticCalculationRun::query()->sole();
        $this->assertSame('SUCCEEDED', $run->status);
        $this->assertSame(1, $run->target_race_count);
        $this->assertSame(5, $run->target_count);
        $this->assertNotNull($run->finished_at);
        $this->assertDatabaseCount('statistic_entry_results', 5);
        $this->assertDatabaseCount('statistic_run_entry_results', 5);

        $result = StatisticEntryResult::query()->where('race_entry_id', $race->entries()->oldest('id')->value('id'))->firstOrFail();
        $this->assertSame('100.00', $result->race_score);
        $this->assertSame('HISTORICAL_SNAPSHOT', $result->quality_status);
        $this->assertSame('HISTORICAL_RACE_CARD', $result->acquisition_mode);
        $this->assertSame('STAT-01-v1', $result->calculation_version);
        $this->assertSame((int) $player->id, (int) $result->player_id);
        $this->assertSame((int) $race->id, (int) $result->race_id);
        $this->assertNull($result->raw_points);
        $this->assertNull($result->confidence);
        $this->assertNull($result->effective_points);
        $this->assertSame('100.00', $result->input_snapshot['entries'][0]['race_score']);
        $this->assertNotSame('122.00', $result->race_score);
        $this->assertNotEmpty($result->input_hash);
        $this->assertFalse($this->queriesTable($queriedSql, 'player_stat_snapshots'));
        $this->assertFalse($this->queriesTable($queriedSql, 'race_results'));
        $this->assertFalse($this->queriesTable($queriedSql, 'race_payouts'));
    }

    public function test_race_id_scope_and_live_pre_race_acquisition_are_supported(): void
    {
        $target = $this->race(
            '2026-07-25',
            ['100.00', '95.00', '90.00', '85.00', '80.00'],
            fetchedAt: '2026-07-25 09:00:00+09:00',
            scheduledStartAt: '2026-07-25 12:00:00+09:00',
        );
        $this->race('2026-07-25', ['110.00', '105.00', '100.00', '95.00', '90.00']);

        $this->artisan('keirin:statistics:build-stat01', [
            '--race-id' => (string) $target->id,
        ])->assertExitCode(0);

        $this->assertDatabaseCount('statistic_entry_results', 5);
        $this->assertSame(
            ['LIVE_PRE_RACE'],
            StatisticEntryResult::query()->distinct()->pluck('acquisition_mode')->all(),
        );
        $this->assertSame(
            [(int) $target->id],
            StatisticEntryResult::query()->distinct()->pluck('race_id')->map(fn ($id): int => (int) $id)->all(),
        );
    }

    public function test_dry_run_calculates_but_writes_nothing(): void
    {
        $this->race('2024-01-01', ['100.00', '95.00', '90.00', '85.00', '80.00']);

        $this->artisan('keirin:statistics:build-stat01', [
            '--from' => '2024-01-01',
            '--to' => '2024-12-31',
            '--dry-run' => true,
        ])->expectsOutputToContain('calculation_run_id=dry-run')
            ->expectsOutputToContain('targets=5 success=5')
            ->assertExitCode(0);

        $this->assertDatabaseCount('statistic_calculation_runs', 0);
        $this->assertDatabaseCount('statistic_entry_results', 0);
        $this->assertDatabaseCount('statistic_run_entry_results', 0);
    }

    public function test_identical_reruns_reuse_results_and_keep_each_run_auditable(): void
    {
        $race = $this->race('2024-01-01', ['100.00', '95.00', '90.00', '85.00', '80.00']);
        $arguments = ['--race-id' => (string) $race->id];

        $this->artisan('keirin:statistics:build-stat01', $arguments)->assertExitCode(0);
        $firstRunId = StatisticCalculationRun::query()->value('id');
        $this->artisan('keirin:statistics:build-stat01', $arguments)->assertExitCode(0);
        $this->artisan('keirin:statistics:build-stat01', [
            ...$arguments,
            '--recalculate' => true,
        ])->assertExitCode(0);

        $this->assertDatabaseCount('statistic_calculation_runs', 3);
        $this->assertDatabaseCount('statistic_entry_results', 5);
        $this->assertDatabaseCount('statistic_run_entry_results', 15);
        $this->assertSame(
            [(int) $firstRunId],
            StatisticEntryResult::query()->distinct()->pluck('calculation_run_id')->map(fn ($id): int => (int) $id)->all(),
        );
        $this->assertSame(3, DB::table('statistic_run_entry_results')->distinct()->count('calculation_run_id'));
    }

    public function test_partial_missing_invalid_and_all_missing_counts_are_audited(): void
    {
        $partial = $this->race('2024-01-01', ['100.00', null, '0.00', '90.00', '80.00']);
        $this->race('2024-01-02', [null, null, null, null, null]);

        $this->artisan('keirin:statistics:build-stat01', [
            '--from' => '2024-01-01',
            '--to' => '2024-01-02',
        ])->expectsOutputToContain('targets=10 success=0 partial=3 missing=6 invalid=1 failed=0')
            ->assertExitCode(0);

        $run = StatisticCalculationRun::query()->sole();
        $this->assertSame(3, $run->partial_count);
        $this->assertSame(6, $run->missing_count);
        $this->assertSame(1, $run->invalid_count);
        $this->assertDatabaseHas('statistic_entry_results', [
            'race_id' => $partial->id,
            'bike_number' => 2,
            'quality_status' => 'MISSING_INPUT',
            'race_score' => null,
        ]);
        $this->assertDatabaseHas('statistic_entry_results', [
            'race_id' => $partial->id,
            'bike_number' => 3,
            'quality_status' => 'INVALID_INPUT',
            'race_score' => null,
        ]);
    }

    public function test_one_bad_race_is_counted_and_later_races_continue(): void
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

        $run = StatisticCalculationRun::query()->sole();
        $this->assertSame('PARTIALLY_FAILED', $run->status);
        $this->assertNotNull($run->finished_at);
        $this->assertSame(0, StatisticCalculationRun::query()->where('status', 'RUNNING')->count());
        $this->assertSame(
            [(int) $goodRace->id],
            StatisticEntryResult::query()->distinct()->pluck('race_id')->map(fn ($id): int => (int) $id)->all(),
        );
    }

    public function test_chunking_eager_loads_entries_without_per_race_n_plus_one_queries(): void
    {
        foreach (range(1, 5) as $day) {
            $this->race(
                sprintf('2024-01-%02d', $day),
                ['100.00', '95.00', '90.00', '85.00', '80.00'],
            );
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
        $this->assertDatabaseCount('statistic_entry_results', 25);
    }

    public function test_no_targets_is_explicit_and_finishes_the_run(): void
    {
        $this->artisan('keirin:statistics:build-stat01', [
            '--from' => '2024-01-01',
            '--to' => '2024-12-31',
        ])->expectsOutputToContain('No target races were found.')
            ->assertExitCode(1);

        $run = StatisticCalculationRun::query()->sole();
        $this->assertSame('NO_TARGETS', $run->status);
        $this->assertNotNull($run->finished_at);
    }

    public function test_schema_exposes_relational_keys_snapshot_columns_and_nullable_points(): void
    {
        $this->assertTrue(Schema::hasColumns('statistic_calculation_runs', [
            'stat_code',
            'calculation_version',
            'target_count',
            'error_summary',
        ]));
        $this->assertTrue(Schema::hasColumns('statistic_entry_results', [
            'race_id',
            'race_entry_id',
            'player_id',
            'input_snapshot',
            'input_hash',
            'raw_points',
            'confidence',
            'effective_points',
        ]));
        $this->assertTrue(Schema::hasTable('statistic_run_entry_results'));
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
        ?string $scheduledStartAt = null,
    ): Race {
        $sequence = Race::query()->count() + 1;
        $race = Race::query()->create([
            'source' => 'keirin_jp',
            'external_race_id' => sprintf('stat01:%s:%03d', str_replace('-', '', $raceDate), $sequence),
            'race_date' => $raceDate,
            'race_number' => $sequence,
            'scheduled_start_at' => $scheduledStartAt,
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

    /**
     * @param  list<string>  $queries
     */
    private function queriesTable(array $queries, string $table): bool
    {
        return count(array_filter(
            $queries,
            static fn (string $sql): bool => str_contains($sql, 'from "'.$table.'"'),
        )) > 0;
    }
}
