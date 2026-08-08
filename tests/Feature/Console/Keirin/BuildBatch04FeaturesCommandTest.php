<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Keirin;

use App\Domain\Keirin\Statistics\Calculators\Stat01Calculator;
use App\Domain\Keirin\Statistics\Calculators\Stat39Calculator;
use App\Domain\Keirin\Statistics\DTO\Batch04BuildOptionsDto;
use App\Domain\Keirin\Statistics\DTO\Batch04FeatureResultDto;
use App\Domain\Keirin\Statistics\DTO\Batch04PositionHistoryContextDto;
use App\Domain\Keirin\Statistics\DTO\Batch04RaceInputDto;
use App\Domain\Keirin\Statistics\DTO\Batch04TargetEntryDto;
use App\Domain\Keirin\Statistics\Repositories\Batch04TargetRepository;
use App\Domain\Keirin\Statistics\Support\Batch04CalculatorSupport;
use App\Models\StatisticFeatureResult;
use App\Models\StatisticFeatureRun;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;
use UnexpectedValueException;

class BuildBatch04FeaturesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_builds_two_auditable_runs_with_position_and_direct_pair_history(): void
    {
        [$runId, $targetRaceId] = $this->fixture();
        $sourceBefore = $this->sourceRows();
        $scrapingBefore = DB::table('scraping_fetch_logs')->count();

        $this->artisan('keirin:statistics:build-batch04', [
            '--stat01-run-id' => $runId,
            '--history-from' => '2023-01-01',
            '--race-id' => $targetRaceId,
        ])->expectsOutputToContain('target_races=1 target_entries=7')->assertExitCode(0);

        $runs = StatisticFeatureRun::query()->where('id', '!=', $runId)->orderBy('stat_code')->get();
        $this->assertSame(['STAT-39', 'STAT-42'], $runs->pluck('stat_code')->all());
        $this->assertSame(['STAT-39-existing-db-v1', 'STAT-42-existing-db-v1'], $runs->pluck('calculation_version')->all());
        $this->assertCount(1, $runs->pluck('parameters')->pluck('batch_execution_uuid')->unique());
        $this->assertSame(14, StatisticFeatureResult::query()->where('feature_run_id', '!=', $runId)->count());

        foreach (StatisticFeatureResult::query()->where('feature_run_id', '!=', $runId)->get() as $result) {
            $this->assertNull($result->opponent_player_id);
            $this->assertNull($result->raw_points);
            $this->assertNull($result->confidence);
            $this->assertNull($result->effective_points);
            $this->assertSame('BACKFILLED_FINAL_RESULT', $result->evidence['history_result_mode']);
        }

        $stat39 = StatisticFeatureResult::query()->where('feature_run_id', '!=', $runId)->where('stat_code', 'STAT-39')->where('bike_number', 1)->sole();
        $this->assertSame(2, $stat39->features['FIELD_BIKE']['sample_count']);
        $this->assertSame(14, $stat39->features['FIELD_BASELINE']['sample_count']);
        $this->assertSame(1, $stat39->features['TRACK_FIELD_BIKE']['sample_count']);
        $this->assertSame(1, $stat39->features['FIELD_BIKE']['residual_sample_count']);
        $this->assertNull($stat39->features['POSITION_BIAS_SCORE']);

        $stat42 = StatisticFeatureResult::query()->where('feature_run_id', '!=', $runId)->where('stat_code', 'STAT-42')->where('bike_number', 1)->sole();
        $this->assertSame(6, $stat42->features['CURRENT_FIELD_CONTEXT']['coentrant_count']);
        $this->assertSame(6, $stat42->features['HEAD_TO_HEAD_SUMMARY']['opponents_with_direct_history_count']);
        $this->assertSame(2, $stat42->features['HEAD_TO_HEAD_SUMMARY']['unique_direct_source_race_count']);
        $this->assertSame(12, $stat42->features['HEAD_TO_HEAD_SUMMARY']['sum_pair_direct_meeting_count']);
        $firstPair = $stat42->features['HEAD_TO_HEAD_BY_COENTRANT'][0]['DIRECT_HISTORY'];
        $this->assertSame(2, $firstPair['normal_direct_meeting_count']);
        $this->assertSame(1, $firstPair['relative_expectation_residual_sample_count']);
        $this->assertNull($stat42->features['MATCHUP_ADJUSTMENT']);

        $this->assertSame($sourceBefore, $this->sourceRows());
        $this->assertSame($scrapingBefore, DB::table('scraping_fetch_logs')->count());
        $this->assertSame(0, StatisticFeatureRun::query()->where('status', 'RUNNING')->count());
    }

    public function test_dry_run_writes_nothing_and_incomplete_stat01_is_rejected(): void
    {
        [$runId, $targetRaceId] = $this->fixture();
        $before = $this->statisticCounts();

        $this->artisan('keirin:statistics:build-batch04', [
            '--stat01-run-id' => $runId,
            '--history-from' => '2023-01-01',
            '--race-id' => $targetRaceId,
            '--dry-run' => true,
        ])->expectsOutputToContain('run=dry-run')->expectsOutputToContain('stat_code=STAT-42')->assertExitCode(0);
        $this->assertSame($before, $this->statisticCounts());

        StatisticFeatureRun::query()->findOrFail($runId)->forceFill(['error_count' => 1])->save();
        $this->artisan('keirin:statistics:build-batch04', [
            '--stat01-run-id' => $runId,
            '--history-from' => '2023-01-01',
            '--race-id' => $targetRaceId,
            '--dry-run' => true,
        ])->expectsOutputToContain('specified STAT-01 run was not complete')->assertExitCode(1);
    }

    public function test_stored_build_rejects_a_null_target_input_as_of_before_writing_batch04_statistics(): void
    {
        [$runId, $targetRaceId] = $this->fixture();
        $this->nullFirstTargetInputAsOf($runId, $targetRaceId);
        $before = $this->statisticCounts();

        $this->artisan('keirin:statistics:build-batch04', [
            '--stat01-run-id' => $runId,
            '--history-from' => '2023-01-01',
            '--race-id' => $targetRaceId,
        ])->expectsOutputToContain('missing STAT-01 input_as_of')->assertExitCode(1);

        $this->assertSame($before, $this->statisticCounts());
        $this->assertSame(0, StatisticFeatureRun::query()->where('id', '!=', $runId)->count());
    }

    public function test_normal_and_null_targets_fail_as_one_batch_before_the_earlier_normal_race_is_processed(): void
    {
        [$runId] = $this->fixture();
        $nullRaceId = $this->addTarget($runId, '2024-01-12', 2);
        $this->nullFirstTargetInputAsOf($runId, $nullRaceId);
        $before = $this->statisticCounts();

        $this->artisan('keirin:statistics:build-batch04', [
            '--stat01-run-id' => $runId,
            '--history-from' => '2023-01-01',
            '--from' => '2024-01-10',
            '--to' => '2024-01-12',
            '--chunk' => 1,
        ])->expectsOutputToContain('missing STAT-01 input_as_of')->assertExitCode(1);

        $this->assertSame($before, $this->statisticCounts());
        $this->assertSame(0, StatisticFeatureRun::query()->where('id', '!=', $runId)->count());
    }

    public function test_repository_defense_rejects_null_before_it_can_become_the_current_time(): void
    {
        [$runId, $targetRaceId] = $this->fixture();
        $this->nullFirstTargetInputAsOf($runId, $targetRaceId);
        $options = new Batch04BuildOptionsDto(
            $runId,
            new DateTimeImmutable('2023-01-01'),
            null,
            null,
            $targetRaceId,
            200,
            true,
        );

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('had missing input_as_of');
        iterator_to_array($this->app->make(Batch04TargetRepository::class)->workingBatches($options));
    }

    public function test_chunk_boundaries_preserve_outputs_and_history_queries_are_not_per_target_entry(): void
    {
        [$runId] = $this->fixture();
        foreach (range(2, 7) as $offset) {
            $this->addTarget($runId, '2024-01-'.sprintf('%02d', 10 + $offset), $offset);
        }

        $this->runStored($runId, 1);
        $small = $this->outputSignature($runId);

        $historyQueries = 0;
        $targetEntryQueries = 0;
        DB::listen(function ($query) use (&$historyQueries, &$targetEntryQueries): void {
            if (str_contains($query->sql, 'history_entries')) {
                $historyQueries++;
            }
            if (str_contains($query->sql, 'source_entries')) {
                $targetEntryQueries++;
            }
        });
        $this->runStored($runId, 200);
        $large = $this->outputSignature($runId, latestBatch: true);

        $this->assertCount(98, $small);
        $this->assertSame($small, $large);
        $this->assertLessThan(7, $historyQueries);
        $this->assertSame(2, $targetEntryQueries);
        $this->assertSame(0, StatisticFeatureRun::query()->where('status', 'RUNNING')->count());
    }

    public function test_one_stat_failure_does_not_stop_the_other_stat_or_later_race(): void
    {
        [$runId, $failedRaceId] = $this->fixture();
        $successfulRaceId = $this->addTarget($runId, '2024-01-12', 2);
        $this->app->bind(Stat39Calculator::class, function () use ($failedRaceId): Stat39Calculator {
            return new class($failedRaceId, $this->app->make(Batch04CalculatorSupport::class)) extends Stat39Calculator
            {
                public function __construct(private readonly int $failedRaceId, Batch04CalculatorSupport $support)
                {
                    parent::__construct($support);
                }

                public function calculate(
                    Batch04TargetEntryDto $target,
                    Batch04RaceInputDto $race,
                    Batch04PositionHistoryContextDto $positionHistory,
                    array $pairHistories,
                    Batch04BuildOptionsDto $options,
                    string $batchExecutionUuid,
                ): Batch04FeatureResultDto {
                    if ($target->raceId === $this->failedRaceId) {
                        throw new RuntimeException('synthetic STAT-39 failure');
                    }

                    return parent::calculate($target, $race, $positionHistory, $pairHistories, $options, $batchExecutionUuid);
                }
            };
        });

        $this->artisan('keirin:statistics:build-batch04', [
            '--stat01-run-id' => $runId,
            '--history-from' => '2023-01-01',
            '--from' => '2024-01-10',
            '--to' => '2024-01-12',
        ])->assertExitCode(1);

        $stat39 = StatisticFeatureRun::query()->where('stat_code', 'STAT-39')->sole();
        $this->assertSame(1, $stat39->error_count);
        $this->assertDatabaseHas('statistic_feature_run_items', ['feature_run_id' => $stat39->id, 'race_id' => $failedRaceId, 'status' => 'FAILED']);
        $this->assertDatabaseHas('statistic_feature_run_items', ['feature_run_id' => $stat39->id, 'race_id' => $successfulRaceId, 'status' => 'SUCCEEDED']);

        $stat42 = StatisticFeatureRun::query()->where('stat_code', 'STAT-42')->sole();
        $this->assertSame(0, $stat42->error_count);
        $this->assertSame(14, $stat42->results()->count());
        $this->assertNotNull($stat42->finished_at);
        $this->assertSame(0, StatisticFeatureRun::query()->where('status', 'RUNNING')->count());
    }

    /** @return array{int,int} */
    private function fixture(): array
    {
        $trackId = (int) DB::table('racetracks')->insertGetId([
            'source' => 'batch04-test', 'external_track_id' => '01', 'name' => 'Track 1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $otherTrackId = (int) DB::table('racetracks')->insertGetId([
            'source' => 'batch04-test', 'external_track_id' => '02', 'name' => 'Track 2',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $players = array_map(fn (int $number): int => $this->player($number), range(1, 7));
        $historyMeeting = $this->meeting($trackId, 'history', '2023-01-05');
        $otherHistoryMeeting = $this->meeting($otherTrackId, 'other-history', '2023-02-05');
        $targetMeeting = $this->meeting($trackId, 'target', '2024-01-10');
        $futureMeeting = $this->meeting($trackId, 'future', '2024-01-11');
        $this->race($historyMeeting, $trackId, '2023-01-05', 1, $players, true);
        $incompleteScoreRaceId = $this->race($otherHistoryMeeting, $otherTrackId, '2023-02-05', 1, $players, true);
        DB::table('race_entries')->where('race_id', $incompleteScoreRaceId)->where('bike_number', 7)->update(['race_score' => null]);
        $targetRaceId = $this->race($targetMeeting, $trackId, '2024-01-10', 1, $players, false);
        $this->addResults($targetRaceId, '2024-01-10');
        $this->race($futureMeeting, $trackId, '2024-01-11', 1, $players, true);
        $runId = $this->stat01Run();
        $this->attachStat01Race($runId, $targetRaceId, '2024-01-10');

        return [$runId, $targetRaceId];
    }

    /** @param list<int> $players */
    private function race(int $meetingId, int $trackId, string $date, int $number, array $players, bool $withResults): int
    {
        $raceId = (int) DB::table('races')->insertGetId([
            'source' => 'batch04-test', 'external_race_id' => 'race-'.$date.'-'.$number,
            'race_day_id' => $this->day($meetingId, $date, $number), 'racetrack_id' => $trackId,
            'race_date' => $date, 'race_number' => $number, 'scheduled_start_at' => $date.' 12:00:00',
            'sales_close_at' => $date.' 11:55:00', 'name' => 'A test race', 'race_type' => 'Ａ級一般',
            'entrant_count' => count($players), 'result_status' => $withResults ? 'CONFIRMED' : 'UNAVAILABLE',
            'result_available' => $withResults, 'result_confirmed_at' => $withResults ? $date.' 18:00:00' : null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ($players as $index => $playerId) {
            $bike = $index + 1;
            $entryId = (int) DB::table('race_entries')->insertGetId([
                'race_id' => $raceId, 'player_id' => $playerId, 'external_player_id' => 'player-'.$playerId,
                'bike_number' => $bike, 'frame_number' => min($bike, 6), 'grade' => 'A1',
                'race_score' => (string) (90 - $index), 'fetched_at' => $date.' 10:00:00',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            if ($withResults) {
                DB::table('race_results')->insert([
                    'race_id' => $raceId, 'race_entry_id' => $entryId, 'player_id' => $playerId,
                    'bike_number' => $bike, 'rank' => $bike, 'result_status' => 'FINISHED',
                    'fetched_at' => $date.' 18:00:00', 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }

        return $raceId;
    }

    private function addTarget(int $runId, string $date, int $number): int
    {
        $players = DB::table('players')->orderBy('id')->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        $trackId = (int) DB::table('racetracks')->value('id');
        $meetingId = $this->meeting($trackId, 'target-'.$date, $date);
        $raceId = $this->race($meetingId, $trackId, $date, $number, $players, false);
        $this->attachStat01Race($runId, $raceId, $date);

        return $raceId;
    }

    private function addResults(int $raceId, string $date): void
    {
        DB::table('races')->where('id', $raceId)->update([
            'result_status' => 'CONFIRMED', 'result_available' => true,
            'result_confirmed_at' => $date.' 18:00:00', 'updated_at' => now(),
        ]);
        foreach (DB::table('race_entries')->where('race_id', $raceId)->get() as $entry) {
            DB::table('race_results')->insert([
                'race_id' => $raceId, 'race_entry_id' => $entry->id, 'player_id' => $entry->player_id,
                'bike_number' => $entry->bike_number, 'rank' => $entry->bike_number, 'result_status' => 'FINISHED',
                'fetched_at' => $date.' 18:00:00', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    private function nullFirstTargetInputAsOf(int $runId, int $raceId): void
    {
        $resultId = DB::table('statistic_feature_results')
            ->where('feature_run_id', $runId)
            ->where('race_id', $raceId)
            ->orderBy('race_entry_id')
            ->value('id');
        DB::table('statistic_feature_results')->where('id', $resultId)->update(['input_as_of' => null]);
    }

    private function stat01Run(): int
    {
        return (int) DB::table('statistic_feature_runs')->insertGetId([
            'run_uuid' => '00000000-0000-4000-8000-000000000401', 'stat_code' => 'STAT-01',
            'calculation_version' => Stat01Calculator::CALCULATION_VERSION, 'mode' => 'BACKFILL', 'status' => 'SUCCEEDED',
            'target_from' => '2024-01-10', 'target_to' => '2024-01-10', 'input_as_of_policy' => 'test',
            'parameters' => '{}', 'target_race_count' => 0, 'processed_race_count' => 0,
            'target_entry_count' => 0, 'success_count' => 0, 'started_at' => now(), 'finished_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function attachStat01Race(int $runId, int $raceId, string $date): void
    {
        $entries = DB::table('race_entries')->where('race_id', $raceId)->orderBy('bike_number')->get();
        foreach ($entries as $entry) {
            $bike = (int) $entry->bike_number;
            DB::table('statistic_feature_results')->insert([
                'feature_run_id' => $runId, 'stat_code' => 'STAT-01',
                'calculation_version' => Stat01Calculator::CALCULATION_VERSION, 'subject_type' => 'RACE_ENTRY',
                'subject_key' => 'race_entry:'.$entry->id, 'race_id' => $raceId, 'race_entry_id' => $entry->id,
                'player_id' => $entry->player_id, 'bike_number' => $bike, 'status' => 'VALID', 'quality_status' => 'FULL',
                'acquisition_mode' => 'BACKFILL', 'input_as_of' => $date.' 11:55:00',
                'source_fetched_at' => $date.' 10:00:00',
                'features' => json_encode([
                    'RACE_SCORE_RAW' => (float) $entry->race_score,
                    'RACE_SCORE_RANK' => $bike,
                    'RACE_SCORE_STRENGTH_PERCENTILE' => ($entries->count() - $bike) / ($entries->count() - 1),
                ], JSON_THROW_ON_ERROR),
                'evidence' => '{}', 'input_hash' => hash('sha256', $date.'-'.$entry->id),
                'calculated_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        DB::table('statistic_feature_runs')->where('id', $runId)->incrementEach([
            'target_race_count' => 1, 'processed_race_count' => 1,
            'target_entry_count' => $entries->count(), 'success_count' => $entries->count(),
        ]);
    }

    private function runStored(int $runId, int $chunk): void
    {
        $this->artisan('keirin:statistics:build-batch04', [
            '--stat01-run-id' => $runId, '--history-from' => '2023-01-01',
            '--from' => '2024-01-10', '--to' => '2024-01-17', '--chunk' => $chunk,
        ])->assertExitCode(0);
    }

    /** @return array<string, array<string, mixed>> */
    private function outputSignature(int $stat01RunId, bool $latestBatch = false): array
    {
        $runs = StatisticFeatureRun::query()->where('id', '!=', $stat01RunId)->orderBy('id')->get();
        if ($latestBatch) {
            $uuid = $runs->last()->parameters['batch_execution_uuid'];
            $runs = $runs->filter(fn (StatisticFeatureRun $run): bool => $run->parameters['batch_execution_uuid'] === $uuid);
        } else {
            $uuid = $runs->first()->parameters['batch_execution_uuid'];
            $runs = $runs->filter(fn (StatisticFeatureRun $run): bool => $run->parameters['batch_execution_uuid'] === $uuid);
        }
        $signature = [];
        foreach (StatisticFeatureResult::query()->whereIn('feature_run_id', $runs->pluck('id'))->get() as $result) {
            $evidence = $result->evidence;
            unset($evidence['batch_execution_uuid']);
            $signature[$result->stat_code.':'.$result->race_entry_id] = [
                'status' => $result->status, 'quality_status' => $result->quality_status,
                'features' => $result->features, 'evidence' => $evidence,
                'input_hash' => $result->input_hash,
            ];
        }
        ksort($signature);

        return $signature;
    }

    private function player(int $number): int
    {
        return (int) DB::table('players')->insertGetId([
            'source' => 'batch04-test', 'external_player_id' => 'player-'.$number,
            'name' => 'Player '.$number, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function meeting(int $trackId, string $suffix, string $date): int
    {
        return (int) DB::table('race_meetings')->insertGetId([
            'source' => 'batch04-test', 'external_meeting_id' => $suffix, 'racetrack_id' => $trackId,
            'starts_on' => $date, 'ends_on' => $date, 'duration_days' => 1, 'grade' => 'F1', 'day_kind' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function day(int $meetingId, string $date, int $number): int
    {
        return (int) DB::table('race_days')->insertGetId([
            'race_meeting_id' => $meetingId, 'external_race_day_id' => 'day-'.$meetingId.'-'.$date,
            'race_date' => $date, 'day_number' => $number, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function sourceRows(): array
    {
        $rows = [];
        foreach (['players', 'racetracks', 'race_meetings', 'race_days', 'races', 'race_entries', 'race_results'] as $table) {
            $rows[$table] = DB::table($table)->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all();
        }

        return $rows;
    }

    /** @return list<int> */
    private function statisticCounts(): array
    {
        return [
            DB::table('statistic_feature_runs')->count(),
            DB::table('statistic_feature_run_items')->count(),
            DB::table('statistic_feature_results')->count(),
        ];
    }
}
