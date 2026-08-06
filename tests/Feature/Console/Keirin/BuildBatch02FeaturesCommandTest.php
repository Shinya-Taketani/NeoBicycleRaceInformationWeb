<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Keirin;

use App\Domain\Keirin\Statistics\Calculators\Stat01Calculator;
use App\Domain\Keirin\Statistics\Calculators\Stat10Calculator;
use App\Domain\Keirin\Statistics\DTO\Batch02BuildOptionsDto;
use App\Domain\Keirin\Statistics\DTO\Batch02FeatureResultDto;
use App\Domain\Keirin\Statistics\DTO\Batch02TargetEntryDto;
use App\Domain\Keirin\Statistics\DTO\HistoricalRaceDto;
use App\Domain\Keirin\Statistics\Enums\HistoricalResultState;
use App\Domain\Keirin\Statistics\Repositories\HistoricalRaceRepository;
use App\Domain\Keirin\Statistics\Support\Batch02CalculatorSupport;
use App\Domain\Keirin\Statistics\Support\StatisticalMath;
use App\Models\StatisticFeatureResult;
use App\Models\StatisticFeatureRun;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class BuildBatch02FeaturesCommandTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    public function test_it_builds_five_auditable_runs_from_stat01_and_preserves_source_tables(): void
    {
        [$runId, $targetRaceId] = $this->fixture();
        $sourceBefore = $this->sourceRows();

        $this->artisan('keirin:statistics:build-batch02', [
            '--stat01-run-id' => $runId,
            '--history-from' => '2023-01-01',
            '--race-id' => $targetRaceId,
            '--chunk' => 1,
        ])
            ->expectsOutputToContain('target_races=1 target_entries=1')
            ->assertExitCode(0);

        $runs = StatisticFeatureRun::query()->where('id', '!=', $runId)->orderBy('stat_code')->get();
        $this->assertSame(['STAT-10', 'STAT-11', 'STAT-12', 'STAT-24', 'STAT-26'], $runs->pluck('stat_code')->all());
        $this->assertCount(1, $runs->pluck('parameters')->pluck('batch_execution_uuid')->unique());
        $this->assertSame(5, StatisticFeatureResult::query()->where('feature_run_id', '!=', $runId)->count());
        foreach (StatisticFeatureResult::query()->where('feature_run_id', '!=', $runId)->get() as $result) {
            $this->assertNull($result->raw_points);
            $this->assertNull($result->confidence);
            $this->assertNull($result->effective_points);
            $this->assertSame('BACKFILLED_FINAL_RESULT', $result->evidence['history_result_mode']);
            $this->assertSame(2, $result->evidence['history_event_count']);
            $this->assertContains('IN_MEETING_RESULT_CONFIRMATION_NOT_RECONSTRUCTED', $result->evidence['quality_reasons']);
        }
        $this->assertSame($sourceBefore, $this->sourceRows());
    }

    public function test_dry_run_calculates_all_stats_without_statistic_writes(): void
    {
        [$runId, $targetRaceId] = $this->fixture();

        $this->artisan('keirin:statistics:build-batch02', [
            '--stat01-run-id' => $runId,
            '--history-from' => '2023-01-01',
            '--race-id' => $targetRaceId,
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('run=dry-run')
            ->expectsOutputToContain('stat_code=STAT-10')
            ->expectsOutputToContain('stat_code=STAT-26')
            ->assertExitCode(0);

        $this->assertSame(1, StatisticFeatureRun::query()->count());
        $this->assertSame(1, StatisticFeatureResult::query()->count());
        $this->assertSame(0, DB::table('statistic_feature_run_items')->count());
    }

    public function test_invalid_arguments_and_incomplete_stat01_run_are_rejected(): void
    {
        [$runId, $targetRaceId] = $this->fixture();
        $commands = [
            [],
            ['--stat01-run-id' => $runId, '--race-id' => $targetRaceId],
            ['--stat01-run-id' => $runId, '--history-from' => '2023-01-01'],
            ['--stat01-run-id' => $runId, '--history-from' => '2024-01-10', '--race-id' => $targetRaceId],
            ['--stat01-run-id' => $runId, '--history-from' => '2023-01-01', '--race-id' => $targetRaceId, '--chunk' => 1001],
        ];
        foreach ($commands as $arguments) {
            $this->artisan('keirin:statistics:build-batch02', $arguments)->assertExitCode(1);
        }
        StatisticFeatureRun::query()->findOrFail($runId)->forceFill(['error_count' => 1])->save();
        $this->artisan('keirin:statistics:build-batch02', [
            '--stat01-run-id' => $runId,
            '--history-from' => '2023-01-01',
            '--race-id' => $targetRaceId,
        ])->expectsOutputToContain('specified STAT-01 run was not complete')->assertExitCode(1);

        $this->assertSame(1, StatisticFeatureRun::query()->count());
    }

    public function test_repository_prevents_leakage_and_hashes_historical_score_context(): void
    {
        [$runId, $targetRaceId] = $this->fixture();
        $options = new Batch02BuildOptionsDto(
            $runId,
            new DateTimeImmutable('2023-01-01'),
            null,
            null,
            $targetRaceId,
            200,
            true,
        );
        $repository = $this->app->make(HistoricalRaceRepository::class);
        $input = iterator_to_array($repository->raceInputs($options), false)[0];
        $target = $input->entries[0];
        $histories = $input->historiesByPlayer[$target->playerId];

        $this->assertCount(2, $histories);
        $this->assertSame(['2023-12-10', '2024-01-09'], array_map(
            fn ($history): string => $history->scheduledStartAt->format('Y-m-d'),
            $histories,
        ));
        $this->assertSame(0.0, $histories[0]->scoreExpectationResidual);
        $this->assertSame(HistoricalResultState::NormalFinish, $histories[1]->resultState);
        $this->assertTrue($histories[1]->tied);
        $calculator = $this->app->make(Stat10Calculator::class);
        $before = $calculator->calculate($target, $histories, $options, 'batch')->inputHash;
        $contextBefore = $histories[0]->historicalScoreContextHash;

        DB::table('race_entries')
            ->where('race_id', $histories[0]->raceId)
            ->where('bike_number', 2)
            ->update(['race_score' => '90.00']);
        $changedInput = iterator_to_array($repository->raceInputs($options), false)[0];
        $changedHistories = $changedInput->historiesByPlayer[$target->playerId];
        $after = $calculator->calculate($target, $changedHistories, $options, 'batch')->inputHash;

        $this->assertNotSame($contextBefore, $changedHistories[0]->historicalScoreContextHash);
        $this->assertSame(1.0, $changedHistories[0]->scoreExpectationResidual);
        $this->assertNotSame($before, $after);

        DB::table('race_entries')
            ->where('race_id', $histories[0]->raceId)
            ->where('bike_number', 2)
            ->update(['race_score' => '0.00']);
        $incompleteInput = iterator_to_array($repository->raceInputs($options), false)[0];
        $this->assertNull($incompleteInput->historiesByPlayer[$target->playerId][0]->scoreExpectationResidual);
    }

    public function test_date_range_keyset_chunks_have_no_omissions_and_history_queries_are_not_per_entry(): void
    {
        [$runId] = $this->fixture();
        $this->addTargetToStat01Run($runId, '2024-01-12', 2);
        $historyQueries = 0;
        DB::listen(function ($query) use (&$historyQueries): void {
            if (str_contains($query->sql, 'history_entries')) {
                $historyQueries++;
            }
        });

        $this->artisan('keirin:statistics:build-batch02', [
            '--stat01-run-id' => $runId,
            '--history-from' => '2023-01-01',
            '--from' => '2024-01-10',
            '--to' => '2024-01-12',
            '--chunk' => 1,
        ])->assertExitCode(0);

        $batchRuns = StatisticFeatureRun::query()->where('id', '!=', $runId)->get();
        $this->assertCount(5, $batchRuns);
        foreach ($batchRuns as $run) {
            $this->assertSame(2, $run->processed_race_count);
            $this->assertSame(2, $run->items()->count());
            $this->assertSame(2, $run->results()->count());
        }
        $this->assertSame(2, $historyQueries);
    }

    public function test_one_stat_race_failure_does_not_stop_other_stats_or_later_races(): void
    {
        [$runId, $failedRaceId] = $this->fixture();
        $successfulRaceId = $this->addTargetToStat01Run($runId, '2024-01-12', 2);
        $this->app->bind(Stat10Calculator::class, function () use ($failedRaceId): Stat10Calculator {
            return new class($failedRaceId, $this->app->make(Batch02CalculatorSupport::class), $this->app->make(StatisticalMath::class)) extends Stat10Calculator
            {
                public function __construct(
                    private readonly int $failedRaceId,
                    Batch02CalculatorSupport $support,
                    StatisticalMath $math,
                ) {
                    parent::__construct($support, $math);
                }

                /** @param  list<HistoricalRaceDto>  $histories */
                public function calculate(Batch02TargetEntryDto $target, array $histories, Batch02BuildOptionsDto $options, string $batchExecutionUuid): Batch02FeatureResultDto
                {
                    if ($target->raceId === $this->failedRaceId) {
                        throw new RuntimeException('synthetic STAT-10 failure');
                    }

                    return parent::calculate($target, $histories, $options, $batchExecutionUuid);
                }
            };
        });

        $this->artisan('keirin:statistics:build-batch02', [
            '--stat01-run-id' => $runId,
            '--history-from' => '2023-01-01',
            '--from' => '2024-01-10',
            '--to' => '2024-01-12',
            '--chunk' => 1,
        ])->assertExitCode(1);

        $stat10 = StatisticFeatureRun::query()->where('stat_code', 'STAT-10')->sole();
        $this->assertSame('PARTIALLY_SUCCEEDED', $stat10->status);
        $this->assertSame(1, $stat10->error_count);
        $this->assertNotNull($stat10->finished_at);
        $this->assertDatabaseHas('statistic_feature_run_items', ['feature_run_id' => $stat10->id, 'race_id' => $failedRaceId, 'status' => 'FAILED']);
        $this->assertDatabaseHas('statistic_feature_run_items', ['feature_run_id' => $stat10->id, 'race_id' => $successfulRaceId, 'status' => 'PARTIAL']);
        foreach (['STAT-11', 'STAT-12', 'STAT-24', 'STAT-26'] as $statCode) {
            $run = StatisticFeatureRun::query()->where('stat_code', $statCode)->sole();
            $this->assertSame(0, $run->error_count);
            $this->assertSame(2, $run->results()->count());
            $this->assertNotNull($run->finished_at);
        }
        $this->assertSame(0, StatisticFeatureRun::query()->where('status', 'RUNNING')->count());
    }

    /** @return array{int, int} */
    private function fixture(): array
    {
        $now = now();
        $trackId = DB::table('racetracks')->insertGetId([
            'source' => 'batch02-test', 'external_track_id' => '01', 'name' => 'Test Track', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $preMeeting = $this->meeting($trackId, 'pre', '2023-12-01');
        $targetMeeting = $this->meeting($trackId, 'target', '2024-01-01');
        $playerId = $this->player('target');
        $opponentId = $this->player('opponent');

        $this->historicalRace($preMeeting, $trackId, '2023-12-10', 1, $playerId, $opponentId, 1, 'FINISHED', '80.00', '70.00');
        $this->historicalRace($targetMeeting, $trackId, '2024-01-09', 2, $playerId, $opponentId, 2, 'TIED', '80.00', '70.00');
        $this->historicalRace($targetMeeting, $trackId, '2024-01-11', 3, $playerId, $opponentId, 1, 'FINISHED', '80.00', '70.00');

        $targetDay = $this->day($targetMeeting, '2024-01-10');
        $targetRaceId = DB::table('races')->insertGetId([
            'source' => 'batch02-test',
            'external_race_id' => 'target-race',
            'race_day_id' => $targetDay,
            'racetrack_id' => $trackId,
            'race_date' => '2024-01-10',
            'race_number' => 1,
            'scheduled_start_at' => '2024-01-10 12:00:00',
            'sales_close_at' => '2024-01-10 11:55:00',
            'race_type' => 'Ａ級予選',
            'entrant_count' => 1,
            'result_status' => 'UNAVAILABLE',
            'result_available' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $entryId = DB::table('race_entries')->insertGetId([
            'race_id' => $targetRaceId,
            'player_id' => $playerId,
            'external_player_id' => 'target',
            'bike_number' => 1,
            'frame_number' => 1,
            'grade' => 'A1',
            'race_score' => '80.00',
            'fetched_at' => '2024-01-10 10:00:00',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $runId = DB::table('statistic_feature_runs')->insertGetId([
            'run_uuid' => '00000000-0000-4000-8000-000000000001',
            'stat_code' => Stat01Calculator::STAT_CODE,
            'calculation_version' => Stat01Calculator::CALCULATION_VERSION,
            'mode' => 'BACKFILL',
            'status' => 'PARTIALLY_SUCCEEDED',
            'history_from' => null,
            'target_from' => '2024-01-10',
            'target_to' => '2024-01-10',
            'target_race_id' => null,
            'input_as_of_policy' => 'test',
            'parameters' => '{}',
            'target_race_count' => 1,
            'processed_race_count' => 1,
            'target_entry_count' => 1,
            'success_count' => 1,
            'partial_count' => 0,
            'missing_count' => 0,
            'invalid_count' => 0,
            'error_count' => 0,
            'started_at' => $now,
            'finished_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('statistic_feature_results')->insert([
            'feature_run_id' => $runId,
            'stat_code' => Stat01Calculator::STAT_CODE,
            'calculation_version' => Stat01Calculator::CALCULATION_VERSION,
            'subject_type' => 'RACE_ENTRY',
            'subject_key' => 'race_entry:'.$entryId,
            'race_id' => $targetRaceId,
            'race_entry_id' => $entryId,
            'player_id' => $playerId,
            'bike_number' => 1,
            'status' => 'VALID',
            'quality_status' => 'FULL',
            'acquisition_mode' => 'BACKFILL',
            'input_as_of' => '2024-01-10 11:55:00',
            'source_fetched_at' => '2024-01-10 10:00:00',
            'features' => '{}',
            'evidence' => '{}',
            'input_hash' => str_repeat('a', 64),
            'calculated_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [(int) $runId, (int) $targetRaceId];
    }

    private function historicalRace(int $meetingId, int $trackId, string $date, int $number, int $playerId, int $opponentId, int $rank, string $status, string $score, string $opponentScore): void
    {
        $now = now();
        $dayId = $this->day($meetingId, $date);
        $raceId = DB::table('races')->insertGetId([
            'source' => 'batch02-test', 'external_race_id' => 'history-'.$date.'-'.$number, 'race_day_id' => $dayId,
            'racetrack_id' => $trackId, 'race_date' => $date, 'race_number' => $number,
            'scheduled_start_at' => $date.' 12:00:00', 'race_type' => 'Ａ級予選', 'entrant_count' => 2,
            'result_status' => 'CONFIRMED', 'result_available' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);
        foreach ([[$playerId, 1, $score], [$opponentId, 2, $opponentScore]] as [$entryPlayerId, $bike, $raceScore]) {
            $entryId = DB::table('race_entries')->insertGetId([
                'race_id' => $raceId, 'player_id' => $entryPlayerId, 'external_player_id' => 'p-'.$entryPlayerId,
                'bike_number' => $bike, 'frame_number' => $bike, 'grade' => 'A1', 'race_score' => $raceScore,
                'fetched_at' => $date.' 10:00:00', 'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('race_results')->insert([
                'race_id' => $raceId, 'race_entry_id' => $entryId, 'player_id' => $entryPlayerId, 'bike_number' => $bike,
                'rank' => $bike === 1 ? $rank : ($rank === 1 ? 2 : 1), 'result_status' => $bike === 1 ? $status : 'FINISHED',
                'fetched_at' => $date.' 18:00:00', 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    private function addTargetToStat01Run(int $runId, string $date, int $number): int
    {
        $now = now();
        $existingRace = DB::table('races')->where('external_race_id', 'target-race')->firstOrFail();
        $existingResult = DB::table('statistic_feature_results')->where('feature_run_id', $runId)->firstOrFail();
        $dayId = $this->day((int) DB::table('race_days')->where('id', $existingRace->race_day_id)->value('race_meeting_id'), $date);
        $raceId = DB::table('races')->insertGetId([
            'source' => 'batch02-test', 'external_race_id' => 'target-race-'.$number, 'race_day_id' => $dayId,
            'racetrack_id' => $existingRace->racetrack_id, 'race_date' => $date, 'race_number' => $number,
            'scheduled_start_at' => $date.' 12:00:00', 'sales_close_at' => $date.' 11:55:00',
            'race_type' => 'Ａ級予選', 'entrant_count' => 1, 'result_status' => 'UNAVAILABLE',
            'result_available' => false, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $entryId = DB::table('race_entries')->insertGetId([
            'race_id' => $raceId, 'player_id' => $existingResult->player_id, 'external_player_id' => 'target',
            'bike_number' => 1, 'frame_number' => 1, 'grade' => 'A1', 'race_score' => '80.00',
            'fetched_at' => $date.' 10:00:00', 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('statistic_feature_results')->insert([
            'feature_run_id' => $runId, 'stat_code' => 'STAT-01', 'calculation_version' => Stat01Calculator::CALCULATION_VERSION,
            'subject_type' => 'RACE_ENTRY', 'subject_key' => 'race_entry:'.$entryId, 'race_id' => $raceId,
            'race_entry_id' => $entryId, 'player_id' => $existingResult->player_id, 'bike_number' => 1,
            'status' => 'VALID', 'quality_status' => 'FULL', 'acquisition_mode' => 'BACKFILL',
            'input_as_of' => $date.' 11:55:00', 'source_fetched_at' => $date.' 10:00:00',
            'features' => '{}', 'evidence' => '{}', 'input_hash' => str_repeat((string) $number, 64),
            'calculated_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('statistic_feature_runs')->where('id', $runId)->update([
            'target_race_count' => 2,
            'processed_race_count' => 2,
            'target_entry_count' => 2,
            'success_count' => 2,
        ]);

        return (int) $raceId;
    }

    private function player(string $suffix): int
    {
        return (int) DB::table('players')->insertGetId([
            'source' => 'batch02-test', 'external_player_id' => $suffix, 'name' => 'Player '.$suffix,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function meeting(int $trackId, string $suffix, string $date): int
    {
        return (int) DB::table('race_meetings')->insertGetId([
            'source' => 'batch02-test', 'external_meeting_id' => $suffix, 'racetrack_id' => $trackId,
            'starts_on' => $date, 'ends_on' => $date, 'duration_days' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function day(int $meetingId, string $date): int
    {
        return (int) DB::table('race_days')->insertGetId([
            'race_meeting_id' => $meetingId,
            'external_race_day_id' => 'day-'.$meetingId.'-'.$date,
            'race_date' => $date,
            'day_number' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function sourceRows(): array
    {
        $tables = ['players', 'racetracks', 'race_meetings', 'race_days', 'races', 'race_entries', 'race_results'];
        $rows = [];
        foreach ($tables as $table) {
            $rows[$table] = DB::table($table)->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all();
        }

        return $rows;
    }
}
