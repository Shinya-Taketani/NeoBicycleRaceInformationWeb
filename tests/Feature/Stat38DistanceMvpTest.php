<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Keirin\Scraping\Exceptions\ParserException;
use App\Domain\Keirin\Scraping\Parsers\EmbeddedJsonExtractor;
use App\Domain\Keirin\Scraping\Parsers\RaceDetailParser;
use App\Domain\Keirin\Scraping\Services\RaceDistanceObservationService;
use App\Domain\Keirin\Scraping\Support\CharacterEncodingConverter;
use App\Domain\Keirin\Statistics\Calculators\Stat38Calculator;
use App\Domain\Keirin\Statistics\DTO\Stat38InputDto;
use App\Domain\Keirin\Statistics\Repositories\Stat38InputRepository;
use App\Domain\Keirin\Statistics\Services\Stat38FeatureBuildService;
use App\Models\Race;
use App\Models\RaceDistanceObservation;
use App\Models\RaceEntry;
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

final class Stat38DistanceMvpTest extends TestCase
{
    use RefreshDatabase;

    private ?CompositionResultTemporaryDirectory $temporary = null;

    private const FIXTURE_SHA = '7ec6fae7e6ff5f8137754986c8f9e8d10bd766b9c65f61aa1252944494ba04dc';

    public function createApplication(): Application
    {
        $this->temporary = CompositionResultTemporaryDirectory::create();
        $root = $this->temporary->path();
        try {
            foreach (['bootstrap', 'bootstrap/cache', 'runtime', 'raw'] as $dir) {
                if (! mkdir($root.'/'.$dir, 0700)) {
                    throw new RuntimeException('Could not create STAT-38 isolated directory.');
                }
            }
            $handle = fopen($root.'/.env', 'xb');
            $text = "# Isolated STAT-38 test. No application secrets.\n";
            if ($handle === false || fwrite($handle, $text) !== strlen($text) || ! fflush($handle)) {
                throw new RuntimeException('Could not create STAT-38 test environment.');
            }
            fclose($handle);
            $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
            $this->traitsUsedByTest = class_uses_recursive(self::class);
            $app->useEnvironmentPath($root);
            $app->useBootstrapPath($root.'/bootstrap');
            $app->useStoragePath($root.'/runtime');
            $app->make(Kernel::class)->bootstrap();
            $app['config']->set(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
                'database.connections.sqlite.url' => null, 'logging.default' => 'null',
                'filesystems.disks.stat38_test' => ['driver' => 'local', 'root' => $root.'/raw', 'throw' => true],
                'keirin.raw_disk' => 'stat38_test']);
            $app->bind(Factory::class, fn () => throw new RuntimeException('HTTP is forbidden in STAT-38 tests.'));

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

    public function test_actual_saved_raw_for_each_development_year_preserves_parser_values_and_source_seal(): void
    {
        $years = [];
        foreach ($this->cases() as $case) {
            [$race, $log] = $this->seedActual($case);
            $before = $this->businessSnapshot();
            $out = app(RaceDistanceObservationService::class)->import((int) $race->id, (int) $log->id, false);
            $row = RaceDistanceObservation::query()->findOrFail($out['observation_id']);
            $this->assertSame($case['expected']['distance'], $row->planned_distance_m);
            $this->assertSame($case['expected']['laps'], $row->planned_laps);
            $this->assertSame($case['original']['sha256'], $row->raw_sha256);
            $this->assertSame($case['original']['bytes'], $row->raw_bytes);
            $this->assertSame($log->raw_file_path, $row->raw_file_path);
            $this->assertSame($log->request_url, $row->source_url);
            $this->assertSame($log->parser_version, $row->source_parser_version);
            $this->assertSame('AT_OR_AFTER_SAME_RAW_START', $row->timing_status);
            $this->assertNull($row->actual_distance_m);
            $this->assertNull($row->actual_laps);
            $this->assertSame('NOT_AUTHORIZED', $row->metadata['prediction_use']);
            $this->assertSame($before, $this->businessSnapshot());
            app(RaceDistanceObservationService::class)->verifyStored($row);
            $years[$race->race_date->format('Y')] = true;
        }
        $this->assertCount(4, $years);
        $this->assertSame('128M', ini_get('memory_limit'));
    }

    public function test_reprocessing_is_idempotent_and_real_recaptures_are_retained(): void
    {
        $cases = $this->cases();
        foreach ([$cases[0], $cases[1]] as $case) {
            [$race, $log] = $this->seedActual($case);
            $first = app(RaceDistanceObservationService::class)->import($race->id, $log->id, false);
            $repeat = app(RaceDistanceObservationService::class)->import($race->id, $log->id, false);
            $this->assertSame(1, $first['planned_new_observations']);
            $this->assertSame(0, $repeat['planned_new_observations']);
            $this->assertSame($first['observation_id'], $repeat['observation_id']);
        }
        $this->assertSame(2, RaceDistanceObservation::query()->count());
        $this->assertSame(2, RaceDistanceObservation::query()->distinct()->count('raw_sha256'));
    }

    public function test_dry_run_import_and_feature_build_have_zero_database_writes(): void
    {
        [$race, $log] = $this->seedActual($this->cases()[0]);
        $writes = [];
        DB::listen(function ($query) use (&$writes): void {
            if (preg_match('/^\s*(insert|update|delete|create|alter|drop|replace)\b/i', $query->sql)) {
                $writes[] = strtok($query->sql, ' ');
            }
        });
        $import = app(RaceDistanceObservationService::class)->import($race->id, $log->id, true);
        $features = app(Stat38FeatureBuildService::class)->build($race->id, new DateTimeImmutable('2025-01-01T10:53:00+09:00'), true);
        $this->assertSame(1, $import['planned_new_observations']);
        $this->assertNull($import['observation_id']);
        $this->assertTrue($features['dry_run']);
        $this->assertNull($features['feature_run_id']);
        $this->assertSame([], $writes);
        $this->assertSame(0, DB::table('statistic_feature_runs')->count());
        $this->assertSame(0, RaceDistanceObservation::query()->count());
    }

    public function test_post_acquired_real_values_are_retrospective_only_and_experience_is_null_not_zero(): void
    {
        [$race, $log] = $this->seedActual($this->cases()[0]);
        app(RaceDistanceObservationService::class)->import($race->id, $log->id, false);
        $out = app(Stat38FeatureBuildService::class)->build($race->id, new DateTimeImmutable('2025-01-01T10:53:00+09:00'), false);
        $this->assertCount(5, $out['features']);
        foreach ($out['features'] as $row) {
            $f = $row['features'];
            $this->assertNull($f['PLANNED_DISTANCE_M']);
            $this->assertNull($f['PLANNED_LAPS']);
            $this->assertNull($f['PREDICTION_EXPERIENCE_COUNT']);
            $this->assertNull($f['PREDICTION_SAME_DISTANCE_COUNT']);
            $this->assertSame('NOT_ESTABLISHED', $f['PREDICTION_EXPERIENCE_STATUS']);
            $this->assertSame(1700, $f['RETROSPECTIVE']['planned_distance_m']);
            $this->assertSame(5, $f['RETROSPECTIVE']['planned_laps']);
            $this->assertSame('RETROSPECTIVE_ONLY', $f['RETROSPECTIVE']['use']);
            $this->assertNull($f['RETROSPECTIVE']['confirmed_started_count']);
            $this->assertNull($f['POINTS']);
            $this->assertNull($f['CONFIDENCE']);
        }
        $this->assertSame(5, DB::table('statistic_feature_results')->where('stat_code', 'STAT-38')->count());
        $this->assertSame(0, DB::table('statistic_feature_results')->whereNotNull('raw_points')->count());
        $this->assertSame(0, DB::table('statistic_feature_results')->where('stat_code', '!=', 'STAT-38')->count());
    }

    public function test_missing_null_and_invalid_values_are_not_replaced_with_zero_or_estimates(): void
    {
        [$race, $log, $html] = $this->seedActual($this->cases()[0]);
        $extractor = app(EmbeddedJsonExtractor::class);
        $pc = $extractor->extract($html, 'PC0201');
        $pj = $extractor->extract($html, 'PJ0315');
        foreach (['absent', 'null'] as $mode) {
            $changed = $pc;
            if ($mode === 'absent') {
                unset($changed['C0201data']['C0201racedtl']['kyori'], $changed['C0201data']['C0201racedtl']['syukai']);
            } else {
                $changed['C0201data']['C0201racedtl']['kyori'] = null;
                $changed['C0201data']['C0201racedtl']['syukai'] = null;
            }
            $dto = app(RaceDistanceObservationService::class)->prepare($race, $log, $this->abnormalHtml($changed, $pj));
            $this->assertNull($dto->distance);
            $this->assertNull($dto->laps);
            $this->assertSame($mode !== 'absent', $dto->rawFields['kyori']['present']);
        }
        foreach ([0, -1, 'invalid', [], 10001] as $bad) {
            $changed = $pc;
            $changed['C0201data']['C0201racedtl']['kyori'] = $bad;
            try {
                app(RaceDistanceObservationService::class)->prepare($race, $log, $this->abnormalHtml($changed, $pj));
                $this->fail('Invalid distance must be rejected.');
            } catch (ParserException) {
                $this->assertSame(0, RaceDistanceObservation::query()->count());
            }
        }
    }

    public function test_wrong_race_day_track_number_and_entrant_identity_are_rejected(): void
    {
        [$race, $log] = $this->seedActual($this->cases()[0]);
        foreach (['race_number' => 12, 'race_date' => '2025-01-02', 'external_race_id' => 'wrong'] as $key => $value) {
            $changed = clone $race;
            $changed->$key = $value;
            try {
                app(RaceDistanceObservationService::class)->prepare($changed, $log, $this->html($this->cases()[0]));
                $this->fail('Mismatched race identity accepted.');
            } catch (RuntimeException $error) {
                $this->assertStringContainsString('mismatch', $error->getMessage());
            }
        }
        app(RaceDistanceObservationService::class)->import($race->id, $log->id, false);
        $entry = RaceEntry::query()->where('race_id', $race->id)->firstOrFail();
        $entry->update(['external_player_id' => '000001']); // Abnormal identity; never a replacement for missing real data.
        $this->expectException(\InvalidArgumentException::class);
        app(Stat38FeatureBuildService::class)->build($race->id, new DateTimeImmutable('2025-01-01T10:53:00+09:00'), true);
    }

    public function test_raw_byte_drift_and_fetch_metadata_mismatch_fail_before_observation_publication(): void
    {
        [$race, $log] = $this->seedActual($this->cases()[0]);
        $path = $this->temporary->path().'/raw/'.$log->raw_file_path;
        $handle = fopen($path, 'r+b');
        fwrite($handle, '!');
        fclose($handle);
        $this->expectException(RuntimeException::class);
        try {
            app(RaceDistanceObservationService::class)->import($race->id, $log->id, false);
        } finally {
            $this->assertSame(0, RaceDistanceObservation::query()->count());
        }
    }

    public function test_invalid_source_and_year_are_rejected_without_any_raw_fallback(): void
    {
        [$race, $log] = $this->seedActual($this->cases()[0]);
        foreach (['http_status' => 302, 'request_url' => 'https://invalid.example/', 'response_size' => 1, 'sha256' => str_repeat('0', 64),
            'raw_file_path' => '../outside.html'] as $key => $value) {
            $before = $log->$key;
            $log->update([$key => $value]);
            try {
                app(RaceDistanceObservationService::class)->import($race->id, $log->id, false);
                $this->fail('Invalid source accepted.');
            } catch (RuntimeException) {
                $this->assertSame(0, RaceDistanceObservation::query()->count());
            } finally {
                $log->update([$key => $before]);
            }
        }
        $race->update(['race_date' => '2026-01-01']); // Rejection-only artificial target, no 2026 Raw.
        $this->expectException(ModelNotFoundException::class);
        app(RaceDistanceObservationService::class)->import($race->id, $log->id, false);
    }

    public function test_append_only_database_constraints_and_existing_schema_are_preserved(): void
    {
        [$race, $log] = $this->seedActual($this->cases()[0]);
        $columns = Schema::getColumns('races');
        $resultColumns = Schema::getColumns('race_results');
        app(RaceDistanceObservationService::class)->import($race->id, $log->id, false);
        $this->assertSame($columns, Schema::getColumns('races'));
        $this->assertSame($resultColumns, Schema::getColumns('race_results'));
        $this->assertCount(2, Schema::getForeignKeys('race_distance_observations'));
        foreach (Schema::getForeignKeys('race_distance_observations') as $fk) {
            $this->assertSame('restrict', $fk['on_delete']);
        }
        $this->assertTrue(collect(Schema::getIndexes('race_distance_observations'))->keyBy('name')['distance_observations_capture_version_unique']['unique']);
        foreach (['UPDATE race_distance_observations SET planned_distance_m=1', 'DELETE FROM race_distance_observations'] as $sql) {
            try {
                DB::statement($sql);
                $this->fail('Immutable observation mutation accepted.');
            } catch (QueryException) {
                $this->assertSame(1700, RaceDistanceObservation::query()->firstOrFail()->planned_distance_m);
            }
        }
        $migration = require database_path('migrations/2026_10_21_000014_create_race_distance_observations.php');
        $this->expectException(RuntimeException::class);
        $migration->down();
    }

    public function test_commands_default_to_plan_and_explicit_dry_run_does_not_persist(): void
    {
        [$race, $log] = $this->seedActual($this->cases()[0]);
        $this->artisan('keirin:stat38:import-distance', ['--race-id' => (string) $race->id, '--fetch-log-id' => [(string) $log->id]])->assertExitCode(0);
        $this->artisan('keirin:stat38:import-distance', ['--race-id' => (string) $race->id, '--fetch-log-id' => [(string) $log->id], '--execute' => true, '--dry-run' => true])->assertExitCode(0);
        $this->artisan('keirin:statistics:build-stat38', ['--race-id' => (string) $race->id, '--input-as-of' => '2025-01-01T10:53:00+09:00', '--execute' => true, '--dry-run' => true])->assertExitCode(0);
        $this->artisan('keirin:statistics:build-stat38', ['--race-id' => (string) $race->id, '--input-as-of' => '2025-01-01 10:53:00'])->assertExitCode(1);
        $this->assertSame(0, RaceDistanceObservation::query()->count());
        $this->assertSame(0, DB::table('statistic_feature_runs')->count());
    }

    public function test_real_same_player_past_entries_are_counted_separately_without_inventing_actual_starts(): void
    {
        $cases = $this->historyCases();
        $target = $this->cases()[0];
        foreach ([...$cases, $target, $this->cases()[1]] as $case) {
            [$race, $log] = $this->seedActual($case);
            app(RaceDistanceObservationService::class)->import($race->id, $log->id, false);
        }
        $result = app(Stat38FeatureBuildService::class)->build($target['target']['race']['race_id'], new DateTimeImmutable('2025-01-01T10:53:00+09:00'), true);
        foreach ($result['features'] as $item) {
            $entry = RaceEntry::query()->findOrFail($item['race_entry_id']);
            $f = $item['features'];
            $this->assertSame($entry->external_player_id === '014236' ? 2 : 0, $f['RETROSPECTIVE']['observed_past_entry_count']);
            $this->assertSame(0, $f['RETROSPECTIVE']['same_distance_observed_entry_count']); // 1625 != 1700, no "similar" conversion.
            $this->assertNull($f['RETROSPECTIVE']['confirmed_started_count']);
            $this->assertNull($f['PREDICTION_EXPERIENCE_COUNT']);
        }
        $same = app(Stat38FeatureBuildService::class)->build($cases[1]['target']['race']['race_id'], new DateTimeImmutable('2024-01-02T23:00:00+09:00'), true);
        $id = RaceEntry::query()->where('race_id', $cases[1]['target']['race']['race_id'])->where('external_player_id', '014236')->value('id');
        $row = collect($same['features'])->firstWhere('race_entry_id', $id);
        $this->assertSame(1, $row['features']['RETROSPECTIVE']['observed_past_entry_count']);
        $this->assertSame(1, $row['features']['RETROSPECTIVE']['same_distance_observed_entry_count']);
        $this->assertNull($row['features']['RETROSPECTIVE']['same_distance_started_count']);
    }

    public function test_stored_observation_is_reverified_before_feature_output(): void
    {
        [$race, $log] = $this->seedActual($this->cases()[0]);
        app(RaceDistanceObservationService::class)->import($race->id, $log->id, false);
        $log->update(['sha256' => str_repeat('0', 64)]);
        $this->expectException(RuntimeException::class);
        try {
            app(Stat38FeatureBuildService::class)->build($race->id, new DateTimeImmutable('2025-01-01T10:53:00+09:00'), false);
        } finally {
            $this->assertSame(0, DB::table('statistic_feature_runs')->count());
            $this->assertSame(0, DB::table('statistic_feature_results')->count());
        }
    }

    public function test_other_player_duplicate_target_and_future_history_are_rejected(): void
    {
        foreach ([$this->cases()[0], ...$this->historyCases()] as $case) {
            [$race, $log] = $this->seedActual($case);
            app(RaceDistanceObservationService::class)->import($race->id, $log->id, false);
        }
        foreach (app(Stat38InputRepository::class)->inputs(12542, new DateTimeImmutable('2025-01-01T10:53:00+09:00')) as $input) {
            if ($input->entry['external_player_id'] !== '014236') {
                continue;
            }
            $history = iterator_to_array($input->history, false);
            $this->assertCount(2, $history);
            foreach ([['external_player_id' => '000001'], ['bike_number' => 9], ['race_id' => 12542], ['race_date' => '2026-01-01']] as $change) {
                $bad = array_replace($history[0], $change); // Rejection-only mutations of real evidence.
                try {
                    app(Stat38Calculator::class)->calculate(new Stat38InputDto($input->raceId, $input->raceDate, $input->inputAsOf,
                        $input->entry, $input->predictionObservation, $input->retrospectiveObservation, [$bad]));
                    $this->fail('Invalid historical identity/time accepted.');
                } catch (\InvalidArgumentException) {
                    $this->assertTrue(true);
                }
            }
            $this->expectException(\InvalidArgumentException::class);
            app(Stat38Calculator::class)->calculate(new Stat38InputDto($input->raceId, $input->raceDate, $input->inputAsOf,
                $input->entry, $input->predictionObservation, $input->retrospectiveObservation, [$history[0], $history[0]]));
        }
        $this->fail('Real matching player was not found.');
    }

    public function test_unknown_equal_and_post_start_acquisition_are_explicit_and_not_prestart_proof(): void
    {
        [$race, $log, $html] = $this->seedActual($this->cases()[0]);
        $service = app(RaceDistanceObservationService::class);
        $dto = $service->prepare($race, $log, $html);
        foreach ([null, $dto->scheduledStartAt, $dto->scheduledStartAt->modify('+1 second')] as $stamp) {
            $abnormal = clone $log;
            $abnormal->fetched_at = $stamp; // Isolated timing rejection case; not a historical acquisition claim.
            $value = $service->prepare($race, $abnormal, $html)->attributes(RaceDistanceObservationService::VERSION);
            $this->assertSame($stamp === null ? 'UNKNOWN' : 'AT_OR_AFTER_SAME_RAW_START', $value['timing_status']);
            $this->assertSame('NOT_AUTHORIZED', $value['metadata']['prediction_use']);
        }
    }

    private function historyCases(): array
    {
        $fixture = getenv('STAT38_TEST_FIXTURE') ?: '/home/shinya/neo-keirin-artifacts/stat38-distance-laps-mvp-01-20261021-01/actual-fixture.json';
        $path = dirname($fixture).'/history-fixture.json';
        if (! is_file($path) || hash_file('sha256', $path) !== '0344d20e9db4918a6a1a8cc0f691cdea724c22fc1b90c9ce2520ce12a02379ca') {
            throw new RuntimeException('Actual same-player history fixture unavailable or changed.');
        }
        $data = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        foreach ($data['cases'] as &$case) {
            $case['fixture_root'] = dirname($path);
        }

        return $data['cases'];
    }

    private function cases(): array
    {
        $path = getenv('STAT38_TEST_FIXTURE') ?: '/home/shinya/neo-keirin-artifacts/stat38-distance-laps-mvp-01-20261021-01/actual-fixture.json';
        if (! is_file($path) || hash_file('sha256', $path) !== self::FIXTURE_SHA) {
            throw new RuntimeException('Required actual PJ0315 fixture unavailable or changed; do not fabricate data.');
        }
        $fixture = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        foreach ($fixture['cases'] as &$case) {
            $case['fixture_root'] = dirname($path);
        }

        return $fixture['cases'];
    }

    private function html(array $case): string
    {
        $path = $case['fixture_root'].'/'.$case['fixture_file'];
        if (filesize($path) !== $case['original']['bytes'] || hash_file('sha256', $path) !== $case['original']['sha256']) {
            throw new RuntimeException('Actual fixture Raw seal mismatch.');
        }
        [$html] = app(CharacterEncodingConverter::class)->convertToUtf8(file_get_contents($path), $case['log']['content_type']);

        return $html;
    }

    private function seedActual(array $case): array
    {
        $page = app(RaceDetailParser::class)->parse($this->html($case));
        $t = $case['target']['race'];
        $track = Racetrack::query()->firstOrCreate(['source' => 'keirin_jp', 'external_track_id' => $page->trackCode], ['name' => $page->trackCode]);
        $race = Race::unguarded(fn () => Race::query()->firstOrCreate(['id' => $t['race_id']], ['source' => 'keirin_jp',
            'external_race_id' => sprintf('%s:%s:%02d', $page->trackCode, $page->raceDate, $page->raceNumber),
            'racetrack_id' => $track->id, 'race_date' => $t['race_date'], 'race_number' => $t['race_number'],
            'scheduled_start_at' => $t['race_date'].' '.$page->startTime.':00', 'entrant_count' => count($page->entries)]));
        foreach ($case['target']['entries'] as $entry) {
            RaceEntry::unguarded(fn () => RaceEntry::query()->firstOrCreate(['id' => $entry['id']], ['race_id' => $race->id, 'player_id' => null,
                'bike_number' => $entry['bike_number'], 'external_player_id' => $entry['external_player_id'],
                'fetched_at' => $case['log']['fetched_at'],
                'player_name' => collect($page->entries)->first(fn ($e) => $e->bikeNumber === $entry['bike_number'])->playerName]));
        }
        $attrs = array_intersect_key($case['log'], array_flip((new ScrapingFetchLog)->getFillable()));
        unset($attrs['batch_run_id']);
        $attrs['raw_file_path'] = $case['fixture_file'];
        $log = ScrapingFetchLog::unguarded(fn () => ScrapingFetchLog::query()->firstOrCreate(['id' => $case['log']['id']], $attrs));
        $destination = $this->temporary->path().'/raw/'.$attrs['raw_file_path'];
        if (! file_exists($destination)) {
            $bytes = file_get_contents($case['fixture_root'].'/'.$case['fixture_file']);
            $handle = fopen($destination, 'xb');
            if ($handle === false || fwrite($handle, $bytes) !== strlen($bytes) || ! fflush($handle)) {
                throw new RuntimeException('Could not copy actual fixture to isolated storage.');
            }
            fclose($handle);
        }

        return [$race->refresh(), $log->refresh(), $this->html($case)];
    }

    private function abnormalHtml(array $pc, array $pj): string
    {
        return '<!-- ABNORMAL TEST MUTATION OF ACTUAL SAVED JSON, NOT NEW RACE DATA --><script>jsonData["PC0201"] = '
            .json_encode($pc, JSON_THROW_ON_ERROR).'; jsonData["PJ0315"] = '.json_encode($pj, JSON_THROW_ON_ERROR).';</script>';
    }

    private function businessSnapshot(): array
    {
        return [DB::table('races')->orderBy('id')->get()->toJson(), DB::table('race_entries')->orderBy('id')->get()->toJson(),
            DB::table('race_results')->count(), DB::table('statistic_feature_results')->count()];
    }
}
