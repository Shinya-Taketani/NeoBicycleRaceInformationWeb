<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Keirin\Scraping\DTO\RaceTacticObservationDto;
use App\Domain\Keirin\Scraping\Exceptions\ParserException;
use App\Domain\Keirin\Scraping\Parsers\EmbeddedJsonExtractor;
use App\Domain\Keirin\Scraping\Parsers\RaceDetailParser;
use App\Domain\Keirin\Scraping\Services\RaceTacticObservationService;
use App\Domain\Keirin\Scraping\Support\CharacterEncodingConverter;
use App\Domain\Keirin\Statistics\Calculators\Stat17DistributionCalculator;
use App\Domain\Keirin\Statistics\Services\Stat17DistributionBuildService;
use App\Models\Race;
use App\Models\RaceEntry;
use App\Models\RaceTacticObservation;
use App\Models\Racetrack;
use App\Models\ScrapingFetchLog;
use DateTimeImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Support\CompositionResultTemporaryDirectory;
use Tests\TestCase;
use Throwable;

final class TacticSnapshotMvpTest extends TestCase
{
    use RefreshDatabase;

    private ?CompositionResultTemporaryDirectory $temporary = null;

    public function createApplication(): Application
    {
        $this->temporary = CompositionResultTemporaryDirectory::create();
        $root = $this->temporary->path();
        try {
            foreach (['bootstrap', 'bootstrap/cache', 'runtime', 'raw'] as $dir) {
                if (! mkdir($root.'/'.$dir, 0700)) {
                    throw new RuntimeException('Cannot create isolated tactic test directory.');
                }
            }
            $this->writeNew($root.'/.env', "# Isolated tactic snapshot test. No application secrets.\n");
            $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
            $this->traitsUsedByTest = class_uses_recursive(self::class);
            $app->useEnvironmentPath($root);
            $app->useBootstrapPath($root.'/bootstrap');
            $app->useStoragePath($root.'/runtime');
            $app->make(Kernel::class)->bootstrap();
            $app['config']->set(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
                'database.connections.sqlite.url' => null, 'logging.default' => 'null', 'keirin.raw_disk' => 'tactic_test',
                'filesystems.disks.tactic_test' => ['driver' => 'local', 'root' => $root.'/raw', 'throw' => true]]);
            $app->bind(Factory::class, fn () => throw new RuntimeException('HTTP forbidden in tactic snapshot tests.'));

            return $app;
        } catch (Throwable $error) {
            $this->temporary->retire($this->temporary->path(), 'TEST_FAILURE');
            $this->temporary = null;
            throw $error;
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

    public function test_real_captures_preserve_all_seven_values_and_compute_only_displayed_count_composition(): void
    {
        $captures = $unique = $years = $examples = [];
        $occurrences = 0;
        foreach ($this->cases() as $case) {
            [$race, $log, $html] = $this->seedCapture($case);
            $page = app(RaceDetailParser::class)->parse($html);
            $rawRows = app(EmbeddedJsonExtractor::class)->extract($html, 'PJ0315')['sensyuTypeInfo'];
            $before = $this->oldState();
            $import = app(RaceTacticObservationService::class)->import($race->id, $log->id, false);
            $this->assertSame(count($page->entries), $import['planned_new_observations']);
            $this->assertSame($before, $this->oldState());
            $rows = RaceTacticObservation::query()->where('scraping_fetch_log_id', $log->id)->orderBy('bike_number')->get();
            $build = app(Stat17DistributionBuildService::class)->build($race->id, $log->id, new DateTimeImmutable($case['target']['race']['race_date'].'T00:00:00+09:00'), false);
            foreach ($rows as $index => $row) {
                $parsed = collect($page->entries)->first(fn ($e) => $e->bikeNumber === $row->bike_number);
                $raw = collect($rawRows)->first(fn ($e) => (int) $e['syaban'] === $row->bike_number);
                foreach (RaceTacticObservationDto::FIELDS as $key => [$column, $property]) {
                    $this->assertSame($parsed->$property, $row->$column);
                    $this->assertSame($raw[$key], $row->raw_fields[$key]['raw']);
                    $this->assertTrue($row->raw_fields[$key]['present']);
                }
                $this->assertNull($row->player_id);
                $this->assertSame($case['original']['sha256'], $row->raw_sha256);
                $this->assertSame($case['original']['bytes'], $row->raw_bytes);
                $this->assertSame($log->request_url, $row->source_url);
                $this->assertSame('UNKNOWN', $row->aggregation_period_status);
                $this->assertSame('UNKNOWN', $row->aggregation_basis_status);
                $this->assertNull($row->official_publication_at);
                $this->assertSame('RETROSPECTIVE_ONLY', $row->use_class);
                $result = $build['features'][$index];
                $profile = $result['features']['RETROSPECTIVE'];
                $expected = [$parsed->escapeCount, $parsed->sprintCount, $parsed->overtakeCount, $parsed->markCount];
                $this->assertNotContains(null, $expected);
                $total = array_sum($expected);
                $this->assertSame($total, $profile['total_displayed_count']);
                if ($total > 0) {
                    $this->assertSame(array_map(fn ($n) => $n / $total, $expected), array_values($profile['composition']));
                    $this->assertEqualsWithDelta(1.0, array_sum($profile['composition']), 1e-12);
                } else {
                    $this->assertNull($profile['composition']);
                }
                $this->assertSame(count(array_filter($expected, fn ($n) => $n > 0)), $profile['observed_tactic_types']);
                $this->assertFalse($result['evidence']['capture_before_input_as_of']);
                $this->assertFalse($result['evidence']['historical_as_of_available']);
                $this->assertSame('NOT_AUTHORIZED', $result['features']['prediction_use']);
                $this->assertNull($result['features']['points']);
                $this->assertNull($result['features']['confidence']);
                $unique[$race->id.':'.$row->race_entry_id] = true;
                $occurrences++;
                if ($index === 0) {
                    $examples[] = ['race_id' => $race->id, 'race_date' => $race->race_date->format('Y-m-d'), 'fetch_log_id' => $log->id,
                        'bike_number' => $row->bike_number, 'counts' => array_intersect_key($row->getAttributes(), array_flip(array_column(RaceTacticObservationDto::FIELDS, 0))),
                        'profile' => $profile, 'fetched_at' => $row->fetched_at?->format(DATE_ATOM),
                        'raw_sha256' => $row->raw_sha256];
                }
            }
            $captures[$log->id] = true;
            $years[$race->race_date->format('Y')][$race->id] = true;
        }
        $this->assertCount(4, $years);
        $this->assertSame($occurrences, RaceTacticObservation::query()->count());
        $this->assertSame(0, DB::table('statistic_feature_results')->where('stat_code', '!=', 'STAT-17')->count());
        $this->assertSame(0, DB::table('statistic_feature_results')->whereNotNull('raw_points')->count());
        $out = getenv('TACTIC_TEST_REPORT_DIRECTORY');
        if (is_string($out) && $out !== '') {
            if (! str_starts_with($out, '/') || ! is_dir($out) || realpath($out) !== $out) {
                throw new RuntimeException('Invalid explicit tactic test report directory.');
            }
            $this->writeNew($out.'/real-raw-acceptance.json', json_encode(['years' => array_map('count', $years),
                'races' => array_sum(array_map('count', $years)), 'entries' => count($unique), 'captures' => count($captures),
                'observation_rows' => $occurrences, 'examples' => $examples], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
        }
    }

    public function test_reprocessing_and_distinct_real_recaptures_preserve_history(): void
    {
        $cases = $this->cases();
        foreach ([$cases[0], $cases[1]] as $case) {
            [$race, $log] = $this->seedCapture($case);
            $first = app(RaceTacticObservationService::class)->import($race->id, $log->id, false);
            $again = app(RaceTacticObservationService::class)->import($race->id, $log->id, false);
            $this->assertSame(5, $first['planned_new_observations']);
            $this->assertSame(0, $again['planned_new_observations']);
            $this->assertSame(array_column($first['entries'], 'observation_id'), array_column($again['entries'], 'observation_id'));
        }
        $this->assertSame(10, RaceTacticObservation::query()->count());
        $this->assertSame(2, RaceTacticObservation::query()->distinct()->count('raw_sha256'));
    }

    public function test_boundary_mutations_preserve_missing_null_empty_placeholder_and_observed_zero(): void
    {
        [$race, $log, $html] = $this->seedCapture($this->cases()[0]);
        $pj = app(EmbeddedJsonExtractor::class)->extract($html, 'PJ0315');
        foreach (['MISSING' => null, 'NULL' => null, 'EMPTY_STRING' => '', 'PLACEHOLDER' => '－', 'OBSERVED_ZERO' => 0, 'STRING_ZERO' => '0'] as $state => $value) {
            $changed = $pj;
            foreach (array_keys(RaceTacticObservationDto::FIELDS) as $key) {
                if ($state === 'MISSING') {
                    unset($changed['sensyuTypeInfo'][0][$key]);
                } else {
                    $changed['sensyuTypeInfo'][0][$key] = $value;
                }
            }
            $control = $this->controlLog($log, $html, $changed);
            app(RaceTacticObservationService::class)->import($race->id, $control->id, false);
            $out = app(Stat17DistributionBuildService::class)->build($race->id, $control->id, new DateTimeImmutable('2025-01-01T00:00:00+09:00'), true);
            $row = RaceTacticObservation::query()->where('scraping_fetch_log_id', $control->id)->where('bike_number', (int) $pj['sensyuTypeInfo'][0]['syaban'])->sole();
            foreach (RaceTacticObservationDto::FIELDS as $key => [$column]) {
                $this->assertSame(str_contains($state, 'ZERO') ? 0 : null, $row->$column);
                $this->assertSame($state !== 'MISSING', $row->raw_fields[$key]['present']);
                $this->assertSame($value, $row->raw_fields[$key]['raw']);
                $this->assertSame($state === 'STRING_ZERO' ? 'OBSERVED_ZERO' : $state, $row->raw_fields[$key]['state']);
            }
            $result = collect($out['features'])->first(fn ($r) => $r['evidence']['race_entry_id'] === $row->race_entry_id);
            $profile = $result['features']['RETROSPECTIVE'];
            $this->assertSame(str_contains($state, 'ZERO') ? 0 : null, $profile['total_displayed_count']);
            $this->assertNull($profile['composition']);
            $this->assertSame(str_contains($state, 'ZERO') ? 'ZERO_TOTAL_UNDEFINED' : 'MISSING_COUNTS', $profile['distribution_status']);
        }
    }

    public function test_one_missing_count_does_not_create_a_partial_denominator(): void
    {
        [$race, $log, $html] = $this->seedCapture($this->cases()[0]);
        $pj = app(EmbeddedJsonExtractor::class)->extract($html, 'PJ0315');
        $pj['sensyuTypeInfo'][0]['nigeCnt'] = null;
        $control = $this->controlLog($log, $html, $pj);
        app(RaceTacticObservationService::class)->import($race->id, $control->id, false);
        $out = app(Stat17DistributionBuildService::class)->build($race->id, $control->id, new DateTimeImmutable('2025-01-01T00:00:00+09:00'), true);
        $profile = $out['features'][0]['features']['RETROSPECTIVE'];
        $this->assertSame(['escape_count'], $profile['missing_fields']);
        $this->assertNull($profile['counts']['escape_count']);
        $this->assertNotNull($profile['counts']['sprint_count']);
        $this->assertNull($profile['total_displayed_count']);
        $this->assertNull($profile['composition']);
        $this->assertNull($profile['observed_tactic_types']);
    }

    public function test_invalid_counts_and_raw_identity_duplicates_fail_without_partial_publication(): void
    {
        [$race, $log, $html] = $this->seedCapture($this->cases()[0]);
        $pj = app(EmbeddedJsonExtractor::class)->extract($html, 'PJ0315');
        foreach ([-1, 10000, 1.5, '1e2', [], 'unknown'] as $bad) {
            $changed = $pj;
            $changed['sensyuTypeInfo'][1]['nigeCnt'] = $bad;
            $control = $this->controlLog($log, $html, $changed);
            try {
                app(RaceTacticObservationService::class)->import($race->id, $control->id, false);
                $this->fail('Invalid count accepted.');
            } catch (ParserException) {
                $this->assertSame(0, RaceTacticObservation::query()->count());
            }
        }
        $changed = $pj;
        $changed['sensyuTypeInfo'][1] = $changed['sensyuTypeInfo'][0];
        $control = $this->controlLog($log, $html, $changed);
        $this->expectException(\InvalidArgumentException::class);
        try {
            app(RaceTacticObservationService::class)->import($race->id, $control->id, false);
        } finally {
            $this->assertSame(0, RaceTacticObservation::query()->count());
        }
    }

    public function test_dry_run_and_plan_have_zero_observation_and_statistic_writes(): void
    {
        [$race, $log] = $this->seedCapture($this->cases()[0]);
        $writes = [];
        DB::listen(function ($query) use (&$writes): void {
            if (preg_match('/^\s*(insert|update|delete|create|alter|drop|replace)\b/i', $query->sql)) {
                $writes[] = strtok($query->sql, ' ');
            }
        });
        $out = app(RaceTacticObservationService::class)->import($race->id, $log->id, true);
        $this->assertSame(5, $out['planned_new_observations']);
        $this->artisan('keirin:tactics:import-observations', ['--race-id' => (string) $race->id, '--fetch-log-id' => [(string) $log->id]])->assertExitCode(0);
        $this->artisan('keirin:tactics:import-observations', ['--race-id' => (string) $race->id, '--fetch-log-id' => [(string) $log->id], '--execute' => true, '--dry-run' => true])->assertExitCode(0);
        $this->artisan('keirin:statistics:build-stat17-profile', ['--race-id' => (string) $race->id,
            '--fetch-log-id' => (string) $log->id, '--input-as-of' => '2025-01-01T00:00:00+09:00'])->assertExitCode(0);
        $this->assertSame([], $writes);
        $this->assertSame(0, RaceTacticObservation::query()->count());
    }

    public function test_stored_capture_dry_run_build_has_zero_writes_and_actual_command_can_save(): void
    {
        [$race, $log] = $this->seedCapture($this->cases()[0]);
        $this->artisan('keirin:tactics:import-observations', ['--race-id' => (string) $race->id,
            '--fetch-log-id' => [(string) $log->id], '--execute' => true])->assertExitCode(0);
        $this->assertSame(5, RaceTacticObservation::query()->count());
        $writes = [];
        DB::listen(function ($query) use (&$writes): void {
            if (preg_match('/^\s*(insert|update|delete|create|alter|drop|replace)\b/i', $query->sql)) {
                $writes[] = true;
            }
        });
        $options = ['--race-id' => (string) $race->id, '--fetch-log-id' => (string) $log->id,
            '--input-as-of' => '2025-01-01T00:00:00+09:00', '--execute' => true];
        $this->artisan('keirin:statistics:build-stat17-profile', $options + ['--dry-run' => true])->assertExitCode(0);
        $this->assertSame([], $writes);
        $this->assertSame(0, DB::table('statistic_feature_runs')->count());
        $this->artisan('keirin:statistics:build-stat17-profile', $options)->assertExitCode(0);
        $this->assertSame(5, DB::table('statistic_feature_results')->count());
        $this->assertSame(Stat17DistributionCalculator::CALCULATION_VERSION, DB::table('statistic_feature_runs')->sole()->calculation_version);
    }

    public function test_wrong_race_date_track_number_or_stored_registration_and_sets_are_rejected(): void
    {
        [$race, $log] = $this->seedCapture($this->cases()[0]);
        foreach (['race_date' => '2025-01-02', 'race_number' => 12, 'external_race_id' => 'wrong'] as $key => $bad) {
            $before = $race->$key;
            $race->update([$key => $bad]);
            try {
                app(RaceTacticObservationService::class)->import($race->id, $log->id, false);
                $this->fail('Wrong race accepted.');
            } catch (RuntimeException) {
                $this->assertSame(0, RaceTacticObservation::query()->count());
            } finally {
                $race->update([$key => $before]);
            }
        }
        RaceEntry::query()->where('race_id', $race->id)->firstOrFail()->update(['external_player_id' => '000001']);
        $this->expectExceptionMessage('registration mismatch');
        app(RaceTacticObservationService::class)->import($race->id, $log->id, false);
    }

    public function test_raw_one_byte_drift_blocks_build_without_statistic_publication(): void
    {
        [$race, $log] = $this->seedCapture($this->cases()[0]);
        app(RaceTacticObservationService::class)->import($race->id, $log->id, false);
        $stream = fopen($this->temporary->path().'/raw/'.$log->raw_file_path, 'r+b');
        fwrite($stream, '!');
        fclose($stream);
        $this->expectException(RuntimeException::class);
        try {
            app(Stat17DistributionBuildService::class)->build($race->id, $log->id, new DateTimeImmutable('2025-01-01T00:00:00+09:00'), false);
        } finally {
            $this->assertSame(0, DB::table('statistic_feature_runs')->count());
        }
    }

    public function test_metadata_drift_source_mismatch_and_unstored_capture_are_rejected(): void
    {
        [$race, $log] = $this->seedCapture($this->cases()[0]);
        foreach (['http_status' => 302, 'request_url' => 'https://invalid.example/', 'sha256' => str_repeat('0', 64), 'response_size' => 1,
            'raw_file_path' => '../not-allowed.html'] as $key => $bad) {
            $before = $log->$key;
            $log->update([$key => $bad]);
            try {
                app(RaceTacticObservationService::class)->import($race->id, $log->id, false);
                $this->fail('Invalid source accepted.');
            } catch (RuntimeException) {
                $this->assertSame(0, RaceTacticObservation::query()->count());
            } finally {
                $log->update([$key => $before]);
            }
        }
        $this->expectExceptionMessage('not been completely stored');
        app(Stat17DistributionBuildService::class)->build($race->id, $log->id, new DateTimeImmutable('2025-01-01T00:00:00+09:00'), false);
    }

    public function test_forbidden_year_is_rejected_before_raw_read(): void
    {
        [$race, $log] = $this->seedCapture($this->cases()[0]);
        $race->update(['race_date' => '2026-01-01']); // Rejection-only target; no 2026 Raw or real DB.
        $log->update(['raw_file_path' => 'never-created.html']);
        $this->expectException(ModelNotFoundException::class);
        app(RaceTacticObservationService::class)->import($race->id, $log->id, false);
    }

    public function test_append_only_constraints_and_existing_stat38_schema_remain_unchanged(): void
    {
        [$race, $log] = $this->seedCapture($this->cases()[0]);
        $old = $this->oldState();
        app(RaceTacticObservationService::class)->import($race->id, $log->id, false);
        $this->assertSame($old, $this->oldState());
        $this->assertCount(4, Schema::getForeignKeys('race_tactic_observations'));
        foreach (Schema::getForeignKeys('race_tactic_observations') as $fk) {
            $this->assertSame('restrict', $fk['on_delete']);
        }
        foreach (['UPDATE race_tactic_observations SET escape_count=0', 'DELETE FROM race_tactic_observations'] as $sql) {
            try {
                DB::statement($sql);
                $this->fail('Observation mutation accepted.');
            } catch (QueryException) {
                $this->assertSame(5, RaceTacticObservation::query()->count());
            }
        }
        $migration = require database_path('migrations/2026_10_22_000015_create_race_tactic_observations.php');
        $this->expectException(RuntimeException::class);
        $migration->down();
    }

    public function test_fetch_metadata_drift_rolls_back_all_partially_appended_observations(): void
    {
        [$race, $log] = $this->seedCapture($this->cases()[0]);
        $changed = false;
        DB::listen(function ($query) use ($log, &$changed): void {
            if (! $changed && str_starts_with($query->sql, 'insert into "race_tactic_observations"')) {
                $changed = true;
                $log->update(['sha256' => str_repeat('0', 64)]);
            }
        });
        $this->expectExceptionMessage('metadata changed before publication');
        try {
            app(RaceTacticObservationService::class)->import($race->id, $log->id, false);
        } finally {
            $this->assertTrue($changed);
            $this->assertSame(0, RaceTacticObservation::query()->count());
        }
    }

    public function test_build_end_integrity_failure_publishes_no_statistic_run_or_result(): void
    {
        [$race, $log] = $this->seedCapture($this->cases()[0]);
        app(RaceTacticObservationService::class)->import($race->id, $log->id, false);
        $calculator = app(Stat17DistributionCalculator::class);
        $mock = \Mockery::mock(Stat17DistributionCalculator::class);
        $changed = false;
        $mock->shouldReceive('calculate')->andReturnUsing(function ($input) use ($calculator, $log, &$changed) {
            $out = $calculator->calculate($input);
            if (! $changed) {
                $changed = true;
                $log->update(['sha256' => str_repeat('0', 64)]);
            }

            return $out;
        });
        $this->app->instance(Stat17DistributionCalculator::class, $mock);
        $this->expectException(RuntimeException::class);
        try {
            app(Stat17DistributionBuildService::class)->build($race->id, $log->id, new DateTimeImmutable('2025-01-01T00:00:00+09:00'), false);
        } finally {
            $this->assertTrue($changed);
            $this->assertSame(0, DB::table('statistic_feature_runs')->count());
            $this->assertSame(0, DB::table('statistic_feature_results')->count());
            $this->assertSame(5, RaceTacticObservation::query()->count());
        }
    }

    private function cases(): array
    {
        $path = getenv('STAT38_TEST_FIXTURE') ?: '/home/shinya/neo-keirin-artifacts/stat38-distance-laps-mvp-01-20261021-01/actual-fixture.json';
        $cases = [];
        foreach ([$path => '7ec6fae7e6ff5f8137754986c8f9e8d10bd766b9c65f61aa1252944494ba04dc',
            dirname($path).'/history-fixture.json' => '0344d20e9db4918a6a1a8cc0f691cdea724c22fc1b90c9ce2520ce12a02379ca'] as $file => $hash) {
            if (! is_file($file) || hash_file('sha256', $file) !== $hash) {
                throw new RuntimeException('Required real tactic Raw fixture missing or seal mismatch.');
            }
            $data = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
            foreach ($data['cases'] as $case) {
                $case['fixture_root'] = dirname($file);
                $cases[] = $case;
            }
        }

        return $cases;
    }

    private function seedCapture(array $case): array
    {
        $path = $case['fixture_root'].'/'.$case['fixture_file'];
        if (filesize($path) !== $case['original']['bytes'] || hash_file('sha256', $path) !== $case['original']['sha256']) {
            throw new RuntimeException('Real PJ0315 Raw seal drift.');
        }
        $bytes = file_get_contents($path);
        [$html] = app(CharacterEncodingConverter::class)->convertToUtf8($bytes, $case['log']['content_type']);
        $page = app(RaceDetailParser::class)->parse($html);
        $track = Racetrack::query()->firstOrCreate(['source' => 'keirin_jp', 'external_track_id' => $page->trackCode], ['name' => $page->trackCode]);
        $target = $case['target']['race'];
        $race = Race::unguarded(fn () => Race::query()->firstOrCreate(['id' => $target['race_id']], ['source' => 'keirin_jp',
            'external_race_id' => sprintf('%s:%s:%02d', $page->trackCode, $page->raceDate, $page->raceNumber),
            'racetrack_id' => $track->id, 'race_date' => $target['race_date'], 'race_number' => $page->raceNumber, 'entrant_count' => count($page->entries)]));
        foreach ($case['target']['entries'] as $entry) {
            RaceEntry::unguarded(fn () => RaceEntry::query()->firstOrCreate(['id' => $entry['id']], ['race_id' => $race->id,
                'bike_number' => $entry['bike_number'], 'external_player_id' => $entry['external_player_id'], 'player_id' => null,
                'fetched_at' => $case['log']['fetched_at']]));
        }
        $attrs = array_intersect_key($case['log'], array_flip((new ScrapingFetchLog)->getFillable()));
        unset($attrs['batch_run_id']);
        $attrs['raw_file_path'] = $case['fixture_file'];
        $log = ScrapingFetchLog::unguarded(fn () => ScrapingFetchLog::query()->firstOrCreate(['id' => $case['log']['id']], $attrs));
        $this->writeNew($this->temporary->path().'/raw/'.$case['fixture_file'], $bytes);

        return [$race, $log, $html];
    }

    private function controlLog(ScrapingFetchLog $original, string $html, array $pj): ScrapingFetchLog
    {
        $pc = app(EmbeddedJsonExtractor::class)->extract($html, 'PC0201');
        $changed = '<!-- ISOLATED BOUNDARY/INVALID MUTATION, NOT HISTORICAL EVIDENCE --><script>jsonData["PC0201"] = '
            .json_encode($pc, JSON_THROW_ON_ERROR).';jsonData["PJ0315"] = '.json_encode($pj, JSON_THROW_ON_ERROR).';</script>';
        $log = $original->replicate();
        $log->raw_file_path = 'control-'.bin2hex(random_bytes(8)).'.html';
        $log->sha256 = hash('sha256', $changed);
        $log->response_size = strlen($changed);
        $log->content_type = 'text/html; charset=UTF-8';
        $log->save();
        $this->writeNew($this->temporary->path().'/raw/'.$log->raw_file_path, $changed);

        return $log;
    }

    private function writeNew(string $path, string $bytes): void
    {
        $stream = fopen($path, 'xb');
        if ($stream === false || fwrite($stream, $bytes) !== strlen($bytes) || ! fflush($stream)) {
            throw new RuntimeException('Cannot create isolated tactic test fixture.');
        }
        fclose($stream);
    }

    private function oldState(): array
    {
        return ['races' => DB::table('races')->orderBy('id')->get()->toJson(),
            'entries' => DB::table('race_entries')->orderBy('id')->get()->toJson(),
            'results' => DB::table('race_results')->count(), 'distance_observations' => DB::table('race_distance_observations')->count(),
            'distance_columns' => Schema::getColumns('race_distance_observations')];
    }
}
