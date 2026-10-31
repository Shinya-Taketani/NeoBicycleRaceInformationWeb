<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Keirin\Scraping\Parsers\EmbeddedJsonExtractor;
use App\Domain\Keirin\Scraping\Support\CharacterEncodingConverter;
use App\Domain\Keirin\Statistics\SavedWeather\Batch;
use App\Domain\Keirin\Statistics\SavedWeather\Files;
use App\Domain\Keirin\Statistics\SavedWeather\MetadataExporter;
use App\Domain\Keirin\Statistics\SavedWeather\Prepare;
use App\Domain\Keirin\Statistics\SavedWeather\Repository;
use App\Domain\Keirin\Statistics\SavedWeather\Store;
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
        $path = getenv('STAT38_RESULT_FIXTURE');
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
            ['multiple', 'AMBIGUOUS_CURRENT_IMPORT'], ['missing-log', 'FETCH_LOG_NOT_EXTRACTED']];
    }

    #[DataProvider('corruptions')]
    public function test_sealed_identity_raw_corruption_stops_without_anchor_fallback(string $mode): void
    {
        $cases = $this->savedCases();
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
        return [['person'], ['date'], ['raw']];
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
}
