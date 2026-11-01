<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Keirin\Scraping\Parsers\EmbeddedJsonExtractor;
use App\Domain\Keirin\Scraping\Support\CharacterEncodingConverter;
use App\Domain\Keirin\Statistics\OfficialMethodHistory\Reader;
use App\Domain\Keirin\Statistics\SavedWeather\Batch;
use App\Domain\Keirin\Statistics\SavedWeather\Derive;
use App\Domain\Keirin\Statistics\SavedWeather\Files;
use App\Domain\Keirin\Statistics\SavedWeather\LegacyInput;
use App\Domain\Keirin\Statistics\SavedWeather\MetadataExporter;
use App\Domain\Keirin\Statistics\SavedWeather\OutputFailure;
use App\Domain\Keirin\Statistics\SavedWeather\Prepare;
use App\Domain\Keirin\Statistics\SavedWeather\Repository;
use App\Domain\Keirin\Statistics\SavedWeather\Snapshot;
use App\Domain\Keirin\Statistics\SavedWeather\Store;
use App\Domain\Keirin\Statistics\SavedWeather\Writer;
use App\Domain\Keirin\Statistics\Services\SavedResultWeatherProjectionService;
use App\Models\Race;
use App\Models\RaceEntry;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\CompositionResultTemporaryDirectory;
use Tests\Support\SavedWeatherResultFixture;
use Tests\TestCase;
use Throwable;

final class SavedWeatherBatchTest extends TestCase
{
    private ?CompositionResultTemporaryDirectory $temporary = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
    }

    public function createApplication(): Application
    {
        $this->temporary = CompositionResultTemporaryDirectory::create();
        $root = $this->temporary->path();
        try {
            foreach (['bootstrap', 'bootstrap/cache', 'runtime', 'raw'] as $dir) {
                mkdir($root.'/'.$dir, 0700);
            }
            $env = Files::create($root.'/.env');
            if (fwrite($env, "# Isolated weather test. No application secrets.\n") === false) {
                throw new RuntimeException('Cannot create artificial env.');
            }
            fclose($env);
            $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
            $this->traitsUsedByTest = class_uses_recursive(self::class);
            $app->useEnvironmentPath($root)->useBootstrapPath($root.'/bootstrap')->useStoragePath($root.'/runtime');
            $app->make(Kernel::class)->bootstrap();
            $app['config']->set(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
                'database.connections.sqlite.url' => null, 'logging.default' => 'null', 'keirin.raw_disk' => 'weather_fixture',
                'filesystems.disks.weather_fixture' => ['driver' => 'local', 'root' => $root.'/raw', 'throw' => true]]);
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

    private function savedCases(): array
    {
        $path = SavedWeatherResultFixture::path();
        $this->assertSame('706e5747f6383711055d1ded3c16575016cfc22148823cc75379117410ee3f11', hash_file('sha256', $path));
        $cases = array_values(array_filter(Files::read($path)['cases'], fn ($c) => $c['race']['race_date'] >= '2024-01-01'));
        DB::statement('PRAGMA foreign_keys=OFF');
        foreach ($cases as $case) {
            $race = $case['race'];
            unset($race['encrypted_parameter']);
            DB::table('racetracks')->insertOrIgnore(['id' => $race['racetrack_id'], 'source' => 'keirin_jp', 'external_track_id' => $case['track_code'], 'name' => $case['track_code']]);
            DB::table('races')->insert($race);
            DB::table('race_entries')->insert($case['entries']);
            DB::table('race_results')->insert($case['results']);
            foreach (['log' => 'scraping_fetch_logs', 'import' => 'race_result_imports'] as $key => $table) {
                $row = $case[$key];
                $row['raw_file_path'] = $case['fixture_file'];
                DB::table($table)->insert($row);
            }
            $body = file_get_contents(dirname($path).'/'.$case['fixture_file']);
            $this->assertSame($case['original']['sha256'], hash('sha256', $body));
            $h = Files::create($this->temporary->path().'/raw/'.$case['fixture_file']);
            fwrite($h, $body);
            fclose($h);
        }

        return $cases;
    }

    private function input(?callable $change = null): array
    {
        $root = $this->temporary->path();
        $change?->__invoke();
        $dbPath = $root.'/fixed.sqlite';
        DB::statement("VACUUM INTO '".$dbPath."'");
        $db = Files::sqlite($dbPath);
        $scope = Files::create($root.'/scope.jsonl');
        foreach (Files::select($db, 'SELECT r.*,t.external_track_id AS track_code FROM races r JOIN racetracks t ON t.id=r.racetrack_id ORDER BY r.id') as $race) {
            Files::append($scope, ['race' => $race, 'entries' => Files::select($db, 'SELECT * FROM race_entries WHERE race_id=? ORDER BY id', [$race['id']]),
                'results' => Files::select($db, 'SELECT * FROM race_results WHERE race_id=? ORDER BY id', [$race['id']])]);
        }
        fclose($scope);
        $groups = [];
        $h = Files::create($root.'/candidates.jsonl');
        foreach ((new Repository)->inventory($db, $db, $root.'/scope.jsonl', ['existing' => $root.'/raw', 'supplement' => $root.'/raw']) as $group) {
            Files::append($h, $group);
            $groups[] = $group;
        }
        fclose($h);
        Files::write($root.'/parent.json', ['fixture' => 'ARTIFICIAL_ISOLATED_MANIFEST_WITH_SEALED_REAL_RAW']);
        $m = ['contract' => Files::VERSION.'-INPUT', 'sqlite' => Files::seal($dbPath), 'original_sqlite' => Files::seal($dbPath),
            'code' => Files::code(),
            'parent' => Files::seal($root.'/parent.json'), 'races' => count($groups),
            'files' => ['candidates.jsonl' => Files::seal($root.'/candidates.jsonl'), 'scope.jsonl' => Files::seal($root.'/scope.jsonl')]];
        Files::write($root.'/manifest.json', $m);
        Files::write($root.'/COMPLETE.json', ['manifest' => Files::seal($root.'/manifest.json')]);

        return [$root.'/manifest.json', Files::seal($root.'/manifest.json')['sha256'], $groups];
    }

    public function test_real_projection_save_reload_dry_run_and_chunk_invariance(): void
    {
        $cases = $this->savedCases();
        $old = [];
        foreach ($cases as $case) {
            $entry = RaceEntry::query()->where('race_id', $case['race']['id'])->orderBy('bike_number')->firstOrFail();
            $old[$case['race']['id']] = app(SavedResultWeatherProjectionService::class)->project(Race::findOrFail($entry->race_id), $entry);
        }
        [$path, $sha, $groups] = $this->input();
        $before = Files::seal($this->temporary->path().'/fixed.sqlite');
        $batch = app(Batch::class);
        $dry = $batch->run($path, $sha, $this->temporary->path().'/not-created', true, 1);
        $this->assertSame(count($cases), $dry['projected']);
        $this->assertSame(0, $dry['saved']);
        $this->assertDirectoryDoesNotExist($this->temporary->path().'/not-created');
        foreach ([1, 100] as $chunk) {
            $out = $this->temporary->path().'/out'.$chunk;
            $summary = $batch->run($path, $sha, $out, false, $chunk);
            $this->assertSame(count($cases), $summary['saved']);
            $this->assertSame(count($groups), $summary['candidates']);
            $this->assertSame('VERIFIED', (new Store)->verify($out, Files::seal($out.'/manifest.json')['sha256'])['status']);
            foreach (Files::rows($out.'/projections.jsonl') as $row) {
                $this->assertSame($old[$row['projection']['race_id']], $row['projection']);
                $this->assertFalse($row['projection']['historical_as_of_available']);
                $this->assertNull($row['projection']['points']);
            }
        }
        $this->assertSame(Files::seal($this->temporary->path().'/out1/projections.jsonl')['sha256'], Files::seal($this->temporary->path().'/out100/projections.jsonl')['sha256']);
        $this->assertSame($before, Files::seal($this->temporary->path().'/fixed.sqlite'));
        $this->assertSame('sqlite', DB::getDefaultConnection());
        $this->assertSame(0, DB::table('statistic_feature_runs')->count());
    }

    #[DataProvider('exclusions')]
    public function test_classifications_keep_denominators_without_projection(string $mode, string $reason): void
    {
        $cases = $this->savedCases();
        $id = $cases[0]['race']['id'];
        [$path, $sha, $groups] = $this->input(function () use ($id, $mode): void {
            if ($mode === 'no-results') {
                DB::table('race_results')->where('race_id', $id)->delete();
            } elseif ($mode === 'null') {
                DB::table('race_results')->where('race_id', $id)->update(['race_result_import_id' => null]);
            } elseif ($mode === 'cancelled' || $mode === 'unconfirmed') {
                DB::table('races')->where('id', $id)->update(['result_status' => $mode === 'cancelled' ? 'CANCELLED' : 'PROVISIONAL']);
            } elseif ($mode === 'multiple') {
                DB::table('race_results')->where('id', DB::table('race_results')->where('race_id', $id)->min('id'))->update(['race_result_import_id' => 999999]);
            } elseif ($mode === 'missing-log') {
                DB::table('scraping_fetch_logs')->where('id', DB::table('race_result_imports')->where('race_id', $id)->value('scraping_fetch_log_id'))->delete();
            } elseif ($mode === 'missing-import') {
                DB::table('race_result_imports')->where('race_id', $id)->delete();
            }
        });
        $match = array_values(array_filter($groups, fn ($g) => $g['race_id'] === $id))[0];
        $this->assertSame($reason, $match['candidates'][0]['reason']);
        $result = app(Batch::class)->run($path, $sha, null, true);
        $this->assertSame(count($cases), $result['races']);
        $this->assertSame(0, $result['exit_code']);
        $this->assertSame($result['candidates'], $result['projected'] + $result['excluded']);
    }

    public static function exclusions(): array
    {
        return [['no-results', 'NO_CURRENT_RESULT'], ['null', 'NOT_REFERENCED_BY_CURRENT_RESULTS'],
            ['cancelled', 'CANCELLED_NO_CURRENT_RESULT_ANCHOR'], ['unconfirmed', 'RACE_NOT_CONFIRMED'],
            ['multiple', 'AMBIGUOUS_CURRENT_IMPORT'], ['missing-log', 'FETCH_LOG_NOT_EXTRACTED'], ['missing-import', 'IMPORT_NOT_EXTRACTED']];
    }

    #[DataProvider('corruptions')]
    public function test_sealed_identity_raw_corruption_stops_without_anchor_fallback(string $mode): void
    {
        $cases = $this->savedCases();
        if ($mode === 'import') {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('REFERENCED_IMPORT_RACE_MISMATCH');
        }
        [$path, $sha, $groups] = $this->input(function () use ($cases, $mode): void {
            $race = $cases[0]['race']['id'];
            if ($mode === 'person') {
                DB::table('race_entries')->where('race_id', $race)->where('bike_number', 1)->update(['external_player_id' => '999999']);
            } elseif ($mode === 'date') {
                DB::table('races')->where('id', $race)->update(['race_date' => '2024-01-02']);
            } elseif ($mode === 'import') {
                DB::table('race_result_imports')->where('race_id', $race)->update(['race_id' => $cases[1]['race']['id']]);
            }
        });
        if ($mode === 'raw') {
            $h = fopen($groups[0]['candidates'][0]['raw']['path'], 'ab');
            fwrite($h, 'X');
            fclose($h);
        }
        $out = $this->temporary->path().'/failed';
        $result = app(Batch::class)->run($path, $sha, $out);
        $this->assertSame(1, $result['exit_code']);
        $this->assertGreaterThan(0, $result['failed']);
        $this->assertFileExists($out.'/FAILED.json');
        $this->assertFileDoesNotExist($out.'/COMPLETE.json');
        $this->assertSame($result['candidates'], $result['projected'] + $result['excluded'] + $result['failed'] + $result['not_processed']);
    }

    public static function corruptions(): array
    {
        return [['person'], ['date'], ['raw'], ['import']];
    }

    public function test_output_collision_and_input_sha_are_rejected_without_writes(): void
    {
        $this->savedCases();
        [$path, $sha] = $this->input();
        $this->artisan('keirin:statistics:build-saved-result-weather', ['--input' => $path, '--input-sha256' => str_repeat('0', 64), '--dry-run' => true])->assertExitCode(1);
        $out = $this->temporary->path().'/exists';
        mkdir($out, 0700);
        $this->artisan('keirin:statistics:build-saved-result-weather', ['--input' => $path, '--input-sha256' => $sha, '--output' => $out])->assertExitCode(1);
        $this->assertSame(['.', '..'], scandir($out));
    }

    public function test_reload_rejects_extra_files_missing_and_resealed_extra_candidate_rows(): void
    {
        $this->savedCases();
        [$path, $sha] = $this->input();
        $out = $this->temporary->path().'/out';
        app(Batch::class)->run($path, $sha, $out);
        Files::write($out.'/FAILED.json', ['artificial' => true]);
        $this->expectException(RuntimeException::class);
        (new Store)->verify($out, Files::seal($out.'/manifest.json')['sha256']);
    }

    public function test_fixed_scope_rejects_2026_and_prepare_requires_explicit_options_before_db(): void
    {
        $this->artisan('keirin:statistics:prepare-saved-result-weather')->assertExitCode(1);
        foreach (MetadataExporter::queries() as $sql) {
            $this->assertStringContainsString("DATE '2024-01-01'", $sql);
            $this->assertStringContainsString("DATE '2025-01-04'", $sql);
            $this->assertStringContainsString('r.id=s.id', $sql);
            $this->assertStringNotContainsString('encp', $sql);
        }
    }

    #[DataProvider('preparationPeriods')]
    public function test_prepare_without_supplement_does_not_connect_and_seals_original_scope(bool $artificial2026): void
    {
        $this->savedCases();
        if ($artificial2026) {
            // Negative metadata control only; no real 2026 data is opened.
            DB::table('races')->update(['race_date' => '2026-01-01']);
        }
        [$input] = $this->input();
        $root = $this->temporary->path();
        $parent = Files::read($input);
        mkdir($root.'/parent', 0700);
        Files::write($root.'/parent/manifest.json', ['working_sqlite' => $parent['sqlite']]);
        Files::write($root.'/parent/COMPLETE.json', ['manifest' => Files::seal($root.'/parent/manifest.json')]);
        mkdir($root.'/snapshot', 0700);
        $files = [];
        foreach (['races', 'race_entries', 'race_results'] as $table) {
            $h = Files::create($root.'/snapshot/'.$table.'.jsonl');
            foreach (DB::table($table)->orderBy('id')->get() as $row) {
                Files::append($h, (array) $row);
            }
            fclose($h);
            $seal = Files::seal($root.'/snapshot/'.$table.'.jsonl');
            $files[$table] = ['path' => $table.'.jsonl', 'sha256' => $seal['sha256'], 'bytes' => $seal['bytes'], 'sql' => 'ARTIFICIAL ISOLATED SNAPSHOT'];
        }
        Files::write($root.'/snapshot/manifest.json', ['files' => $files]);
        Files::write($root.'/snapshot/COMPLETE.json', ['manifest_sha256' => Files::seal($root.'/snapshot/manifest.json')['sha256']]);
        config(['database.connections.pgsql.host' => 'SOURCE_CONNECTION_FORBIDDEN']);
        if ($artificial2026) {
            try {
                app(Prepare::class)->run($root.'/parent/manifest.json', Files::seal($root.'/parent/manifest.json')['sha256'],
                    $root.'/snapshot', $root.'/raw', $root.'/raw', $root.'/prepared');
                $this->fail('Artificial 2026 scope was accepted.');
            } catch (RuntimeException $e) {
                $this->assertSame('FIXED_SCOPE_OUT_OF_RANGE', $e->getMessage());
                $this->assertFileExists($root.'/prepared/FAILED.json');
                $this->assertFileDoesNotExist($root.'/prepared/COMPLETE.json');
            }

            return;
        }
        $m = app(Prepare::class)->run($root.'/parent/manifest.json', Files::seal($root.'/parent/manifest.json')['sha256'],
            $root.'/snapshot', $root.'/raw', $root.'/raw', $root.'/prepared');
        $this->assertSame(0, $m['extraction']['connections']);
        $this->assertSame($parent['sqlite'], $m['sqlite']);
        $this->assertSame('VERIFIED', (new Store)->verify($this->buildPrepared($root.'/prepared'), Files::seal($root.'/prepared-output/manifest.json')['sha256'])['status']);
    }

    public static function preparationPeriods(): array
    {
        return [[false], [true]];
    }

    private function buildPrepared(string $root): string
    {
        $out = $this->temporary->path().'/prepared-output';
        $result = app(Batch::class)->run($root.'/manifest.json', Files::seal($root.'/manifest.json')['sha256'], $out);
        $this->assertSame(0, $result['exit_code']);

        return $out;
    }

    public function test_missing_row_insert_conflict_retains_old_row(): void
    {
        $this->savedCases();
        [$input] = $this->input();
        $m = Files::read($input);
        $db = new \PDO('sqlite:'.$m['sqlite']['path']);
        $row = Files::select($db, 'SELECT * FROM race_result_imports ORDER BY id LIMIT 1')[0];
        $row['source_hash'] = str_repeat('0', 64);
        $h = Files::create($this->temporary->path().'/conflicts.jsonl');
        try {
            Prepare::insertMissing($db, 'race_result_imports', $row, $h);
            $this->fail('Existing nonmissing ID was overwritten.');
        } catch (RuntimeException $e) {
            $this->assertSame('EXISTING_ID_CONFLICT', $e->getMessage());
            $this->assertNotSame($row['source_hash'], Files::select($db, 'SELECT source_hash FROM race_result_imports WHERE id=?', [$row['id']])[0]['source_hash']);
        } finally {
            fclose($h);
        }
    }

    #[DataProvider('weatherValues')]
    public function test_artificial_weather_boundaries_are_preserved_by_actual_batch_and_reload(mixed $value, bool $present): void
    {
        $cases = $this->savedCases();
        $case = $cases[0];
        $original = file_get_contents($this->temporary->path().'/raw/'.$case['fixture_file']);
        [$html] = app(CharacterEncodingConverter::class)->convertToUtf8($original, $case['log']['content_type']);
        $extract = app(EmbeddedJsonExtractor::class);
        $pc = $extract->extract($html, 'PC0201');
        $pj = $extract->extract($html, 'PJ0326');
        foreach (['tenki', 'husoku'] as $key) {
            unset($pj[$key]);
            if ($present) {
                $pj[$key] = $value;
            }
        }
        $body = '<!-- ARTIFICIAL WEATHER BOUNDARY ONLY --><script>jsonData["PC0201"] = '.trim(Files::json($pc)).';jsonData["PJ0326"] = '.trim(Files::json($pj)).';</script>';
        $h = Files::create($this->temporary->path().'/raw/weather-control.html');
        fwrite($h, $body);
        fclose($h);
        DB::table('scraping_fetch_logs')->where('id', $case['log']['id'])->update(['raw_file_path' => 'weather-control.html', 'sha256' => hash('sha256', $body), 'response_size' => strlen($body), 'content_type' => 'text/html; charset=UTF-8']);
        DB::table('race_result_imports')->where('id', $case['import']['id'])->update(['raw_file_path' => 'weather-control.html', 'source_hash' => hash('sha256', $body), 'raw_response_size' => strlen($body), 'converted_hash' => hash('sha256', $body)]);
        [$input, $sha] = $this->input();
        $out = $this->temporary->path().'/out';
        $summary = app(Batch::class)->run($input, $sha, $out);
        $this->assertSame(0, $summary['exit_code']);
        (new Store)->verify($out, Files::seal($out.'/manifest.json')['sha256']);
        foreach (Files::rows($out.'/projections.jsonl') as $row) {
            if ($row['projection']['race_id'] === $case['race']['id']) {
                $expected = app(SavedResultWeatherProjectionService::class)->fields($pj);
                $this->assertSame($expected, $row['projection']['raw_fields']);
                $this->assertSame($value, $row['projection']['raw_fields']['husoku']['raw']);
                $this->assertSame($present, $row['projection']['raw_fields']['husoku']['present']);
            }
        }
    }

    public static function weatherValues(): array
    {
        return [[null, false], [null, true], ['', true], ['0', true], [0, true], [0.0, true], ['3.4', true], ['unknown', true], [[], true]];
    }

    #[DataProvider('bundleChanges')]
    public function test_resealed_bundle_cannot_add_or_omit_candidate_or_projection_rows(string $file, bool $extra): void
    {
        $this->savedCases();
        [$input, $sha] = $this->input();
        $root = $this->temporary->path();
        app(Batch::class)->run($input, $sha, $root.'/original');
        Files::directory($root.'/changed');
        foreach (['candidate-inventory.jsonl', 'projections.jsonl', 'race-coverage.jsonl', 'coverage.json', 'coverage.csv'] as $name) {
            $bytes = file_get_contents($root.'/original/'.$name);
            if ($name === $file) {
                $line = substr($bytes, 0, strpos($bytes, "\n") + 1);
                $bytes = $extra ? $bytes.$line : substr($bytes, strlen($line));
            }
            $h = Files::create($root.'/changed/'.$name);
            fwrite($h, $bytes);
            fclose($h);
            $refs[$name] = Files::seal($root.'/changed/'.$name);
        }
        Files::write($root.'/changed/manifest.json', ['contract' => Files::VERSION, 'input' => Files::seal($input), 'files' => $refs]);
        Files::write($root.'/changed/COMPLETE.json', ['manifest' => Files::seal($root.'/changed/manifest.json')]);
        $this->expectException(RuntimeException::class);
        (new Store)->verify($root.'/changed', Files::seal($root.'/changed/manifest.json')['sha256']);
    }

    public static function bundleChanges(): array
    {
        return [['candidate-inventory.jsonl', true], ['candidate-inventory.jsonl', false], ['projections.jsonl', true], ['projections.jsonl', false]];
    }

    #[DataProvider('supplementModes')]
    public function test_b_c_supplement_changes_only_verified_pointer_and_keeps_unknown_origin(string $mode, bool $accepted): void
    {
        $this->savedCases();
        [$input] = $this->input();
        $root = $this->temporary->path().'/binding-control';
        Files::directory($root);
        $scope = Files::create($root.'/scope.jsonl');
        $outputs = [];
        foreach (['races', 'entries', 'results'] as $table) {
            $outputs[$table] = Files::create($root.'/export-'.$table.'.jsonl');
        }
        foreach (Files::rows($this->temporary->path().'/scope.jsonl') as $group) {
            $sourceRace = $group['race'];
            if ($mode === 'race-drift') {
                $sourceRace['race_number']++;
            }
            Files::append($outputs['races'], $sourceRace);
            foreach ($group['entries'] as $entry) {
                if ($mode === 'person-drift') {
                    $entry['external_player_id'] = '999999';
                }
                Files::append($outputs['entries'], $entry);
            }
            foreach ($group['results'] as &$result) {
                $current = $result;
                if ($mode === 'payload-drift') {
                    $current['rank'] = 9;
                } elseif ($mode === 'time-drift') {
                    $current['updated_at'] = '2026-10-31T01:00:00+09:00'; // Artificial retrieval time, not a 2026 race.
                }
                Files::append($outputs['results'], $current);
                if ($mode === 'omitted') {
                    unset($result['race_result_import_id']);
                } else {
                    $result['race_result_import_id'] = null;
                }
            }
            unset($result);
            Files::append($scope, $group);
        }
        fclose($scope);
        foreach ($outputs as $h) {
            fclose($h);
        }
        $sqlite = Files::read($input)['sqlite']['path'];
        $db = new \PDO('sqlite:'.$sqlite);
        $db->exec('UPDATE race_results SET race_result_import_id=NULL');
        $before = Files::select($db, 'SELECT * FROM race_results ORDER BY id');
        $h = Files::create($root.'/sidecar.jsonl');
        try {
            (new \ReflectionMethod(Prepare::class, 'links'))->invoke(app(Prepare::class), $db, $root, $h);
        } finally {
            fclose($h);
        }
        $after = Files::select($db, 'SELECT * FROM race_results ORDER BY id');
        foreach ($after as $i => $row) {
            $this->assertSame($before[$i], array_replace($row, ['race_result_import_id' => null]));
            $this->assertSame($accepted, $row['race_result_import_id'] !== null);
        }
        foreach (Files::rows($root.'/bindings.jsonl') as $row) {
            $b = $row['binding'];
            $this->assertSame($mode === 'omitted' ? 'NOT_RECORDED' : 'EXPLICIT_NULL', $b['snapshot_import_pointer']);
            $this->assertSame('NOT_PROVEN', $b['historical_import_pointer_at_snapshot']);
            $this->assertSame($accepted ? 'CURRENT_SOURCE_ROW_MATCHED' : 'SOURCE_ROW_DRIFT', $b['state']);
        }
    }

    public static function supplementModes(): array
    {
        return [['omitted', true], ['null', true], ['payload-drift', false], ['time-drift', false], ['race-drift', false], ['person-drift', false]];
    }

    #[DataProvider('fixtureModes')]
    public function test_fixture_resolution_does_not_mask_invalid_explicit_paths(string $mode): void
    {
        $previous = getenv('STAT38_RESULT_FIXTURE');
        try {
            if ($mode === 'unset' || $mode === 'empty') {
                putenv($mode === 'unset' ? 'STAT38_RESULT_FIXTURE' : 'STAT38_RESULT_FIXTURE=');
                $this->assertSame(SavedWeatherResultFixture::DEFAULT_PATH, SavedWeatherResultFixture::path());
            } else {
                $path = $this->temporary->path().'/explicit.json';
                if ($mode !== 'missing') {
                    $h = Files::create($path);
                    fwrite($h, $mode === 'valid' ? file_get_contents(SavedWeatherResultFixture::DEFAULT_PATH) : '{}');
                    fclose($h);
                }
                putenv('STAT38_RESULT_FIXTURE='.$path);
                if ($mode === 'valid') {
                    $this->assertSame($path, SavedWeatherResultFixture::path());
                } else {
                    $this->expectException(RuntimeException::class);
                    $this->expectExceptionMessage($mode === 'missing' ? 'missing or unreadable' : 'SHA mismatch');
                    SavedWeatherResultFixture::path();
                }
            }
        } finally {
            putenv($previous === false ? 'STAT38_RESULT_FIXTURE' : 'STAT38_RESULT_FIXTURE='.$previous);
        }
    }

    public static function fixtureModes(): array
    {
        return [['unset'], ['empty'], ['valid'], ['missing'], ['sha']];
    }

    #[DataProvider('snapshotChanges')]
    public function test_snapshot_rejects_changed_id_sets_and_missing_track(string $mode): void
    {
        $this->savedCases();
        $root = $this->temporary->path();
        Files::directory($root.'/snapshot');
        $files = [];
        foreach (['races', 'race_entries', 'race_results'] as $table) {
            $rows = DB::table($table)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
            $h = Files::create($root.'/snapshot/'.$table.'.jsonl');
            foreach ($rows as $row) {
                Files::append($h, $row);
            }
            if ($mode === 'duplicate' && $table === 'races') {
                Files::append($h, $rows[0]);
            }
            fclose($h);
            $files[$table] = Files::seal($root.'/snapshot/'.$table.'.jsonl');
            $files[$table]['path'] = $table.'.jsonl';
            $files[$table]['sql'] = 'ISOLATED FIXED SNAPSHOT';
        }
        Files::write($root.'/snapshot/manifest.json', ['files' => $files]);
        Files::write($root.'/snapshot/COMPLETE.json', ['manifest_sha256' => hash_file('sha256', $root.'/snapshot/manifest.json')]);
        $table = match ($mode) {
            'entry' => 'race_entries', 'result' => 'race_results', default => 'races'
        };
        $old = (array) DB::table($table)->orderBy('id')->first();
        if (in_array($mode, ['race', 'entry', 'result', 'swapped'], true)) {
            if ($mode === 'swapped') {
                DB::table($table)->where('id', $old['id'])->delete();
            }
            $old['id'] = 9999999;
            if ($table === 'race_entries' || $table === 'race_results') {
                $old['bike_number'] = 9;
            } elseif ($table === 'races') {
                $old['external_race_id'] = 'ARTIFICIAL_EXTRA_WITHIN_ALLOWED_PERIOD';
            }
            DB::table($table)->insert($old);
        } elseif ($mode === 'missing') {
            DB::table($table)->where('id', $old['id'])->delete();
        } elseif ($mode === 'track') {
            DB::table('racetracks')->delete();
        }
        DB::statement("VACUUM INTO '".$root."/changed.sqlite'");
        try {
            (new Snapshot)->scope(Files::sqlite($root.'/changed.sqlite'), $root.'/snapshot', $root.'/new-scope.jsonl');
            $this->fail('Changed fixed cohort accepted.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString($mode === 'track' ? 'FIXED_RACE_TRACK_METADATA_MISSING' : 'SNAPSHOT_SET_OR_PAYLOAD_MISMATCH', $e->getMessage());
            if ($mode !== 'track') {
                $counts = json_decode(explode(':', $e->getMessage(), 2)[1], true, flags: JSON_THROW_ON_ERROR)['counts'];
                $this->assertGreaterThan(0, $counts[match ($mode) {
                    'missing', 'swapped' => 'missing_ids', 'duplicate' => 'duplicate_ids', default => 'extra_ids'
                }]);
                if ($mode === 'swapped') {
                    $this->assertSame(1, $counts['extra_ids']);
                }
            }
        }
    }

    public static function snapshotChanges(): array
    {
        return [['race'], ['entry'], ['result'], ['missing'], ['swapped'], ['duplicate'], ['track']];
    }

    #[DataProvider('mixedPointers')]
    public function test_mixed_known_and_missing_pointers_reject_known_reference_drift(bool $omitted, bool $drift): void
    {
        $this->savedCases();
        [$input] = $this->input();
        $root = $this->temporary->path().'/mixed';
        Files::directory($root);
        $group = iterator_to_array(Files::rows($this->temporary->path().'/scope.jsonl'))[0];
        $r1 = $group['results'][0];
        $r2 = $group['results'][1];
        $id = $r1['race_result_import_id'];
        $group['results'][1]['race_result_import_id'] = null;
        if ($omitted) {
            unset($group['results'][1]['race_result_import_id']);
        }
        foreach (['scope' => [$group], 'export-races' => [$group['race']], 'export-entries' => $group['entries'], 'export-results' => array_map(
            fn ($r) => $r['id'] === $r1['id'] && $drift ? array_replace($r, ['race_result_import_id' => $id + 1]) : $r,
            array_replace($group['results'], [1 => $r2]))] as $name => $rows) {
            $h = Files::create($root.'/'.$name.'.jsonl');
            foreach ($rows as $row) {
                Files::append($h, $row);
            }
            fclose($h);
        }
        $db = new \PDO('sqlite:'.Files::read($input)['sqlite']['path']);
        $db->exec('UPDATE race_results SET race_result_import_id=NULL WHERE id='.$r2['id']);
        $side = Files::create($root.'/sidecar.jsonl');
        try {
            (new \ReflectionMethod(Prepare::class, 'links'))->invoke(app(Prepare::class), $db, $root, $side);
        } finally {
            fclose($side);
        }
        $this->assertSame($id, Files::select($db, 'SELECT race_result_import_id FROM race_results WHERE id=?', [$r1['id']])[0]['race_result_import_id']);
        $this->assertSame($drift ? null : $id, Files::select($db, 'SELECT race_result_import_id FROM race_results WHERE id=?', [$r2['id']])[0]['race_result_import_id']);
        $bindings = iterator_to_array(Files::rows($root.'/bindings.jsonl'));
        $this->assertCount(1, $bindings);
        $this->assertSame($drift ? 'SOURCE_ROW_DRIFT' : 'CURRENT_SOURCE_ROW_MATCHED', $bindings[0]['binding']['state']);
    }

    public static function mixedPointers(): array
    {
        return [[false, false], [true, false], [false, true], [true, true]];
    }

    #[DataProvider('logComparisons')]
    public function test_exported_disp_is_compared_semantically_without_mutating_old_json(string $mode): void
    {
        $this->savedCases();
        [$input] = $this->input();
        $db = new \PDO('sqlite:'.Files::read($input)['sqlite']['path']);
        $row = Files::select($db, 'SELECT * FROM scraping_fetch_logs ORDER BY id LIMIT 1')[0];
        $old = '{ "unexported": "kept", "disp" : "ARTIFICIAL" }';
        $db->prepare('UPDATE scraping_fetch_logs SET request_parameters=? WHERE id=?')->execute([$old, $row['id']]);
        $row['request_parameters'] = $mode === 'type' ? '{"disp":1}' : ($mode === 'disp' ? '{"disp":"OTHER"}' : '{"disp":"ARTIFICIAL"}');
        if ($mode === 'metadata') {
            $row['sha256'] = str_repeat('0', 64);
        }
        if ($mode === 'insert') {
            $row['id'] = 9999999;
            $row['request_key'] = 'ARTIFICIAL_NEW_KEY';
        }
        $h = Files::create($this->temporary->path().'/log-sidecar.jsonl');
        try {
            if (in_array($mode, ['disp', 'type', 'metadata'], true)) {
                $this->expectException(RuntimeException::class);
                $this->expectExceptionMessage($mode === 'metadata' ? 'EXISTING_ID_CONFLICT' : 'EXISTING_REQUEST_PARAMETERS_CONFLICT');
            }
            Prepare::insertMissing($db, 'scraping_fetch_logs', $row, $h);
            $saved = Files::select($db, 'SELECT * FROM scraping_fetch_logs WHERE id=?', [$row['id']])[0];
            $this->assertSame($mode === 'insert' ? $row['request_parameters'] : $old, $saved['request_parameters']);
        } finally {
            fclose($h);
        }
    }

    public static function logComparisons(): array
    {
        return [['same'], ['insert'], ['disp'], ['type'], ['metadata']];
    }

    #[DataProvider('outputFailures')]
    public function test_output_failures_keep_fixed_denominators_and_uncommitted_rows(string $stream, int $occurrence, int $saved): void
    {
        $this->savedCases();
        [$input, $sha, $groups] = $this->input();
        $writer = new class($stream, $occurrence) extends Writer
        {
            public int $afterFailure = 0;

            private int $calls = 0;

            private bool $broken = false;

            public function __construct(private string $target, private int $occurrence) {}

            public function write(string $name, array $value): void
            {
                if ($this->broken) {
                    $this->afterFailure++;
                    throw new OutputFailure('Repeated broken stream write.');
                }
                if ($name === $this->target && ++$this->calls === $this->occurrence) {
                    $this->broken = true;
                    throw new OutputFailure('ARTIFICIAL_OUTPUT_FAILURE');
                }
                parent::write($name, $value);
            }
        };
        $out = $this->temporary->path().'/partial';
        $result = (new Batch(app(SavedResultWeatherProjectionService::class), $writer))->run($input, $sha, $out);
        $this->assertSame(1, $result['exit_code']);
        $this->assertSame(count($groups), $result['races']);
        $this->assertSame(array_sum(array_map(fn ($g) => count($g['candidates']), $groups)), $result['candidates']);
        $this->assertSame($saved, $result['saved']);
        $this->assertSame($saved, $result['projected']);
        $this->assertSame(1, $result['failed']);
        $this->assertSame($result['candidates'], $result['projected'] + $result['excluded'] + $result['failed'] + $result['not_processed']);
        $this->assertSame($result['races'], array_sum($result['race_statuses']));
        $this->assertSame(0, $writer->afterFailure);
        $this->assertFileExists($out.'/FAILED.json');
        $this->assertFileDoesNotExist($out.'/COMPLETE.json');
        $this->assertFileExists($out.'/projections.jsonl');
        if ($stream !== 'projections') {
            $this->assertGreaterThan($saved, $result['projection_rows_written']);
        }
    }

    public static function outputFailures(): array
    {
        return [['projections', 1, 0], ['projections', 2, 1], ['candidate-inventory', 1, 0], ['race-coverage', 1, 0]];
    }

    #[DataProvider('evidenceControls')]
    public function test_weather_preserves_evidence_checks_without_method_semantic_evaluation(string $mode): void
    {
        $case = $this->savedCases()[0];
        $row = DB::table('race_results')->where('race_id', $case['race']['id'])->where('rank', 1)->first();
        $race = Race::findOrFail($case['race']['id']);
        $entry = RaceEntry::query()->where('race_id', $race->id)->where('bike_number', $row->bike_number)->sole();
        if ($mode === 'mark' || $mode === 'range') {
            $bytes = file_get_contents($this->temporary->path().'/raw/'.$case['fixture_file']);
            [$html] = app(CharacterEncodingConverter::class)->convertToUtf8($bytes, $case['log']['content_type']);
            $pc = app(EmbeddedJsonExtractor::class)->extract($html, 'PC0201');
            $pj = app(EmbeddedJsonExtractor::class)->extract($html, 'PJ0326');
            foreach ($pj['tyakujyunItemSubData'] as &$raw) {
                if ((int) $raw['syaban'] === $row->bike_number) {
                    if ($mode === 'mark') {
                        $raw['kimarite'] = "\u{30DE}\u{30FC}\u{30AF}";
                    } else {
                        $raw['tyaku'] = '10';
                    }
                }
            }
            unset($raw);
            $body = '<!-- ARTIFICIAL EVIDENCE BOUNDARY --><script>jsonData["PC0201"]='.trim(Files::json($pc)).';jsonData["PJ0326"]='.trim(Files::json($pj)).';</script>';
            $h = Files::create($this->temporary->path().'/raw/evidence-control.html');
            fwrite($h, $body);
            fclose($h);
            DB::table('scraping_fetch_logs')->where('id', $case['log']['id'])->update(['raw_file_path' => 'evidence-control.html', 'sha256' => hash('sha256', $body), 'response_size' => strlen($body), 'content_type' => 'text/html; charset=UTF-8']);
            DB::table('race_result_imports')->where('id', $case['import']['id'])->update(['raw_file_path' => 'evidence-control.html', 'source_hash' => hash('sha256', $body), 'raw_response_size' => strlen($body), 'converted_hash' => hash('sha256', $body)]);
            DB::table('race_results')->where('id', $row->id)->update($mode === 'mark' ? ['winning_technique' => "\u{30DE}\u{30FC}\u{30AF}"] : ['rank' => 10]);
        } else {
            $changes = match ($mode) {
                'rank' => ['rank' => 9], 'method' => ['winning_technique' => 'ARTIFICIAL_MISMATCH'],
                'finished-null', 'tied-null' => ['rank' => null, 'result_status' => $mode === 'finished-null' ? 'FINISHED' : 'TIED'],
                'nonfinished-rank' => ['rank' => 1, 'result_status' => 'DID_NOT_FINISH'], default => []
            };
            if ($changes !== []) {
                DB::table('race_results')->where('id', $row->id)->update($changes);
            }
            if ($mode === 'person') {
                $entry->external_player_id = '999999';
            } elseif ($mode === 'import') {
                DB::table('race_result_imports')->where('id', $case['import']['id'])->update(['race_id' => 9999999]);
            } elseif ($mode === 'hash') {
                DB::table('scraping_fetch_logs')->where('id', $case['log']['id'])->update(['sha256' => str_repeat('0', 64)]);
            } elseif ($mode === 'size') {
                DB::table('scraping_fetch_logs')->where('id', $case['log']['id'])->update(['response_size' => 1]);
            } elseif ($mode === 'time') {
                DB::table('race_result_imports')->where('id', $case['import']['id'])->update(['imported_at' => '2022-01-01 00:00:00']);
            }
        }
        $accepted = false;
        try {
            $projection = app(SavedResultWeatherProjectionService::class)->project($race, $entry);
            $accepted = true;
            $this->assertSame($race->id, $projection['race_id']);
        } catch (RuntimeException $e) {
            $this->assertNotSame('', $e->getMessage());
        }
        $this->assertSame($mode === 'mark', $accepted);
        $this->expectException(RuntimeException::class);
        app(Reader::class)->read($race, $entry);
    }

    public static function evidenceControls(): array
    {
        return [['mark'], ['range'], ['rank'], ['method'], ['person'], ['import'], ['hash'], ['size'], ['time'], ['finished-null'], ['tied-null'], ['nonfinished-rank']];
    }

    #[DataProvider('derivationControls')]
    public function test_offline_derivation_checks_scope_seals_and_current_code(string $mode): void
    {
        $this->savedCases();
        [$input] = $this->input();
        $m = Files::read($input);
        $root = $this->temporary->path();
        Files::directory($root.'/snapshot');
        $files = [];
        foreach (['races', 'race_entries', 'race_results'] as $table) {
            $h = Files::create($root.'/snapshot/'.$table.'.jsonl');
            foreach (DB::table($table)->orderBy('id')->get() as $row) {
                Files::append($h, (array) $row);
            }
            fclose($h);
            $files[$table] = Files::seal($root.'/snapshot/'.$table.'.jsonl');
            $files[$table]['path'] = $table.'.jsonl';
            $files[$table]['sql'] = 'ARTIFICIAL DERIVATION CONTROL';
        }
        Files::write($root.'/snapshot/manifest.json', ['files' => $files]);
        Files::write($root.'/snapshot/COMPLETE.json', ['manifest_sha256' => hash_file('sha256', $root.'/snapshot/manifest.json')]);
        $reference = Files::seal($input);
        if ($mode === 'sha') {
            $reference['sha256'] = str_repeat('0', 64);
        } elseif ($mode === 'extra') {
            $db = new \PDO('sqlite:'.$m['sqlite']['path']);
            $row = Files::select($db, 'SELECT * FROM races ORDER BY id LIMIT 1')[0];
            $row['id'] = 9999999;
            $row['external_race_id'] = 'ARTIFICIAL_EXTRA_RACE';
            $db->prepare('INSERT INTO races ('.implode(',', array_keys($row)).') VALUES ('.implode(',', array_fill(0, count($row), '?')).')')->execute(array_values($row));
            $m['sqlite'] = Files::seal($m['sqlite']['path']);
        }
        $m['raw_roots'] = ['existing' => $root.'/raw', 'supplement' => $root.'/raw'];
        $data = ['input' => $m, 'reference' => $reference, 'snapshot' => $root.'/snapshot', 'data_seals' => [$reference],
            'historical_code' => ['fixture' => 'ARTIFICIAL_DEPENDENCY_CONTROL_NOT_REAL_LEGACY_PROOF'], 'row_audit' => []];
        $legacy = new class($data) extends LegacyInput
        {
            public function __construct(private array $data) {}

            public function load(): array
            {
                Files::verify($this->data['reference']);

                return $this->data;
            }
        };
        $out = $root.'/derived';
        if ($mode === 'collision') {
            Files::directory($out);
        }
        $before = Files::seal($input);
        if (in_array($mode, ['sha', 'extra', 'collision'], true)) {
            $this->expectException(RuntimeException::class);
        }
        (new Derive($legacy, new Snapshot, new Repository))->run($out);
        if (in_array($mode, ['normal', 'code'], true)) {
            $this->assertSame($before, Files::seal($input));
            $this->assertSame(0, Store::input($out.'/manifest.json', Files::seal($out.'/manifest.json')['sha256'])['extraction']['connections']);
            if ($mode === 'code') {
                $changed = Files::read($out.'/manifest.json');
                $changed['code'][0]['sha256'] = str_repeat('0', 64);
                Files::write($root.'/changed-input.json', $changed);
                $this->expectException(RuntimeException::class);
                Store::input($root.'/changed-input.json', Files::seal($root.'/changed-input.json')['sha256']);
            }
        }
    }

    public static function derivationControls(): array
    {
        return [['normal'], ['sha'], ['extra'], ['collision'], ['code']];
    }

    public function test_output_open_failure_returns_full_counts_even_without_diagnostic_storage(): void
    {
        $this->savedCases();
        [$input, $sha, $groups] = $this->input();
        $writer = new class extends Writer
        {
            public function open(string $root): void
            {
                throw new OutputFailure('ARTIFICIAL_UNWRITABLE_OUTPUT');
            }

            public function failure(string $root, array $summary): bool
            {
                return false;
            }
        };
        $out = $this->temporary->path().'/unwritable-control';
        $s = (new Batch(app(SavedResultWeatherProjectionService::class), $writer))->run($input, $sha, $out);
        $this->assertSame(1, $s['exit_code']);
        $this->assertSame(count($groups), $s['races']);
        $this->assertSame(1, $s['failed']);
        $this->assertSame($s['candidates'] - 1, $s['not_processed']);
        $this->assertSame($s['races'], array_sum($s['race_statuses']));
        $this->assertSame(0, $s['saved']);
        $this->assertFalse($s['failure_diagnostic_saved']);
        $this->assertFileDoesNotExist($out.'/COMPLETE.json');
    }

    public function test_a_only_race_keeps_original_references_despite_current_correction(): void
    {
        $this->savedCases();
        [$input] = $this->input();
        $root = $this->temporary->path().'/a-only';
        Files::directory($root);
        $group = iterator_to_array(Files::rows($this->temporary->path().'/scope.jsonl'))[0];
        foreach (['scope' => [$group], 'export-races' => [$group['race']], 'export-entries' => $group['entries'],
            'export-results' => array_map(fn ($row) => array_replace($row, ['race_result_import_id' => 999999]), $group['results'])] as $name => $rows) {
            $h = Files::create($root.'/'.$name.'.jsonl');
            foreach ($rows as $row) {
                Files::append($h, $row);
            }
            fclose($h);
        }
        $db = new \PDO('sqlite:'.Files::read($input)['sqlite']['path']);
        $before = Files::select($db, 'SELECT * FROM race_results ORDER BY id');
        $h = Files::create($root.'/sidecar.jsonl');
        try {
            (new \ReflectionMethod(Prepare::class, 'links'))->invoke(app(Prepare::class), $db, $root, $h);
        } finally {
            fclose($h);
        }
        $this->assertSame($before, Files::select($db, 'SELECT * FROM race_results ORDER BY id'));
        $this->assertSame([], iterator_to_array(Files::rows($root.'/bindings.jsonl')));
    }
}
