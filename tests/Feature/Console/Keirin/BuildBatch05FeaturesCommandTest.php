<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Keirin;

use App\Domain\Keirin\Statistics\Calculators\Stat01Calculator;
use App\Domain\Keirin\Statistics\Calculators\Stat41Calculator;
use App\Domain\Keirin\Statistics\DTO\Batch05FeatureResultDto;
use App\Domain\Keirin\Statistics\DTO\Batch05RaceInputDto;
use App\Domain\Keirin\Statistics\Support\DeterministicJsonHasher;
use App\Domain\Keirin\Statistics\Support\StatisticalMath;
use App\Models\StatisticFeatureResult;
use App\Models\StatisticFeatureRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class BuildBatch05FeaturesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_stores_one_race_grain_result_for_complete_seven_and_nine_entry_races(): void
    {
        $runId = $this->sourceRun();
        $seven = $this->addSourceRace($runId, '2024-01-01', 1, [100, 95, 90, 85, 80, 75, 70]);
        $nine = $this->addSourceRace($runId, '2024-12-31', 2, [100, 98, 96, 94, 92, 90, 88, 86, 84]);
        $this->completeSourceRun($runId);

        $this->artisan('keirin:statistics:build-batch05', [
            '--stat01-run-id' => $runId,
            '--from' => '2024-01-01',
            '--to' => '2024-12-31',
        ])->expectsOutputToContain('target_races=2 target_entries=16')
            ->expectsOutputToContain('processed_races=2 result_count=2 valid=2')
            ->assertExitCode(0);

        $run = StatisticFeatureRun::query()->where('stat_code', Stat41Calculator::STAT_CODE)->sole();
        $this->assertSame(Stat41Calculator::CALCULATION_VERSION, $run->calculation_version);
        $this->assertSame(2, $run->target_race_count);
        $this->assertSame(16, $run->target_entry_count);
        $this->assertSame(2, $run->processed_race_count);
        $this->assertSame(2, $run->results()->count());
        $this->assertSame('SUCCEEDED', $run->status);
        foreach ($run->results()->orderBy('race_id')->get() as $result) {
            $this->assertSame('RACE', $result->subject_type);
            $this->assertSame('race:'.$result->race_id, $result->subject_key);
            $this->assertNull($result->race_entry_id);
            $this->assertNull($result->player_id);
            $this->assertNull($result->opponent_player_id);
            $this->assertNull($result->bike_number);
            $this->assertNull($result->raw_points);
            $this->assertNull($result->confidence);
            $this->assertNull($result->effective_points);
            $this->assertNull($result->features['RACE_COMPETITIVENESS_SCORE']);
        }
        $this->assertSame(7, $run->results()->where('race_id', $seven)->sole()->features['RACE_CONTEXT']['actual_entry_count']);
        $this->assertSame(9, $run->results()->where('race_id', $nine)->sole()->features['RACE_CONTEXT']['actual_entry_count']);
        $this->assertSame(0, StatisticFeatureRun::query()->where('status', 'RUNNING')->count());
    }

    public function test_partial_stat01_score_coverage_is_preserved_without_reading_current_race_entries(): void
    {
        $runId = $this->sourceRun();
        $raceId = $this->addSourceRace($runId, '2024-02-01', 1, [90, 88, 86, 84, 82, 80, 'invalid']);
        $this->completeSourceRun($runId);

        $this->artisan('keirin:statistics:build-batch05', [
            '--stat01-run-id' => $runId,
            '--race-id' => $raceId,
        ])->expectsOutputToContain('partial=1')->assertExitCode(0);

        $result = StatisticFeatureResult::query()->where('stat_code', Stat41Calculator::STAT_CODE)->sole();
        $this->assertSame('PARTIAL', $result->status);
        $this->assertSame('PARTIAL', $result->quality_status);
        $this->assertSame(6, $result->features['SCORE_COVERAGE']['usable_score_count']);
        $this->assertSame(1, $result->features['SCORE_COVERAGE']['invalid_score_count']);
        $this->assertSame('PARTIAL_PLAYER_SCORES', $result->evidence['reason']);
    }

    public function test_dry_run_writes_nothing_and_incomplete_source_run_is_rejected(): void
    {
        $runId = $this->sourceRun();
        $raceId = $this->addSourceRace($runId, '2024-03-01', 1, [90, 88, 86, 84, 82]);
        $this->completeSourceRun($runId);
        $before = $this->statisticCounts();

        $this->artisan('keirin:statistics:build-batch05', [
            '--stat01-run-id' => $runId,
            '--race-id' => $raceId,
            '--dry-run' => true,
        ])->expectsOutputToContain('run=dry-run')->assertExitCode(0);
        $this->assertSame($before, $this->statisticCounts());

        StatisticFeatureRun::query()->findOrFail($runId)->forceFill(['error_count' => 1])->save();
        $this->artisan('keirin:statistics:build-batch05', [
            '--stat01-run-id' => $runId,
            '--race-id' => $raceId,
            '--dry-run' => true,
        ])->expectsOutputToContain('specified STAT-01 run was not complete')->assertExitCode(1);
    }

    public function test_null_or_mixed_input_as_of_fails_preflight_before_a_batch05_run_is_created(): void
    {
        $runId = $this->sourceRun();
        $first = $this->addSourceRace($runId, '2024-04-01', 1, [90, 88, 86, 84, 82]);
        $second = $this->addSourceRace($runId, '2024-04-02', 1, [91, 89, 87, 85, 83]);
        $this->completeSourceRun($runId);
        DB::table('statistic_feature_results')
            ->where('feature_run_id', $runId)->where('race_id', $second)->orderBy('id')->limit(1)
            ->update(['input_as_of' => null]);

        $this->artisan('keirin:statistics:build-batch05', [
            '--stat01-run-id' => $runId, '--from' => '2024-04-01', '--to' => '2024-04-02',
        ])->expectsOutputToContain('missing STAT-01 input_as_of')->assertExitCode(1);
        $this->assertSame(0, StatisticFeatureRun::query()->where('stat_code', Stat41Calculator::STAT_CODE)->count());

        DB::table('statistic_feature_results')
            ->where('feature_run_id', $runId)->where('race_id', $second)
            ->update(['input_as_of' => '2024-04-02 10:00:00']);
        DB::table('statistic_feature_results')
            ->where('feature_run_id', $runId)->where('race_id', $first)->orderBy('id')->limit(1)
            ->update(['input_as_of' => '2024-04-01 10:01:00']);

        $this->artisan('keirin:statistics:build-batch05', [
            '--stat01-run-id' => $runId, '--from' => '2024-04-01', '--to' => '2024-04-02',
        ])->expectsOutputToContain('inconsistent STAT-01 input_as_of')->assertExitCode(1);
        $this->assertSame(0, StatisticFeatureRun::query()->where('stat_code', Stat41Calculator::STAT_CODE)->count());
    }

    public function test_keyset_chunk_boundaries_have_no_duplicates_or_omissions_and_are_chunk_invariant(): void
    {
        $runId = $this->sourceRun();
        foreach (range(1, 12) as $number) {
            $this->addSourceRace($runId, '2024-05-'.sprintf('%02d', $number), $number, [90, 88, 86, 84, 82]);
        }
        $this->completeSourceRun($runId);

        $this->runStored($runId, 1);
        $small = $this->latestOutputSignature();
        $this->runStored($runId, 200);
        $large = $this->latestOutputSignature();

        $this->assertCount(12, $small);
        $this->assertSame($small, $large);
        $this->assertSame(12, count(array_unique(array_column($large, 'race_id'))));
    }

    public function test_one_race_failure_does_not_stop_later_working_batches_and_run_finishes(): void
    {
        $runId = $this->sourceRun();
        $failedRaceId = $this->addSourceRace($runId, '2024-06-01', 1, [90, 88, 86, 84, 82]);
        $lastRaceId = 0;
        foreach (range(2, 7) as $day) {
            $lastRaceId = $this->addSourceRace($runId, '2024-06-'.sprintf('%02d', $day), $day, [91, 89, 87, 85, 83]);
        }
        $this->completeSourceRun($runId);
        $this->app->bind(Stat41Calculator::class, function () use ($failedRaceId): Stat41Calculator {
            return new class($failedRaceId, new StatisticalMath, new DeterministicJsonHasher) extends Stat41Calculator
            {
                public function __construct(
                    private readonly int $failedRaceId,
                    StatisticalMath $math,
                    DeterministicJsonHasher $hasher,
                ) {
                    parent::__construct($math, $hasher);
                }

                public function calculate(Batch05RaceInputDto $race): Batch05FeatureResultDto
                {
                    if ($race->raceId === $this->failedRaceId) {
                        throw new RuntimeException('synthetic Batch05 race failure');
                    }

                    return parent::calculate($race);
                }
            };
        });

        $this->artisan('keirin:statistics:build-batch05', [
            '--stat01-run-id' => $runId, '--from' => '2024-06-01', '--to' => '2024-06-07', '--chunk' => 1,
        ])->assertExitCode(1);

        $run = StatisticFeatureRun::query()->where('stat_code', Stat41Calculator::STAT_CODE)->sole();
        $this->assertSame(1, $run->error_count);
        $this->assertSame(6, $run->results()->count());
        $this->assertDatabaseHas('statistic_feature_run_items', ['feature_run_id' => $run->id, 'race_id' => $failedRaceId, 'status' => 'FAILED']);
        $this->assertDatabaseHas('statistic_feature_run_items', ['feature_run_id' => $run->id, 'race_id' => $lastRaceId, 'status' => 'SUCCEEDED']);
        $this->assertNotNull($run->finished_at);
        $this->assertSame(0, StatisticFeatureRun::query()->where('status', 'RUNNING')->count());
    }

    public function test_target_loading_does_not_query_current_entries_results_payouts_or_scraping_tables(): void
    {
        $runId = $this->sourceRun();
        foreach (range(1, 7) as $day) {
            $this->addSourceRace($runId, '2024-07-'.sprintf('%02d', $day), $day, [90, 88, 86, 84, 82]);
        }
        $this->completeSourceRun($runId);
        $forbidden = [];
        DB::listen(function ($query) use (&$forbidden): void {
            foreach (['race_entries', 'race_results', 'race_payouts', 'scraping_fetch_logs'] as $table) {
                if (preg_match('/(?:from|join)\s+["`]?'.$table.'["`]?\b/i', $query->sql) === 1) {
                    $forbidden[] = $query->sql;
                }
            }
        });

        $this->artisan('keirin:statistics:build-batch05', [
            '--stat01-run-id' => $runId, '--from' => '2024-07-01', '--to' => '2024-07-07', '--dry-run' => true,
        ])->assertExitCode(0);

        $this->assertSame([], $forbidden);
    }

    public function test_current_race_entry_score_changes_do_not_change_the_same_stat01_snapshot_output(): void
    {
        $runId = $this->sourceRun();
        $raceId = $this->addSourceRace($runId, '2024-08-01', 1, [90, 88, 86, 84, 82]);
        foreach (range(1, 5) as $bike) {
            DB::table('race_entries')->insert([
                'race_id' => $raceId,
                'bike_number' => $bike,
                'race_score' => 10 + $bike,
                'fetched_at' => '2024-08-01 10:00:00',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $this->completeSourceRun($runId);

        $this->artisan('keirin:statistics:build-batch05', [
            '--stat01-run-id' => $runId, '--race-id' => $raceId,
        ])->assertExitCode(0);
        $first = StatisticFeatureResult::query()->where('stat_code', Stat41Calculator::STAT_CODE)->latest('id')->firstOrFail();

        DB::table('race_entries')->where('race_id', $raceId)->update(['race_score' => 120]);
        $this->artisan('keirin:statistics:build-batch05', [
            '--stat01-run-id' => $runId, '--race-id' => $raceId,
        ])->assertExitCode(0);
        $second = StatisticFeatureResult::query()->where('stat_code', Stat41Calculator::STAT_CODE)->latest('id')->firstOrFail();

        $this->assertSame($first->input_hash, $second->input_hash);
        $this->assertSame($first->features, $second->features);
        $this->assertEquals(86.0, $second->features['SCORE_DISTRIBUTION']['mean']);
    }

    private function sourceRun(): int
    {
        return (int) StatisticFeatureRun::query()->create([
            'run_uuid' => fake()->uuid(),
            'stat_code' => Stat01Calculator::STAT_CODE,
            'calculation_version' => Stat01Calculator::CALCULATION_VERSION,
            'mode' => 'BACKFILL',
            'status' => 'SUCCEEDED',
            'input_as_of_policy' => 'test',
            'parameters' => [],
            'target_race_count' => 0,
            'processed_race_count' => 0,
            'target_entry_count' => 0,
            'success_count' => 0,
            'partial_count' => 0,
            'missing_count' => 0,
            'invalid_count' => 0,
            'error_count' => 0,
            'started_at' => now(),
            'finished_at' => now(),
        ])->id;
    }

    /** @param list<mixed> $scores */
    private function addSourceRace(int $runId, string $date, int $number, array $scores): int
    {
        $trackId = (int) (DB::table('racetracks')->value('id') ?: DB::table('racetracks')->insertGetId([
            'source' => 'batch05-test', 'external_track_id' => '01', 'name' => 'Test track',
            'created_at' => now(), 'updated_at' => now(),
        ]));
        $meetingId = (int) DB::table('race_meetings')->insertGetId([
            'source' => 'batch05-test', 'external_meeting_id' => 'meeting-'.$date.'-'.$number,
            'racetrack_id' => $trackId, 'starts_on' => $date, 'ends_on' => $date, 'duration_days' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $dayId = (int) DB::table('race_days')->insertGetId([
            'race_meeting_id' => $meetingId, 'external_race_day_id' => 'day-'.$date.'-'.$number,
            'race_date' => $date, 'day_number' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $raceId = (int) DB::table('races')->insertGetId([
            'source' => 'batch05-test', 'external_race_id' => 'race-'.$date.'-'.$number,
            'race_day_id' => $dayId, 'racetrack_id' => $trackId, 'race_date' => $date,
            'race_number' => $number, 'entrant_count' => count($scores),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ($scores as $index => $score) {
            $available = is_numeric($score) && (float) $score > 0;
            StatisticFeatureResult::query()->create([
                'feature_run_id' => $runId,
                'stat_code' => Stat01Calculator::STAT_CODE,
                'calculation_version' => Stat01Calculator::CALCULATION_VERSION,
                'subject_type' => 'RACE_ENTRY',
                'subject_key' => 'race_entry:'.($raceId * 10 + $index + 1),
                'race_id' => $raceId,
                'race_entry_id' => $raceId * 10 + $index + 1,
                'player_id' => $index === count($scores) - 1 ? null : $index + 1,
                'bike_number' => $index + 1,
                'status' => $available ? 'VALID' : 'INVALID_INPUT',
                'quality_status' => $available ? 'FULL' : 'PARTIAL',
                'acquisition_mode' => 'BACKFILL',
                'input_as_of' => $date.' 10:00:00',
                'source_fetched_at' => $date.' 09:00:00',
                'features' => [
                    'RACE_SCORE_RAW' => $score,
                    'RACE_SCORE_AVAILABLE' => $available,
                    'ENTRANT_COUNT' => count($scores),
                ],
                'evidence' => [
                    'expected_entrant_count' => count($scores),
                    'race_input_hash' => hash('sha256', $raceId.'-'.$index),
                ],
                'input_hash' => hash('sha256', 'entry-'.$raceId.'-'.$index),
                'calculated_at' => now(),
            ]);
        }

        return $raceId;
    }

    private function completeSourceRun(int $runId): void
    {
        $entries = StatisticFeatureResult::query()->where('feature_run_id', $runId)->count();
        $races = StatisticFeatureResult::query()->where('feature_run_id', $runId)->distinct()->count('race_id');
        StatisticFeatureRun::query()->findOrFail($runId)->forceFill([
            'target_race_count' => $races,
            'processed_race_count' => $races,
            'target_entry_count' => $entries,
            'success_count' => $entries,
        ])->save();
    }

    private function runStored(int $runId, int $chunk): void
    {
        $this->artisan('keirin:statistics:build-batch05', [
            '--stat01-run-id' => $runId, '--from' => '2024-01-01', '--to' => '2024-12-31', '--chunk' => $chunk,
        ])->assertExitCode(0);
    }

    /** @return list<array{race_id:int,input_hash:string}> */
    private function latestOutputSignature(): array
    {
        $runId = (int) StatisticFeatureRun::query()->where('stat_code', Stat41Calculator::STAT_CODE)->latest('id')->value('id');

        return StatisticFeatureResult::query()->where('feature_run_id', $runId)->orderBy('race_id')->get()
            ->map(fn (StatisticFeatureResult $result): array => ['race_id' => $result->race_id, 'input_hash' => $result->input_hash])
            ->all();
    }

    /** @return array{runs:int,items:int,results:int} */
    private function statisticCounts(): array
    {
        return [
            'runs' => DB::table('statistic_feature_runs')->count(),
            'items' => DB::table('statistic_feature_run_items')->count(),
            'results' => DB::table('statistic_feature_results')->count(),
        ];
    }
}
