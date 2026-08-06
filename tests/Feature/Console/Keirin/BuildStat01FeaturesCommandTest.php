<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Keirin;

use App\Domain\Keirin\Statistics\Calculators\Stat01Calculator;
use App\Domain\Keirin\Statistics\DTO\Stat01RaceFeatureDto;
use App\Domain\Keirin\Statistics\DTO\Stat01RaceInputDto;
use App\Domain\Keirin\Statistics\Support\DeterministicJsonHasher;
use App\Models\StatisticFeatureResult;
use App\Models\StatisticFeatureRun;
use App\Models\StatisticFeatureRunItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class BuildStat01FeaturesCommandTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    public function test_race_id_builds_stat01_results_and_preserves_source_tables(): void
    {
        $raceId = $this->createRace('Ａ級予選', '2024-01-02', ['80.00', '75.00', '70.00', '65.00', '60.00']);
        $sourceBefore = $this->sourceRows();

        $this->artisan('keirin:statistics:build-stat01', ['--race-id' => $raceId])
            ->expectsOutputToContain(
                'target_races=1 processed_races=1 target_entries=5 success=5 partial=0 missing=0 invalid=0 errors=0',
            )
            ->assertExitCode(0);

        $run = StatisticFeatureRun::query()->sole();
        $this->assertSame('SUCCEEDED', $run->status);
        $this->assertSame(1, $run->processed_race_count);
        $this->assertSame(5, $run->success_count);
        $this->assertDatabaseHas('statistic_feature_run_items', [
            'feature_run_id' => $run->id,
            'race_id' => $raceId,
            'status' => 'SUCCEEDED',
            'feature_result_count' => 5,
        ]);
        $this->assertSame(5, StatisticFeatureResult::query()->count());
        foreach (StatisticFeatureResult::query()->get() as $result) {
            $this->assertNull($result->raw_points);
            $this->assertNull($result->confidence);
            $this->assertNull($result->effective_points);
            $this->assertSame('BACKFILL', $result->acquisition_mode);
        }
        $this->assertSame($sourceBefore, $this->sourceRows());
    }

    public function test_date_range_includes_a_and_s_classes_and_excludes_l_class_and_other_dates(): void
    {
        $aRace = $this->createRace('Ａ級予選', '2024-01-01');
        $sRace = $this->createRace('Ｓ級決勝', '2024-01-02');
        $this->createRace('Ｌ級ガールズ予選', '2024-01-02');
        $this->createRace('Ａ級予選', '2024-02-01');

        $this->artisan('keirin:statistics:build-stat01', [
            '--from' => '2024-01-01',
            '--to' => '2024-01-31',
        ])->assertExitCode(0);

        $this->assertSame([$aRace, $sRace], StatisticFeatureRunItem::query()->orderBy('race_id')->pluck('race_id')->all());
        $this->assertSame(10, StatisticFeatureResult::query()->count());
    }

    public function test_invalid_arguments_are_rejected_without_writes(): void
    {
        $commands = [
            [],
            ['--race-id' => 1, '--from' => '2024-01-01', '--to' => '2024-01-02'],
            ['--from' => '2024-01-01'],
            ['--from' => '2024-02-30', '--to' => '2024-03-01'],
            ['--from' => '2024-02-01', '--to' => '2024-01-01'],
            ['--race-id' => 1, '--chunk' => 0],
            ['--race-id' => 1, '--chunk' => 1001],
        ];

        foreach ($commands as $arguments) {
            $this->artisan('keirin:statistics:build-stat01', $arguments)->assertExitCode(1);
        }

        $this->assertSame(0, StatisticFeatureRun::query()->count());
        $this->assertSame(0, StatisticFeatureRunItem::query()->count());
        $this->assertSame(0, StatisticFeatureResult::query()->count());
    }

    public function test_dry_run_calculates_but_writes_none_of_the_statistic_tables(): void
    {
        $this->createRace('Ａ級予選', '2024-01-01');

        $this->artisan('keirin:statistics:build-stat01', [
            '--from' => '2024-01-01',
            '--to' => '2024-01-01',
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('run=dry-run')
            ->expectsOutputToContain('processed_races=1')
            ->assertExitCode(0);

        $this->assertSame(0, StatisticFeatureRun::query()->count());
        $this->assertSame(0, StatisticFeatureRunItem::query()->count());
        $this->assertSame(0, StatisticFeatureResult::query()->count());
    }

    public function test_zero_missing_and_valid_scores_are_counted_without_treating_zero_as_ability(): void
    {
        $raceId = $this->createRace('Ａ級予選', '2024-01-01', ['80.00', '0.00', null, '70.00', '60.00']);

        $this->artisan('keirin:statistics:build-stat01', ['--race-id' => $raceId])->assertExitCode(0);

        $run = StatisticFeatureRun::query()->sole();
        $this->assertSame('PARTIALLY_SUCCEEDED', $run->status);
        $this->assertSame(0, $run->success_count);
        $this->assertSame(3, $run->partial_count);
        $this->assertSame(1, $run->missing_count);
        $this->assertSame(1, $run->invalid_count);
        $invalid = StatisticFeatureResult::query()->where('status', 'INVALID_INPUT')->sole();
        $this->assertFalse($invalid->features['RACE_SCORE_AVAILABLE']);
        $this->assertNull($invalid->features['RACE_SCORE_RANK']);
        $this->assertSame('RACE_SCORE_NON_POSITIVE_UNRESOLVED', $invalid->evidence['status_reason']);
    }

    public function test_race_id_keyset_chunks_have_no_duplicates_or_omissions(): void
    {
        $raceIds = [];
        foreach (range(1, 5) as $day) {
            $raceIds[] = $this->createRace('Ｓ級予選', sprintf('2024-01-%02d', $day));
        }

        $this->artisan('keirin:statistics:build-stat01', [
            '--from' => '2024-01-01',
            '--to' => '2024-01-05',
            '--chunk' => 2,
        ])->assertExitCode(0);

        $this->assertSame($raceIds, StatisticFeatureRunItem::query()->orderBy('race_id')->pluck('race_id')->all());
        $this->assertSame(25, StatisticFeatureResult::query()->count());
        $this->assertSame(25, StatisticFeatureResult::query()->distinct()->count('race_entry_id'));
    }

    public function test_one_race_failure_is_isolated_and_later_races_are_saved(): void
    {
        $failedRaceId = $this->createRace('Ａ級予選', '2024-01-01');
        $successfulRaceId = $this->createRace('Ａ級予選', '2024-01-02');
        $this->app->bind(Stat01Calculator::class, fn (): Stat01Calculator => new class($failedRaceId) extends Stat01Calculator
        {
            public function __construct(private readonly int $failedRaceId)
            {
                parent::__construct(new DeterministicJsonHasher);
            }

            public function calculate(Stat01RaceInputDto $race): Stat01RaceFeatureDto
            {
                if ($race->id === $this->failedRaceId) {
                    throw new RuntimeException('synthetic calculation failure');
                }

                return parent::calculate($race);
            }
        });

        $this->artisan('keirin:statistics:build-stat01', [
            '--from' => '2024-01-01',
            '--to' => '2024-01-02',
            '--chunk' => 1,
        ])->assertExitCode(1);

        $run = StatisticFeatureRun::query()->sole();
        $this->assertSame('PARTIALLY_SUCCEEDED', $run->status);
        $this->assertSame(1, $run->error_count);
        $this->assertSame(5, $run->success_count);
        $this->assertDatabaseHas('statistic_feature_run_items', ['race_id' => $failedRaceId, 'status' => 'FAILED']);
        $this->assertDatabaseHas('statistic_feature_run_items', ['race_id' => $successfulRaceId, 'status' => 'SUCCEEDED']);
        $this->assertSame([$successfulRaceId], StatisticFeatureResult::query()->distinct()->pluck('race_id')->all());
    }

    public function test_each_invocation_creates_a_new_auditable_run(): void
    {
        $raceId = $this->createRace('Ａ級予選', '2024-01-01');

        $this->artisan('keirin:statistics:build-stat01', ['--race-id' => $raceId])->assertExitCode(0);
        $firstRun = StatisticFeatureRun::query()->sole();
        $this->artisan('keirin:statistics:build-stat01', ['--race-id' => $raceId])->assertExitCode(0);

        $this->assertSame(2, StatisticFeatureRun::query()->count());
        $this->assertSame(10, StatisticFeatureResult::query()->count());
        $this->assertSame('SUCCEEDED', $firstRun->refresh()->status);
        $this->assertCount(2, StatisticFeatureRun::query()->distinct()->pluck('run_uuid'));
    }

    public function test_a_run_with_only_failed_races_finishes_as_failed(): void
    {
        $raceId = $this->createRace('Ａ級予選', '2024-01-01');
        $this->app->bind(Stat01Calculator::class, fn (): Stat01Calculator => new class extends Stat01Calculator
        {
            public function __construct()
            {
                parent::__construct(new DeterministicJsonHasher);
            }

            public function calculate(Stat01RaceInputDto $race): Stat01RaceFeatureDto
            {
                throw new RuntimeException('synthetic total failure');
            }
        });

        $this->artisan('keirin:statistics:build-stat01', ['--race-id' => $raceId])->assertExitCode(1);

        $run = StatisticFeatureRun::query()->sole();
        $this->assertSame('FAILED', $run->status);
        $this->assertSame(1, $run->processed_race_count);
        $this->assertSame(1, $run->error_count);
        $this->assertNotNull($run->finished_at);
        $this->assertDatabaseHas('statistic_feature_run_items', ['race_id' => $raceId, 'status' => 'FAILED']);
    }

    public function test_entry_count_mismatch_is_recorded_as_partial(): void
    {
        $raceId = $this->createRace('Ａ級予選', '2024-01-01', ['80.00', '70.00', '60.00'], entrantCount: 5);

        $this->artisan('keirin:statistics:build-stat01', ['--race-id' => $raceId])->assertExitCode(0);

        $this->assertDatabaseHas('statistic_feature_run_items', ['race_id' => $raceId, 'status' => 'PARTIAL']);
        $this->assertSame(3, StatisticFeatureResult::query()->where('status', 'PARTIAL')->count());
        $this->assertFalse(StatisticFeatureResult::query()->firstOrFail()->evidence['entry_count_matches']);
    }

    /** @param list<?string>|null $scores */
    private function createRace(
        string $raceType,
        string $raceDate,
        ?array $scores = null,
        ?int $entrantCount = null,
    ): int {
        $this->sequence++;
        $scores ??= ['80.00', '75.00', '70.00', '65.00', '60.00'];
        $now = now();
        $raceId = DB::table('races')->insertGetId([
            'source' => 'statistics-test',
            'external_race_id' => 'statistics-race-'.$this->sequence,
            'race_date' => $raceDate,
            'race_number' => $this->sequence,
            'scheduled_start_at' => $raceDate.' 12:00:00',
            'sales_close_at' => $raceDate.' 11:55:00',
            'race_type' => $raceType,
            'entrant_count' => $entrantCount ?? count($scores),
            'result_status' => 'UNAVAILABLE',
            'result_available' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        foreach ($scores as $index => $score) {
            $playerId = DB::table('players')->insertGetId([
                'source' => 'statistics-test',
                'external_player_id' => 'statistics-player-'.$this->sequence.'-'.$index,
                'name' => 'Test Player '.$this->sequence.'-'.$index,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('race_entries')->insert([
                'race_id' => $raceId,
                'player_id' => $playerId,
                'external_player_id' => 'statistics-player-'.$this->sequence.'-'.$index,
                'bike_number' => $index + 1,
                'frame_number' => $index + 1,
                'grade' => str_starts_with($raceType, 'Ｓ') ? 'S1' : 'A1',
                'race_score' => $score,
                'fetched_at' => $raceDate.' 13:00:00',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        return (int) $raceId;
    }

    /** @return array{races: array<int, array<string, mixed>>, race_entries: array<int, array<string, mixed>>, players: array<int, array<string, mixed>>} */
    private function sourceRows(): array
    {
        return [
            'races' => DB::table('races')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all(),
            'race_entries' => DB::table('race_entries')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all(),
            'players' => DB::table('players')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all(),
        ];
    }
}
