<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Keirin\Statistics\Calculators\Stat09GradeBreakdownCalculator;
use App\Domain\Keirin\Statistics\DTO\Batch02BuildOptionsDto;
use App\Domain\Keirin\Statistics\DTO\Batch03BuildOptionsDto;
use App\Domain\Keirin\Statistics\DTO\Batch04BuildOptionsDto;
use App\Domain\Keirin\Statistics\DTO\Batch05BuildOptionsDto;
use App\Domain\Keirin\Statistics\DTO\HistoricalRaceDto;
use App\Domain\Keirin\Statistics\Repositories\HistoricalRaceRepository;
use App\Domain\Keirin\Statistics\Repositories\Stat09GradeContextRepository;
use App\Domain\Keirin\Statistics\Services\AcquiredHistoryFeatureBuildService;
use App\Domain\Keirin\Statistics\Services\Batch02FeatureBuildService;
use App\Domain\Keirin\Statistics\Services\Batch03FeatureBuildService;
use App\Domain\Keirin\Statistics\Services\Batch04FeatureBuildService;
use App\Domain\Keirin\Statistics\Services\Batch05FeatureBuildService;
use App\Models\StatisticFeatureResult;
use App\Models\StatisticFeatureRun;
use DateTimeImmutable as Time;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\CompositionResultTemporaryDirectory;
use Tests\Support\StatCategory1Fixture;
use Tests\TestCase;
use Throwable;
use UnexpectedValueException;

final class Stat09GradeBreakdownTest extends TestCase
{
    use RefreshDatabase;

    private ?CompositionResultTemporaryDirectory $temporary = null;

    public function createApplication(): Application
    {
        $this->temporary = CompositionResultTemporaryDirectory::create();
        $root = $this->temporary->path();
        try {
            foreach (['bootstrap', 'bootstrap/cache', 'runtime'] as $dir) {
                if (! mkdir($root.'/'.$dir, 0700)) {
                    throw new RuntimeException('Cannot create grade-test isolation.');
                }
            }
            $env = fopen($root.'/.env', 'xb');
            $text = "# Isolated grade breakdown test. No application secrets.\n";
            if ($env === false || fwrite($env, $text) !== strlen($text)) {
                throw new RuntimeException('Cannot create artificial test environment.');
            }
            fclose($env);
            $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
            $this->traitsUsedByTest = class_uses_recursive(self::class);
            $app->useEnvironmentPath($root);
            $app->useBootstrapPath($root.'/bootstrap');
            $app->useStoragePath($root.'/runtime');
            $app->make(Kernel::class)->bootstrap();
            $app['config']->set(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
                'database.connections.sqlite.url' => null, 'logging.default' => 'null']);
            $app->bind(Factory::class, fn () => throw new RuntimeException('HTTP forbidden.'));

            return $app;
        } catch (Throwable $e) {
            $this->temporary->retire($this->temporary->path(), 'TEST_FAILURE');
            $this->temporary = null;
            throw $e;
        }
    }

    protected function tearDown(): void
    {
        $reason = $this->status()->isSuccess() ? 'TEST_COMPLETE' : 'TEST_FAILURE';
        try {
            parent::tearDown();
        } finally {
            $this->temporary?->retire($this->temporary->path(), $reason);
        }
    }

    public function test_repository_uses_saved_entry_grade_not_current_or_target_grade_and_batches_queries(): void
    {
        [$args, $options] = $this->fixture();
        $race = app(HistoricalRaceRepository::class)->raceInputs($options)->current();
        $history = $race->historiesByPlayer[$race->entries[0]->playerId];
        $queries = [];
        DB::listen(function ($q) use (&$queries): void {
            $queries[] = $q->sql;
        });
        $repo = app(Stat09GradeContextRepository::class);
        $first = $repo->forHistories($history);
        $this->assertCount(1, $queries);
        $this->assertSame(['A1', 'S2'], array_column($first, 'entryGrade'));
        $this->assertSame(['F1', 'G3'], array_column($first, 'meetingGrade'));
        DB::table('players')->update(['current_grade' => 'SS']);
        DB::table('race_entries')->where('race_id', $args['--race-id'])->update(['grade' => 'SS']);
        $this->assertEquals($first, $repo->forHistories($history));
        $this->assertEquals($first, $repo->forHistories(array_reverse($history)));
    }

    #[DataProvider('grades')]
    public function test_real_repository_applies_existing_grade_maps_and_retains_raw(?string $entryRaw, string $entryExpected, ?string $meetingRaw, string $meetingExpected): void
    {
        [, $options] = $this->fixture();
        $race = app(HistoricalRaceRepository::class)->raceInputs($options)->current();
        $events = $race->historiesByPlayer[$race->entries[0]->playerId];
        $e = $events[0];
        DB::table('race_entries')->where('id', $e->raceEntryId)->update(['grade' => $entryRaw]);
        DB::table('race_meetings')->where('id', $e->raceMeetingId)->update(['grade' => $meetingRaw]);
        $c = app(Stat09GradeContextRepository::class)->forHistories([$e])[$e->raceEntryId];
        $this->assertSame($entryExpected, $c->entryGrade);
        $this->assertSame($meetingExpected, $c->meetingGrade);
        $this->assertSame($entryRaw, $c->raw['entry_grade']);
        $this->assertSame($meetingRaw, $c->raw['meeting_grade']);
        if ($entryExpected === 'UNKNOWN') {
            $this->assertNotEmpty($c->reasons['entry_grade']);
        }
        if ($meetingExpected === 'UNKNOWN') {
            $this->assertNotEmpty($c->reasons['meeting_grade']);
        }
    }

    public static function grades(): array
    {
        return [[null, 'UNKNOWN', null, 'UNKNOWN'], ['', 'UNKNOWN', '', 'UNKNOWN'], ['alien', 'UNKNOWN', 'alien', 'UNKNOWN'],
            ['L1', 'UNKNOWN', 'F1', 'F1'], ['Ｓ級１班', 'S1', 'GⅠ', 'G1'], ['ＳＳ', 'SS', ' GP ', 'GP'],
            ['S2', 'S2', 'GⅡ', 'G2'], ['S3', 'S3', 'GⅢ', 'G3'], ['A1', 'A1', 'FⅠ', 'F1'], ['A2', 'A2', 'FⅡ', 'F2'],
            ['A3', 'A3', 'G1', 'G1'], ['A4', 'A4', 'G2', 'G2'], ['B1', 'B1', 'G3', 'G3'], ['B2', 'B2', 'F2', 'F2']];
    }

    #[DataProvider('badMeetings')]
    public function test_unverified_meeting_context_remains_unknown_without_removing_history(string $table, array $values, string $reason): void
    {
        [, $options] = $this->fixture();
        $race = app(HistoricalRaceRepository::class)->raceInputs($options)->current();
        $events = $race->historiesByPlayer[$race->entries[0]->playerId];
        $e = $events[0];
        $id = match ($table) {
            'races' => $e->raceId, 'race_meetings' => $e->raceMeetingId,
            default => DB::table('races')->where('id', $e->raceId)->value('race_day_id'),
        };
        if (($values['racetrack_id'] ?? null) === 'OTHER_TRACK') {
            $values['racetrack_id'] = DB::table('racetracks')->insertGetId(['source' => 'synthetic', 'external_track_id' => '02', 'name' => 'Other synthetic']);
        }
        DB::table($table)->where('id', $id)->update($values);
        $c = app(Stat09GradeContextRepository::class)->forHistories([$e])[$e->raceEntryId];
        $this->assertSame('A1', $c->entryGrade);
        $this->assertSame('UNKNOWN', $c->meetingGrade);
        $this->assertContains($reason, $c->reasons['meeting_grade']);
        $out = app(Stat09GradeBreakdownCalculator::class)->calculate($race->entries[0], [$e], $options, [], [$e->raceEntryId => $c]);
        $this->assertSame(1, $out->features['MEETING_GRADE_BREAKDOWN']['UNKNOWN']['observed_rows']);
        $this->assertSame(1, $out->features['ACQUIRED_PERIOD']['observed_rows']);
    }

    public static function badMeetings(): array
    {
        return [['races', ['race_day_id' => null], 'MEETING_RELATION_MISSING'],
            ['race_days', ['race_date' => '2024-12-03'], 'RACE_DAY_DATE_MISMATCH'],
            ['race_meetings', ['starts_on' => '2024-12-03'], 'MEETING_PERIOD_UNVERIFIED'],
            ['race_meetings', ['ends_on' => '2024-11-01'], 'MEETING_PERIOD_UNVERIFIED'],
            ['race_meetings', ['racetrack_id' => 'OTHER_TRACK'], 'MEETING_TRACK_MISMATCH_OR_MISSING'],
            ['races', ['grade' => 'G1'], 'CONFLICTING_MEETING_GRADE']];
    }

    public function test_missing_source_row_differs_from_null_grade_and_identity_mismatch_is_an_error(): void
    {
        [, $options] = $this->fixture();
        $r = app(HistoricalRaceRepository::class)->raceInputs($options)->current();
        $e = $r->historiesByPlayer[$r->entries[0]->playerId][0];
        $repo = app(Stat09GradeContextRepository::class);
        $missing = new HistoricalRaceDto(...array_replace(get_object_vars($e), ['raceEntryId' => 999999]));
        $c = $repo->forHistories([$missing])[999999];
        $this->assertSame(['HISTORY_ENTRY_NOT_FOUND'], $c->reasons['entry_grade']);
        $this->assertFalse($c->sources['entry_found']);
        DB::table('race_entries')->where('id', $e->raceEntryId)->update(['grade' => null]);
        $this->assertSame(['ENTRY_GRADE_NULL'], $repo->forHistories([$e])[$e->raceEntryId]->reasons['entry_grade']);
        $other = new HistoricalRaceDto(...array_replace(get_object_vars($e), ['playerId' => 999999]));
        $this->expectException(UnexpectedValueException::class);
        $repo->forHistories([$other]);
    }

    public function test_profiles_save_read_reproduce_and_dry_run_writes_nothing(): void
    {
        [$args] = $this->fixture();
        $this->artisan('keirin:statistics:build-acquired-history', $args)->assertExitCode(0);
        $old = StatisticFeatureRun::query()->where('stat_code', 'STAT-09')->sole();
        $this->assertSame('STAT-09-acquired-career-v2', $old->calculation_version);
        $this->assertArrayNotHasKey('stat09_profile', $old->parameters);
        $oldRows = $this->semantics($old->id);
        $this->artisan('keirin:statistics:build-acquired-history', $args + ['--stat09-profile' => 'legacy-v2'])->assertExitCode(0);
        $this->assertSame($oldRows, $this->semantics((int) DB::table('statistic_feature_runs')->max('id')));
        $newRows = [];
        for ($pass = 0; $pass < 2; $pass++) {
            $this->artisan('keirin:statistics:build-acquired-history', $args + ['--stat09-profile' => 'grade-breakdown-v3'])->assertExitCode(0);
            $id = (int) DB::table('statistic_feature_runs')->max('id');
            $run = StatisticFeatureRun::query()->findOrFail($id);
            $this->assertSame('STAT-09-acquired-career-grade-breakdown-v3', $run->calculation_version);
            $this->assertSame('ACQUIRED-HISTORY-RACE-AUDIT-v3', $run->parameters['build_service_version']);
            $this->assertSame('STAT09-SAVED-HISTORY-GRADES-v1', $run->parameters['grade_projection_contract']);
            $newRows[] = $this->semantics($id);
            $this->assertSame(0, $run->error_count);
            foreach ($newRows[$pass] as $i => $row) {
                $base = $row['features'];
                unset($base['ENTRY_GRADE_BREAKDOWN'], $base['MEETING_GRADE_BREAKDOWN']);
                $this->assertSame($oldRows[$i]['features'], $base);
                $this->assertSame($oldRows[$i]['status'], $row['status']);
                $this->assertSame($oldRows[$i]['quality'], $row['quality']);
                $this->assertNotSame($oldRows[$i]['hash'], $row['hash']);
            }
        }
        $this->assertSame($newRows[0], $newRows[1]);
        $before = $this->counts();
        $writes = [];
        DB::listen(function ($q) use (&$writes): void {
            if (preg_match('/^\s*(insert|update|delete|create|alter|drop)/i', $q->sql)) {
                $writes[] = $q->sql;
            }
        });
        $this->artisan('keirin:statistics:build-acquired-history', $args + ['--stat09-profile' => 'grade-breakdown-v3', '--dry-run' => true])->assertExitCode(0);
        $this->assertSame([], $writes);
        $this->assertSame($before, $this->counts());
    }

    public function test_repository_datetime_spellings_preserve_microseconds_and_hash_but_not_evidence_text(): void
    {
        [, $options] = $this->fixture();
        $r = app(HistoricalRaceRepository::class)->raceInputs($options)->current();
        $target = $r->entries[0];
        $history = $r->historiesByPlayer[$target->playerId];
        $e = $history[0];
        $day = DB::table('races')->where('id', $e->raceId)->value('race_day_id');
        foreach ([['race_entries', $e->raceEntryId, 'fetched_at', 'entry_fetched_at'], ['races', $e->raceId, 'last_fetched_at', 'race_last_fetched_at'],
            ['race_days', $day, 'last_fetched_at', 'day_last_fetched_at'], ['race_meetings', $e->raceMeetingId, 'last_fetched_at', 'meeting_last_fetched_at']] as [$table, $id, $column, $key]) {
            $outputs = [];
            foreach (['2026-08-01T12:00:00.123456+09:00', '2026-08-01T03:00:00.123456Z'] as $at) {
                DB::table($table)->where('id', $id)->update([$column => $at]);
                $c = app(Stat09GradeContextRepository::class)->forHistories($history);
                $out = app(Stat09GradeBreakdownCalculator::class)->calculate($target, $history, $options, [], $c);
                $this->assertSame($at, $out->evidence['grade_breakdown']['references'][0]['sources'][$key]);
                $outputs[] = $out;
            }
            $this->assertSame($outputs[0]->features, $outputs[1]->features);
            $this->assertSame($outputs[0]->inputHash, $outputs[1]->inputHash);
        }
    }

    public function test_grade_metadata_is_loaded_only_for_histories_accepted_for_each_target(): void
    {
        [$args] = $this->fixture();
        $readBindings = [];
        DB::listen(function ($q) use (&$readBindings): void {
            if (str_contains($q->sql, '"entry_grade"') && str_contains($q->sql, '"meeting_last_fetched_at"')) {
                $readBindings[] = $q->bindings;
            }
        });
        $this->artisan('keirin:statistics:build-acquired-history', $args + ['--stat09-profile' => 'grade-breakdown-v3'])->assertExitCode(0);
        $this->assertCount(1, $readBindings);
        $targetIds = DB::table('race_entries')->where('race_id', $args['--race-id'])->pluck('id')->all();
        foreach ($targetIds as $id) {
            $this->assertNotContains($id, $readBindings[0]);
        }
    }

    public function test_explicit_v3_stat20_accepts_only_new09_and_reproduces_same_sources(): void
    {
        [$args, $options] = $this->fixture(9);
        app(Batch02FeatureBuildService::class)->build($options);
        app(Batch03FeatureBuildService::class)->build(new Batch03BuildOptionsDto($options->stat01RunId, $options->historyFrom, null, null, $options->raceId, 10, false));
        app(Batch04FeatureBuildService::class)->build(new Batch04BuildOptionsDto($options->stat01RunId, $options->historyFrom, null, null, $options->raceId, 10, false));
        app(Batch05FeatureBuildService::class)->build(new Batch05BuildOptionsDto($options->stat01RunId, null, null, $options->raceId, 10, false));
        $service = app(AcquiredHistoryFeatureBuildService::class);
        foreach (['STAT-16', 'STAT-25', 'STAT-27', 'STAT-44'] as $stat) {
            $service->build($options, $stat);
        }
        $sources = StatisticFeatureRun::query()->where('id', '!=', $options->stat01RunId)->pluck('id')->all();
        $old = $service->build($options, 'STAT-09');
        $new = $service->build($options, 'STAT-09', null, 'grade-breakdown-v3');
        $base = ['--stat01-run-id' => $options->stat01RunId, '--race-id' => $options->raceId];
        $v2 = $base + ['--source-run-id' => [...$sources, $old['run_id']]];
        $v3 = $base + ['--source-run-id' => [...$sources, $new['run_id']], '--source-contract' => 'STAT-20-acquired-history-v3'];
        $this->artisan('keirin:statistics:build-stat20-acquired-history', $v2)->assertExitCode(0);
        $this->assertSame('STAT-20-acquired-history-v2', StatisticFeatureRun::query()->where('stat_code', 'STAT-20')->sole()->calculation_version);
        $saved = [];
        for ($pass = 0; $pass < 2; $pass++) {
            $this->artisan('keirin:statistics:build-stat20-acquired-history', $v3)->assertExitCode(0);
            $id = (int) DB::table('statistic_feature_runs')->max('id');
            $saved[] = $this->semantics($id);
            $this->assertCount(9, $saved[$pass]);
            $this->assertSame('STAT-20-acquired-history-v3', $saved[$pass][0]['evidence']['source_contract']);
            $this->assertSame('STAT-09-acquired-career-grade-breakdown-v3', $saved[$pass][0]['features']['STAT_QUALITY']['STAT-09']['calculation_version']);
        }
        $this->assertSame($saved[0], $saved[1]);
        $before = $this->counts();
        foreach ([array_replace($v3, ['--source-contract' => 'STAT-20-acquired-history-v2']),
            $v2 + ['--source-contract' => 'STAT-20-acquired-history-v3'], $v3 + ['--dry-run' => true]] as $i => $request) {
            $this->artisan('keirin:statistics:build-stat20-acquired-history', $request)->assertExitCode($i < 2 ? 1 : 0);
            $this->assertSame($before, $this->counts());
        }
        $r = StatisticFeatureRun::query()->findOrFail($new['run_id']);
        $r->forceFill(['parameters' => array_replace($r->parameters, ['stat09_profile' => 'legacy-v2'])])->save();
        $this->artisan('keirin:statistics:build-stat20-acquired-history', $v3)->assertExitCode(1);
    }

    public function test_unknown_or_incompatible_profile_and_contract_are_rejected_without_writes(): void
    {
        [$args] = $this->fixture();
        $before = $this->counts();
        foreach ([['--stat09-profile' => 'unknown'], ['--stat' => 'STAT-25', '--stat09-profile' => 'grade-breakdown-v3']] as $bad) {
            $this->artisan('keirin:statistics:build-acquired-history', array_replace($args, $bad))->assertExitCode(1);
            $this->assertSame($before, $this->counts());
        }
        $this->artisan('keirin:statistics:build-stat20-acquired-history', ['--stat01-run-id' => $args['--stat01-run-id'], '--race-id' => $args['--race-id'],
            '--source-contract' => 'unknown'])->assertExitCode(1);
        $this->assertSame($before, $this->counts());
    }

    private function semantics(int $run): array
    {
        return StatisticFeatureResult::query()->where('feature_run_id', $run)->orderBy('bike_number')->get()->map(fn ($r) => [
            'features' => $r->features, 'evidence' => $r->evidence, 'status' => $r->status, 'quality' => $r->quality_status, 'hash' => $r->input_hash])->all();
    }

    private function counts(): array
    {
        return array_map(fn ($t) => DB::table($t)->count(), ['statistic_feature_runs', 'statistic_feature_run_items', 'statistic_feature_results']);
    }

    private function fixture(int $n = 5): array
    {
        // Synthetic category/transaction tests, never historical acceptance evidence.
        $track = DB::table('racetracks')->insertGetId(['source' => 'synthetic', 'external_track_id' => '01', 'name' => 'Synthetic']);
        $players = [];
        for ($bike = 1; $bike <= $n; $bike++) {
            $players[$bike] = DB::table('players')->insertGetId(['source' => 'synthetic', 'external_player_id' => sprintf('%06d', $bike), 'name' => 'Synthetic '.$bike]);
        }
        foreach (['2024-12-01', '2024-12-02', '2025-01-04'] as $i => $date) {
            $meeting = DB::table('race_meetings')->insertGetId(['source' => 'synthetic', 'external_meeting_id' => 'synthetic-'.$i, 'racetrack_id' => $track,
                'starts_on' => $date, 'ends_on' => $date, 'duration_days' => 1, 'grade' => $i === 1 ? 'G3' : 'F1', 'last_fetched_at' => '2026-07-03 12:00:00']);
            $day = DB::table('race_days')->insertGetId(['race_meeting_id' => $meeting, 'external_race_day_id' => 'synthetic-'.$i,
                'race_date' => $date, 'day_number' => 1, 'last_fetched_at' => '2026-07-02 12:00:00']);
            $race = DB::table('races')->insertGetId(['source' => 'synthetic', 'external_race_id' => 'synthetic-'.$i, 'racetrack_id' => $track,
                'race_day_id' => $day, 'race_date' => $date, 'race_number' => 1, 'scheduled_start_at' => $date.' 12:00:00', 'sales_close_at' => $date.' 11:55:00',
                'entrant_count' => $n, 'race_type' => 'Ａ級予選', 'result_status' => $i === 2 ? 'UNAVAILABLE' : 'CONFIRMED', 'last_fetched_at' => '2026-07-02 11:00:00']);
            foreach ($players as $bike => $player) {
                DB::table('race_entries')->insert(['race_id' => $race, 'player_id' => $player, 'external_player_id' => sprintf('%06d', $bike),
                    'bike_number' => $bike, 'grade' => $i === 1 ? 'S2' : 'A1', 'race_score' => 90 - $bike, 'fetched_at' => '2026-07-01 12:00:00']);
                if ($i < 2) {
                    DB::table('race_results')->insert(['race_id' => $race, 'bike_number' => $bike, 'rank' => $bike,
                        'result_status' => 'FINISHED', 'fetched_at' => '2026-07-01 12:01:00']);
                }
            }
        }
        $root = StatCategory1Fixture::stat01($race);
        $options = new Batch02BuildOptionsDto($root, new Time('2024-01-01T00:00:00+09:00'), null, null, $race, 10, false);

        return [['--stat' => 'STAT-09', '--stat01-run-id' => $root, '--history-from' => '2024-01-01', '--race-id' => $race], $options];
    }
}
