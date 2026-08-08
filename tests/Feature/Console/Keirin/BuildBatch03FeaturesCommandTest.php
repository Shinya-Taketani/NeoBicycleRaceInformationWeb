<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Keirin;

use App\Domain\Keirin\Statistics\Calculators\Stat01Calculator;
use App\Domain\Keirin\Statistics\Calculators\Stat07Calculator;
use App\Domain\Keirin\Statistics\Calculators\Stat08Calculator;
use App\Domain\Keirin\Statistics\Calculators\Stat23Calculator;
use App\Domain\Keirin\Statistics\Calculators\Stat31Calculator;
use App\Domain\Keirin\Statistics\Calculators\Stat32Calculator;
use App\Domain\Keirin\Statistics\Calculators\Stat33Calculator;
use App\Domain\Keirin\Statistics\Contracts\Batch03Calculator;
use App\Domain\Keirin\Statistics\DTO\Batch03BuildOptionsDto;
use App\Domain\Keirin\Statistics\DTO\Batch03FeatureResultDto;
use App\Domain\Keirin\Statistics\DTO\Batch03TargetEntryDto;
use App\Domain\Keirin\Statistics\Repositories\Batch03HistoricalRaceRepository;
use App\Domain\Keirin\Statistics\Support\Batch03CalculatorSupport;
use App\Models\StatisticFeatureResult;
use App\Models\StatisticFeatureRun;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class BuildBatch03FeaturesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_builds_six_auditable_runs_and_preserves_source_and_scraping_tables(): void
    {
        [$runId, $targetRaceId] = $this->fixture();
        $sourceBefore = $this->sourceRows();
        $scrapingBefore = DB::table('scraping_fetch_logs')->count();

        $this->artisan('keirin:statistics:build-batch03', [
            '--stat01-run-id' => $runId,
            '--history-from' => '2023-01-01',
            '--race-id' => $targetRaceId,
        ])->expectsOutputToContain('target_races=1 target_entries=1')->assertExitCode(0);

        $runs = StatisticFeatureRun::query()->where('id', '!=', $runId)->orderBy('stat_code')->get();
        $this->assertSame(['STAT-07', 'STAT-08', 'STAT-23', 'STAT-31', 'STAT-32', 'STAT-33'], $runs->pluck('stat_code')->all());
        $this->assertCount(1, $runs->pluck('parameters')->pluck('batch_execution_uuid')->unique());
        $this->assertSame(6, StatisticFeatureResult::query()->where('feature_run_id', '!=', $runId)->count());
        foreach (StatisticFeatureResult::query()->where('feature_run_id', '!=', $runId)->get() as $result) {
            $this->assertNull($result->raw_points);
            $this->assertNull($result->confidence);
            $this->assertNull($result->effective_points);
            $this->assertSame('BACKFILLED_FINAL_RESULT', $result->evidence['history_result_mode']);
        }
        $stat33 = StatisticFeatureResult::query()
            ->where('feature_run_id', '!=', $runId)
            ->where('stat_code', 'STAT-33')
            ->sole();
        $this->assertTrue($stat33->evidence['previous_result_observed_by_app_as_of_input']);
        $this->assertFalse($stat33->evidence['official_result_availability_reconstructed']);
        $this->assertNotNull($stat33->evidence['previous_result_app_first_confirmed_at']);
        $this->assertSame(2, $stat33->features['CURRENT_MEETING_CONTEXT']['current_day_number']);
        $this->assertSame($sourceBefore, $this->sourceRows());
        $this->assertSame($scrapingBefore, DB::table('scraping_fetch_logs')->count());
    }

    public function test_dry_run_writes_no_statistics_and_incomplete_stat01_is_rejected(): void
    {
        [$runId, $targetRaceId] = $this->fixture();
        $before = $this->statisticCounts();

        $this->artisan('keirin:statistics:build-batch03', [
            '--stat01-run-id' => $runId,
            '--history-from' => '2023-01-01',
            '--race-id' => $targetRaceId,
            '--dry-run' => true,
        ])->expectsOutputToContain('run=dry-run')->expectsOutputToContain('stat_code=STAT-33')->assertExitCode(0);
        $this->assertSame($before, $this->statisticCounts());

        StatisticFeatureRun::query()->findOrFail($runId)->forceFill(['error_count' => 1])->save();
        $this->artisan('keirin:statistics:build-batch03', [
            '--stat01-run-id' => $runId,
            '--history-from' => '2023-01-01',
            '--race-id' => $targetRaceId,
            '--dry-run' => true,
        ])->expectsOutputToContain('specified STAT-01 run was not complete')->assertExitCode(1);
    }

    public function test_chunk_sizes_produce_identical_outputs_and_queries_are_not_per_target_entry(): void
    {
        [$runId] = $this->fixture();
        foreach (range(2, 6) as $number) {
            $this->addTarget($runId, '2024-01-'.sprintf('%02d', 10 + $number), $number);
        }
        $small = $this->calculatedOutputs($this->rangeOptions($runId, 1));
        $historyQueries = 0;
        $contextQueries = 0;
        DB::listen(function ($query) use (&$historyQueries, &$contextQueries): void {
            if (str_contains($query->sql, 'history_entries')) {
                $historyQueries++;
            } elseif (str_contains($query->sql, 'from "race_entries"')) {
                $contextQueries++;
            }
        });
        $large = $this->calculatedOutputs($this->rangeOptions($runId, 200));

        $this->assertCount(36, $small);
        $this->assertSame($small, $large);
        $this->assertSame(2, $historyQueries);
        $this->assertSame(2, $contextQueries);
        $this->assertLessThan(6, $historyQueries);
    }

    public function test_one_stat_race_failure_does_not_stop_other_stats_or_later_races(): void
    {
        [$runId, $failedRaceId] = $this->fixture();
        $successfulRaceId = $this->addTarget($runId, '2024-01-12', 2);
        $this->app->bind(Stat07Calculator::class, function () use ($failedRaceId): Stat07Calculator {
            return new class($failedRaceId, $this->app->make(Batch03CalculatorSupport::class)) extends Stat07Calculator
            {
                public function __construct(private readonly int $failedRaceId, Batch03CalculatorSupport $support)
                {
                    parent::__construct($support);
                }

                public function calculate(Batch03TargetEntryDto $target, array $histories, Batch03BuildOptionsDto $options, string $batchExecutionUuid): Batch03FeatureResultDto
                {
                    if ($target->raceId === $this->failedRaceId) {
                        throw new RuntimeException('synthetic STAT-07 failure');
                    }

                    return parent::calculate($target, $histories, $options, $batchExecutionUuid);
                }
            };
        });

        $this->artisan('keirin:statistics:build-batch03', [
            '--stat01-run-id' => $runId,
            '--history-from' => '2023-01-01',
            '--from' => '2024-01-10',
            '--to' => '2024-01-12',
        ])->assertExitCode(1);

        $stat07 = StatisticFeatureRun::query()->where('stat_code', 'STAT-07')->sole();
        $this->assertSame(1, $stat07->error_count);
        $this->assertDatabaseHas('statistic_feature_run_items', ['feature_run_id' => $stat07->id, 'race_id' => $failedRaceId, 'status' => 'FAILED']);
        $this->assertDatabaseHas('statistic_feature_run_items', ['feature_run_id' => $stat07->id, 'race_id' => $successfulRaceId]);
        foreach (['STAT-08', 'STAT-23', 'STAT-31', 'STAT-32', 'STAT-33'] as $statCode) {
            $run = StatisticFeatureRun::query()->where('stat_code', $statCode)->sole();
            $this->assertSame(0, $run->error_count);
            $this->assertSame(2, $run->results()->count());
            $this->assertNotNull($run->finished_at);
        }
        $this->assertSame(0, StatisticFeatureRun::query()->where('status', 'RUNNING')->count());
    }

    /** @return array{int,int} */
    private function fixture(): array
    {
        $now = now();
        $track1 = DB::table('racetracks')->insertGetId(['source' => 'batch03-test', 'external_track_id' => '01', 'name' => 'Track 1', 'created_at' => $now, 'updated_at' => $now]);
        $track2 = DB::table('racetracks')->insertGetId(['source' => 'batch03-test', 'external_track_id' => '02', 'name' => 'Track 2', 'created_at' => $now, 'updated_at' => $now]);
        $player = $this->player('target');
        $opponent = $this->player('opponent');
        $historyMeeting = $this->meeting($track1, 'history', '2024-01-01');
        $targetMeeting = $this->meeting($track1, 'target', '2024-01-08');
        $this->race($historyMeeting, $track1, '2024-01-01', 1, 1, 'Ａ級一般', $player, $opponent, 3);
        $this->race($historyMeeting, $track1, '2024-01-02', 2, 2, 'Ａ級準決勝', $player, $opponent, 3);
        $this->race($historyMeeting, $track2, '2024-01-03', 3, 3, 'Ａ級決勝', $player, $opponent, 1);
        $this->race($targetMeeting, $track1, '2024-01-09', 1, 1, 'Ａ級準決勝', $player, $opponent, 2);

        $targetRaceId = $this->targetRace($targetMeeting, $track1, '2024-01-10', 2, 2, $player);
        $entry = DB::table('race_entries')->where('race_id', $targetRaceId)->sole();
        $runId = DB::table('statistic_feature_runs')->insertGetId([
            'run_uuid' => '00000000-0000-4000-8000-000000000301', 'stat_code' => 'STAT-01',
            'calculation_version' => Stat01Calculator::CALCULATION_VERSION, 'mode' => 'BACKFILL', 'status' => 'SUCCEEDED',
            'target_from' => '2024-01-10', 'target_to' => '2024-01-10', 'input_as_of_policy' => 'test', 'parameters' => '{}',
            'target_race_count' => 1, 'processed_race_count' => 1, 'target_entry_count' => 1, 'success_count' => 1,
            'started_at' => $now, 'finished_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->stat01Result($runId, $targetRaceId, (int) $entry->id, $player, 1, '2024-01-10');

        return [(int) $runId, $targetRaceId];
    }

    private function race(int $meetingId, int $trackId, string $date, int $number, int $day, string $type, int $player, int $opponent, int $rank): void
    {
        $now = now();
        $raceId = DB::table('races')->insertGetId([
            'source' => 'batch03-test', 'external_race_id' => 'history-'.$date, 'race_day_id' => $this->day($meetingId, $date, $day),
            'racetrack_id' => $trackId, 'race_date' => $date, 'race_number' => $number, 'scheduled_start_at' => $date.' 12:00:00',
            'name' => '先固', 'race_type' => $type, 'entrant_count' => 2, 'result_status' => 'CONFIRMED', 'result_available' => true,
            'result_confirmed_at' => $date.' 18:00:00',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        foreach ([[$player, 1, '80.00', $rank], [$opponent, 2, '70.00', $rank === 1 ? 2 : 1]] as [$playerId, $bike, $score, $resultRank]) {
            $entryId = DB::table('race_entries')->insertGetId([
                'race_id' => $raceId, 'player_id' => $playerId, 'external_player_id' => 'p-'.$playerId, 'bike_number' => $bike,
                'frame_number' => $bike, 'grade' => 'A1', 'race_score' => $score, 'fetched_at' => $date.' 10:00:00',
                'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('race_results')->insert([
                'race_id' => $raceId, 'race_entry_id' => $entryId, 'player_id' => $playerId, 'bike_number' => $bike,
                'rank' => $resultRank, 'result_status' => 'FINISHED', 'fetched_at' => $date.' 18:00:00',
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    private function targetRace(int $meetingId, int $trackId, string $date, int $number, int $day, int $player): int
    {
        $now = now();
        $raceId = DB::table('races')->insertGetId([
            'source' => 'batch03-test', 'external_race_id' => 'target-'.$date.'-'.$number, 'race_day_id' => $this->day($meetingId, $date, $day),
            'racetrack_id' => $trackId, 'race_date' => $date, 'race_number' => $number, 'scheduled_start_at' => $date.' 12:00:00',
            'sales_close_at' => $date.' 11:55:00', 'name' => '先固', 'race_type' => 'Ａ級決勝', 'entrant_count' => 1,
            'result_status' => 'UNAVAILABLE', 'result_available' => false, 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('race_entries')->insert([
            'race_id' => $raceId, 'player_id' => $player, 'external_player_id' => 'target', 'bike_number' => 1,
            'frame_number' => 1, 'grade' => 'A1', 'race_score' => '80.00', 'fetched_at' => $date.' 10:00:00',
            'created_at' => $now, 'updated_at' => $now,
        ]);

        return (int) $raceId;
    }

    private function addTarget(int $runId, string $date, int $number): int
    {
        $baseRace = DB::table('races')->where('external_race_id', 'target-2024-01-10-2')->firstOrFail();
        $player = (int) DB::table('statistic_feature_results')->where('feature_run_id', $runId)->value('player_id');
        $meeting = (int) DB::table('race_days')->where('id', $baseRace->race_day_id)->value('race_meeting_id');
        $raceId = $this->targetRace($meeting, (int) $baseRace->racetrack_id, $date, $number, min($number, 3), $player);
        $entryId = (int) DB::table('race_entries')->where('race_id', $raceId)->value('id');
        $this->stat01Result($runId, $raceId, $entryId, $player, 1, $date);
        DB::table('statistic_feature_runs')->where('id', $runId)->incrementEach([
            'target_race_count' => 1, 'processed_race_count' => 1, 'target_entry_count' => 1, 'success_count' => 1,
        ]);

        return $raceId;
    }

    private function stat01Result(int $runId, int $raceId, int $entryId, int $player, int $bike, string $date): void
    {
        DB::table('statistic_feature_results')->insert([
            'feature_run_id' => $runId, 'stat_code' => 'STAT-01', 'calculation_version' => Stat01Calculator::CALCULATION_VERSION,
            'subject_type' => 'RACE_ENTRY', 'subject_key' => 'race_entry:'.$entryId, 'race_id' => $raceId,
            'race_entry_id' => $entryId, 'player_id' => $player, 'bike_number' => $bike, 'status' => 'VALID',
            'quality_status' => 'FULL', 'acquisition_mode' => 'BACKFILL', 'input_as_of' => $date.' 11:55:00',
            'source_fetched_at' => $date.' 10:00:00', 'features' => '{}', 'evidence' => '{}',
            'input_hash' => hash('sha256', $date.'-'.$entryId), 'calculated_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function rangeOptions(int $runId, int $chunk): Batch03BuildOptionsDto
    {
        return new Batch03BuildOptionsDto($runId, new DateTimeImmutable('2023-01-01'), new DateTimeImmutable('2024-01-10'), new DateTimeImmutable('2024-01-16'), null, $chunk, true);
    }

    /** @return array<string,array<string,mixed>> */
    private function calculatedOutputs(Batch03BuildOptionsDto $options): array
    {
        /** @var list<Batch03Calculator> $calculators */
        $calculators = [
            $this->app->make(Stat07Calculator::class), $this->app->make(Stat08Calculator::class),
            $this->app->make(Stat23Calculator::class), $this->app->make(Stat31Calculator::class),
            $this->app->make(Stat32Calculator::class), $this->app->make(Stat33Calculator::class),
        ];
        $outputs = [];
        foreach ($this->app->make(Batch03HistoricalRaceRepository::class)->raceInputs($options) as $race) {
            foreach ($calculators as $calculator) {
                foreach ($race->entries as $entry) {
                    $result = $calculator->calculate($entry, $race->historiesByPlayer[$entry->playerId] ?? [], $options, 'fixed-batch');
                    $outputs[$calculator->stat()->value.':'.$entry->raceEntryId] = [
                        'features' => $result->features, 'evidence' => $result->evidence, 'status' => $result->status->value,
                        'quality' => $result->qualityStatus->value, 'input_hash' => $result->inputHash,
                    ];
                }
            }
        }
        ksort($outputs);

        return $outputs;
    }

    private function player(string $suffix): int
    {
        return (int) DB::table('players')->insertGetId(['source' => 'batch03-test', 'external_player_id' => $suffix, 'name' => 'Player '.$suffix, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function meeting(int $track, string $suffix, string $date): int
    {
        return (int) DB::table('race_meetings')->insertGetId([
            'source' => 'batch03-test', 'external_meeting_id' => $suffix, 'racetrack_id' => $track, 'starts_on' => $date,
            'ends_on' => (new DateTimeImmutable($date))->modify('+2 days')->format('Y-m-d'), 'duration_days' => 3,
            'grade' => 'F1', 'day_kind' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function day(int $meeting, string $date, int $number): int
    {
        $existing = DB::table('race_days')->where('race_meeting_id', $meeting)->where('race_date', $date)->value('id');
        if ($existing !== null) {
            return (int) $existing;
        }

        return (int) DB::table('race_days')->insertGetId([
            'race_meeting_id' => $meeting, 'external_race_day_id' => 'day-'.$meeting.'-'.$date,
            'race_date' => $date, 'day_number' => $number, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function sourceRows(): array
    {
        $rows = [];
        foreach (['players', 'racetracks', 'race_meetings', 'race_days', 'races', 'race_entries', 'race_results'] as $table) {
            $rows[$table] = DB::table($table)->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all();
        }

        return $rows;
    }

    private function statisticCounts(): array
    {
        return [
            DB::table('statistic_feature_runs')->count(),
            DB::table('statistic_feature_run_items')->count(),
            DB::table('statistic_feature_results')->count(),
        ];
    }
}
