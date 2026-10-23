<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Keirin\Scraping\Parsers\EmbeddedJsonExtractor;
use App\Domain\Keirin\Scraping\Parsers\RaceDetailParser;
use App\Domain\Keirin\Scraping\Services\RaceRosterSnapshotService;
use App\Domain\Keirin\Scraping\Support\CharacterEncodingConverter;
use App\Models\Race;
use App\Models\RaceEntry;
use App\Models\RaceRosterSnapshot;
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

final class RosterSnapshotMvpTest extends TestCase
{
    use RefreshDatabase;

    private ?CompositionResultTemporaryDirectory $temporary = null;

    public function createApplication(): Application
    {
        $this->temporary = CompositionResultTemporaryDirectory::create();
        $root = $this->temporary->path();
        try {
            foreach (['bootstrap', 'bootstrap/cache', 'runtime', 'raw'] as $directory) {
                if (! mkdir($root.'/'.$directory, 0700)) {
                    throw new RuntimeException('Cannot create isolated roster test directory.');
                }
            }
            $this->writeNew($root.'/.env', "# Isolated roster snapshot test. No application secrets.\n");
            $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
            $this->traitsUsedByTest = class_uses_recursive(self::class);
            $app->useEnvironmentPath($root);
            $app->useBootstrapPath($root.'/bootstrap');
            $app->useStoragePath($root.'/runtime');
            $app->make(Kernel::class)->bootstrap();
            $app['config']->set(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
                'database.connections.sqlite.url' => null, 'logging.default' => 'null', 'keirin.raw_disk' => 'roster_test',
                'filesystems.disks.roster_test' => ['driver' => 'local', 'root' => $root.'/raw', 'throw' => true]]);
            $app->bind(Factory::class, fn () => throw new RuntimeException('HTTP forbidden in roster snapshot tests.'));

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

    public function test_real_raw_versions_save_complete_rosters_and_compare_actual_captures(): void
    {
        $races = $entries = $snapshots = $pairs = [];
        $occurrences = 0;
        foreach ($this->cases() as $case) {
            [$race, $log, $html] = $this->seedCapture($case);
            $page = app(RaceDetailParser::class)->parse($html);
            $before = $this->oldState();
            $first = app(RaceRosterSnapshotService::class)->import($race->id, $log->id, false);
            $again = app(RaceRosterSnapshotService::class)->import($race->id, $log->id, false);
            $this->assertSame(1, $first['planned_new_snapshots']);
            $this->assertSame(0, $again['planned_new_snapshots']);
            $this->assertSame($first['snapshot_id'], $again['snapshot_id']);
            $this->assertSame($before, $this->oldState());
            $row = RaceRosterSnapshot::query()->findOrFail($first['snapshot_id']);
            $this->assertSame(count($page->entries), $row->entrant_count);
            $this->assertSame($log->raw_file_path, $row->raw_file_path);
            $this->assertSame($case['original']['sha256'], $row->raw_sha256);
            $this->assertSame($case['original']['bytes'], $row->raw_bytes);
            $this->assertSame($log->fetched_at->format('U.u'), $row->fetched_at->format('U.u'));
            // The original sealed bundle lacks log.created_at; never invent historical evidence.
            $this->assertNull($row->fetch_log_saved_at);
            $this->assertSame('AT_OR_AFTER_SAME_RAW_START', $row->timing_status);
            $this->assertSame('RETROSPECTIVE_ONLY', $row->use_class);
            $this->assertFalse($row->historical_as_of_available);
            $this->assertSame('NOT_AUTHORIZED', $row->prediction_use);
            $this->assertSame('UNKNOWN', $row->official_publication_status);
            $this->assertNull($row->official_publication_at);
            foreach ($page->entries as $index => $entry) {
                $stored = $row->entries[$index];
                foreach (['bike_number' => 'bikeNumber', 'frame_number' => 'frameNumber', 'external_player_id' => 'externalPlayerId',
                    'player_name' => 'playerName', 'grade' => 'grade', 'riding_style' => 'ridingStyle', 'race_score' => 'raceScore'] as $key => $property) {
                    $this->assertSame($entry->$property, $stored[$key]);
                }
                $this->assertSame('string', $stored['raw_fields']['sensyuRegistNo']['raw_type']);
                $this->assertSame($entry->externalPlayerId, json_decode($stored['raw_fields']['sensyuRegistNo']['raw_json'], true, flags: JSON_THROW_ON_ERROR));
                $entries[$race->id.':'.$entry->externalPlayerId] = true;
                $occurrences++;
            }
            $races[$race->id] = $race->race_date->format('Y');
            $snapshots[$race->id][] = $row;
        }
        foreach ($snapshots as $raceId => $versions) {
            if (count($versions) === 2) {
                $comparison = app(RaceRosterSnapshotService::class)->compare($raceId, $versions[1]->id, $versions[0]->id,
                    new DateTimeImmutable($versions[0]->race_date->format('Y-m-d').'T00:00:00+09:00'));
                $this->assertSame('NOT_ESTABLISHED', $comparison['status']);
                $this->assertSame(count($versions[0]->entries), count($comparison['difference']['frame_comparison_unknown_registrations']));
                $this->assertSame($comparison['difference']['before'], $comparison['difference']['after']);
                $this->assertNull($comparison['official_entry_change_type']);
                foreach (['added_registrations', 'removed_registrations', 'bike_changes', 'frame_changes'] as $key) {
                    $this->assertSame([], $comparison['difference'][$key]);
                }
                $this->assertTrue($comparison['before_capture']['input_as_of_before_same_raw_start']);
                $this->assertNull($comparison['before_capture']['acquired_and_logged_before_input_as_of']);
                $pairs[] = ['race_id' => $raceId, 'comparison' => $comparison];
            }
        }
        $this->assertCount(6, $races);
        $this->assertCount(39, $entries);
        $this->assertSame(51, $occurrences);
        $this->assertCount(2, $pairs);
        $this->assertSame(8, RaceRosterSnapshot::query()->count());
        $directory = getenv('ROSTER_TEST_REPORT_DIRECTORY');
        if (is_string($directory) && $directory !== '') {
            if (realpath($directory) !== $directory || ! is_dir($directory)) {
                throw new RuntimeException('Invalid roster report directory.');
            }
            $this->writeNew($directory.'/roster-real-raw-acceptance.json', json_encode([
                'races' => count($races), 'entries' => count($entries), 'capture_versions' => 8, 'entry_observations' => $occurrences,
                'comparable_races' => count($pairs), 'comparable_versions' => 4, 'observed_identity_or_bike_changed_pairs' => 0,
                'frame_changes' => 'NOT_ESTABLISHED_ALL_FRAMES_NULL',
                'fetch_log_saved_at_evidence' => 'NOT_INCLUDED_IN_ORIGINAL_FIXTURE', 'pairs' => $pairs,
                'snapshots' => RaceRosterSnapshot::query()->orderBy('scraping_fetch_log_id')->get()->toArray(),
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n");
        }
    }

    public function test_old_raw_roster_survives_different_current_people_and_entrant_count(): void
    {
        [$race, $log] = $this->seedCapture($this->cases()[0]);
        RaceEntry::query()->where('race_id', $race->id)->orderBy('id')->firstOrFail()->update(['external_player_id' => '000001']);
        $race->update(['entrant_count' => 9]);
        $before = $this->oldState();
        $out = app(RaceRosterSnapshotService::class)->import($race->id, $log->id, false);
        $stored = RaceRosterSnapshot::query()->findOrFail($out['snapshot_id']);
        $this->assertSame(5, $stored->entrant_count);
        $this->assertNotContains('000001', array_column($stored->entries, 'external_player_id'));
        $this->assertSame($before, $this->oldState());
    }

    public function test_identical_raw_in_a_distinct_isolated_fetch_keeps_both_versions(): void
    {
        [$race, $log] = $this->seedCapture($this->cases()[0]);
        $first = app(RaceRosterSnapshotService::class)->import($race->id, $log->id, false);
        // This duplicate fetch is an artificial persistence case, not an additional historical capture.
        $copy = $log->replicate();
        $copy->save();
        $second = app(RaceRosterSnapshotService::class)->import($race->id, $copy->id, false);
        $this->assertNotSame($first['snapshot_id'], $second['snapshot_id']);
        $this->assertSame(2, RaceRosterSnapshot::query()->count());
        $this->assertSame(1, RaceRosterSnapshot::query()->distinct()->count('raw_sha256'));
        $this->assertSame(0, app(RaceRosterSnapshotService::class)->import($race->id, $copy->id, false)['planned_new_snapshots']);
    }

    public function test_changed_metadata_of_existing_capture_cannot_overwrite_old_snapshot(): void
    {
        [$race, $log] = $this->seedCapture($this->cases()[0]);
        app(RaceRosterSnapshotService::class)->import($race->id, $log->id, false);
        $before = RaceRosterSnapshot::query()->get()->toJson();
        $log->update(['parser_version' => 'isolated-conflicting-source-version']);
        $this->expectExceptionMessage('Stored roster snapshot conflict');
        try {
            app(RaceRosterSnapshotService::class)->import($race->id, $log->id, false);
        } finally {
            $this->assertSame($before, RaceRosterSnapshot::query()->get()->toJson());
        }
    }

    public function test_isolated_noncontiguous_bikes_and_leading_zero_registration_are_preserved(): void
    {
        [$race, $log, $html] = $this->seedCapture($this->cases()[0]);
        [$pc, $pj] = $this->documents($html);
        $bikes = [1, 3, 5, 7, 9];
        foreach ($pj['sensyuTypeInfo'] as $i => &$entry) {
            $entry['syaban'] = (string) $bikes[$i];
            if ($i === 0) {
                $entry['sensyuRegistNo'] = '001234';
            }
            $pc['C0201data']['C0201racedtl']['C0201sensyu'][$i]['carNum'] = (string) $bikes[$i];
            $pc['C0201data']['C0201racedtl']['C0201sensyu'][$i]['numPlayer'] = $entry['sensyuRegistNo'];
        }
        unset($entry);
        $control = $this->control($log, $pc, $pj);
        $out = app(RaceRosterSnapshotService::class)->import($race->id, $control->id, false);
        $stored = RaceRosterSnapshot::query()->findOrFail($out['snapshot_id'])->entries;
        $this->assertSame($bikes, array_column($stored, 'bike_number'));
        $this->assertSame('001234', $stored[0]['external_player_id']);
    }

    public function test_isolated_optional_fields_keep_missing_null_empty_string_object_and_list_distinct(): void
    {
        [$race, $log, $html] = $this->seedCapture($this->cases()[0]);
        [$pc, $original] = $this->documents($html);
        foreach (['MISSING' => null, 'NULL' => null, 'EMPTY' => '', 'OBJECT' => (object) [], 'LIST' => []] as $kind => $value) {
            $pj = $original;
            if ($kind === 'MISSING') {
                unset($pj['sensyuTypeInfo'][0]['sensyuName']);
            } else {
                $pj['sensyuTypeInfo'][0]['sensyuName'] = $value;
            }
            $control = $this->control($log, $pc, $pj);
            $out = app(RaceRosterSnapshotService::class)->import($race->id, $control->id, false);
            $field = RaceRosterSnapshot::query()->findOrFail($out['snapshot_id'])->entries[0]['raw_fields']['sensyuName'];
            $this->assertSame($kind !== 'MISSING', $field['present']);
            $this->assertSame($kind === 'MISSING' ? 'MISSING' : get_debug_type($value), $field['raw_type']);
            $this->assertSame($kind === 'MISSING' ? null : json_encode($value, JSON_THROW_ON_ERROR), $field['raw_json']);
        }
    }

    public function test_isolated_summary_detail_mismatch_duplicates_and_wrong_race_are_rejected(): void
    {
        [$race, $log, $html] = $this->seedCapture($this->cases()[0]);
        [$pc, $pj] = $this->documents($html);
        foreach (['DATE', 'TRACK', 'NUMBER', 'SUMMARY', 'DUPLICATE_BIKE', 'DUPLICATE_REGISTRATION'] as $kind) {
            $a = $pc;
            $b = $pj;
            if ($kind === 'DUPLICATE_REGISTRATION') {
                $a['C0201data']['C0201racedtl']['C0201sensyu'][1]['numPlayer'] = $b['sensyuTypeInfo'][0]['sensyuRegistNo'];
                $b['sensyuTypeInfo'][1]['sensyuRegistNo'] = $b['sensyuTypeInfo'][0]['sensyuRegistNo'];
            } else {
                match ($kind) {
                    'DATE' => $a['C0201data']['selKaisai'] = '20250102',
                    'TRACK' => $a['C0201data']['selKjyoCd'] = '99',
                    'NUMBER' => $a['C0201data']['selRaceNo'] = '12',
                    'SUMMARY' => $a['C0201data']['C0201racedtl']['C0201sensyu'][0]['numPlayer'] = '000001',
                    'DUPLICATE_BIKE' => $b['sensyuTypeInfo'][1] = $b['sensyuTypeInfo'][0],
                };
            }
            $control = $this->control($log, $a, $b);
            try {
                app(RaceRosterSnapshotService::class)->import($race->id, $control->id, false);
                $this->fail('Invalid isolated roster accepted: '.$kind);
            } catch (RuntimeException|\InvalidArgumentException) {
                $this->assertSame(0, RaceRosterSnapshot::query()->count());
            }
        }
    }

    public function test_raw_missing_sha_size_and_source_mismatch_fail_without_writes(): void
    {
        [$race, $log] = $this->seedCapture($this->cases()[0]);
        foreach (['raw_file_path' => 'never-created.html', 'sha256' => str_repeat('0', 64), 'response_size' => 1,
            'http_status' => 302, 'request_url' => 'https://invalid.example/', 'parser_version' => ''] as $key => $bad) {
            $before = $log->$key;
            $log->update([$key => $bad]);
            try {
                app(RaceRosterSnapshotService::class)->import($race->id, $log->id, false);
                $this->fail('Invalid roster source accepted: '.$key);
            } catch (RuntimeException) {
                $this->assertSame(0, RaceRosterSnapshot::query()->count());
            } finally {
                $log->update([$key => $before]);
            }
        }
    }

    public function test_dry_run_and_plan_perform_zero_database_writes(): void
    {
        [$race, $log] = $this->seedCapture($this->cases()[0]);
        $writes = [];
        DB::listen(function ($query) use (&$writes): void {
            if (preg_match('/^\s*(insert|update|delete|create|alter|drop|replace)\b/i', $query->sql)) {
                $writes[] = true;
            }
        });
        $options = ['--race-id' => (string) $race->id, '--fetch-log-id' => [(string) $log->id]];
        $this->artisan('keirin:rosters:import-snapshots', $options)->assertExitCode(0);
        $this->artisan('keirin:rosters:import-snapshots', $options + ['--execute' => true, '--dry-run' => true])->assertExitCode(0);
        $this->assertSame(1, app(RaceRosterSnapshotService::class)->import($race->id, $log->id, true)['planned_new_snapshots']);
        $this->assertSame([], $writes);
        $this->assertSame(0, RaceRosterSnapshot::query()->count());
    }

    public function test_explicit_import_and_read_only_compare_commands(): void
    {
        $ids = [];
        foreach (array_slice($this->cases(), 0, 2) as $case) {
            [$race, $log] = $this->seedCapture($case);
            $this->artisan('keirin:rosters:import-snapshots', ['--race-id' => (string) $race->id,
                '--fetch-log-id' => [(string) $log->id], '--execute' => true])->assertExitCode(0);
            $ids[] = RaceRosterSnapshot::query()->where('scraping_fetch_log_id', $log->id)->sole()->id;
        }
        $before = RaceRosterSnapshot::query()->get()->toJson();
        $writes = [];
        DB::listen(function ($query) use (&$writes): void {
            if (preg_match('/^\s*(insert|update|delete|create|alter|drop|replace)\b/i', $query->sql)) {
                $writes[] = true;
            }
        });
        $options = ['--race-id' => (string) $race->id, '--first-snapshot-id' => (string) $ids[0],
            '--second-snapshot-id' => (string) $ids[1], '--input-as-of' => '2024-12-31T15:00:00Z'];
        $this->artisan('keirin:statistics:compare-stat46-rosters', $options)->assertExitCode(0);
        $this->artisan('keirin:statistics:compare-stat46-rosters', $options + ['--execute' => true])
            ->expectsOutputToContain('NOT_ESTABLISHED')->assertExitCode(0);
        $this->artisan('keirin:statistics:compare-stat46-rosters', array_replace($options,
            ['--input-as-of' => '2025-02-30T00:00:00+09:00', '--execute' => true]))->assertExitCode(1);
        $this->assertSame([], $writes);
        $this->assertSame($before, RaceRosterSnapshot::query()->get()->toJson());
        $same = app(RaceRosterSnapshotService::class)->compare($race->id, $ids[0], $ids[0], null);
        $this->assertSame('INSUFFICIENT_SNAPSHOTS', $same['status']);
    }

    public function test_source_drift_before_publication_rolls_back_snapshot(): void
    {
        [$race, $log] = $this->seedCapture($this->cases()[0]);
        $changed = false;
        DB::listen(function ($query) use ($log, &$changed): void {
            if (! $changed && str_starts_with($query->sql, 'insert into "race_roster_snapshots"')) {
                $changed = true;
                $log->update(['sha256' => str_repeat('0', 64)]);
            }
        });
        $this->expectExceptionMessage('metadata changed before publication');
        try {
            app(RaceRosterSnapshotService::class)->import($race->id, $log->id, false);
        } finally {
            $this->assertTrue($changed);
            $this->assertSame(0, RaceRosterSnapshot::query()->count());
        }
    }

    public function test_one_byte_drift_in_artificial_copy_prevents_stored_comparison(): void
    {
        [$race, $log] = $this->seedCapture($this->cases()[0]);
        $out = app(RaceRosterSnapshotService::class)->import($race->id, $log->id, false);
        $stream = fopen($this->temporary->path().'/raw/'.$log->raw_file_path, 'r+b');
        fwrite($stream, '!');
        fclose($stream);
        $this->expectException(RuntimeException::class);
        app(RaceRosterSnapshotService::class)->compare($race->id, $out['snapshot_id'], $out['snapshot_id'], null);
    }

    public function test_append_only_constraints_and_restrict_foreign_keys(): void
    {
        [$race, $log] = $this->seedCapture($this->cases()[0]);
        $out = app(RaceRosterSnapshotService::class)->import($race->id, $log->id, false);
        $this->assertCount(2, Schema::getForeignKeys('race_roster_snapshots'));
        foreach (Schema::getForeignKeys('race_roster_snapshots') as $fk) {
            $this->assertSame('restrict', $fk['on_delete']);
        }
        foreach (['UPDATE race_roster_snapshots SET entrant_count=1', 'DELETE FROM race_roster_snapshots',
            'DELETE FROM scraping_fetch_logs WHERE id='.$log->id] as $sql) {
            try {
                DB::statement($sql);
                $this->fail('Immutable roster or referenced source mutation accepted.');
            } catch (QueryException) {
                $this->assertSame(1, RaceRosterSnapshot::query()->count());
            }
        }
        $row = RaceRosterSnapshot::query()->findOrFail($out['snapshot_id']);
        $this->expectExceptionMessage('append-only');
        $row->update(['entrant_count' => 1]);
    }

    public function test_populated_migration_cannot_be_removed(): void
    {
        [$race, $log] = $this->seedCapture($this->cases()[0]);
        app(RaceRosterSnapshotService::class)->import($race->id, $log->id, false);
        $migration = require database_path('migrations/2026_10_23_000016_create_race_roster_snapshots.php');
        $this->expectExceptionMessage('Cannot remove populated');
        $migration->down();
    }

    public function test_2026_target_is_rejected_before_reading_any_raw(): void
    {
        [$race, $log] = $this->seedCapture($this->cases()[0]);
        $race->update(['race_date' => '2026-01-01']); // Rejection-only artificial identity; no holdout data.
        $log->update(['raw_file_path' => 'never-created.html']);
        $this->expectException(ModelNotFoundException::class);
        app(RaceRosterSnapshotService::class)->import($race->id, $log->id, false);
    }

    private function cases(): array
    {
        $path = getenv('STAT38_TEST_FIXTURE') ?: '/home/shinya/neo-keirin-artifacts/stat38-distance-laps-mvp-01-20261021-01/actual-fixture.json';
        $cases = [];
        foreach ([$path => '7ec6fae7e6ff5f8137754986c8f9e8d10bd766b9c65f61aa1252944494ba04dc',
            dirname($path).'/history-fixture.json' => '0344d20e9db4918a6a1a8cc0f691cdea724c22fc1b90c9ce2520ce12a02379ca'] as $file => $sha) {
            if (! is_file($file) || hash_file('sha256', $file) !== $sha) {
                throw new RuntimeException('Required real PJ0315 fixture missing or seal mismatch.');
            }
            foreach (json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR)['cases'] as $case) {
                $case['fixture_root'] = dirname($file);
                $cases[] = $case;
            }
        }

        return $cases;
    }

    private function seedCapture(array $case): array
    {
        $file = $case['fixture_root'].'/'.$case['fixture_file'];
        if (filesize($file) !== $case['original']['bytes'] || hash_file('sha256', $file) !== $case['original']['sha256']) {
            throw new RuntimeException('Actual PJ0315 fixture seal drift.');
        }
        $bytes = file_get_contents($file);
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
        $log = new ScrapingFetchLog;
        $log->timestamps = false;
        $log->forceFill(['id' => $case['log']['id'], ...$attrs, 'created_at' => null, 'updated_at' => null])->save();
        $relative = $log->raw_file_path;
        if (! is_string($relative) || str_starts_with($relative, '/') || str_contains($relative, '..') || str_contains($relative, '\\')) {
            throw new RuntimeException('Invalid real fixture relative path.');
        }
        $path = $this->temporary->path().'/raw/'.$relative;
        if (! is_dir(dirname($path)) && ! mkdir(dirname($path), 0700, true)) {
            throw new RuntimeException('Cannot create isolated Raw subdirectory.');
        }
        $this->writeNew($path, $bytes);

        return [$race, $log, $html];
    }

    private function documents(string $html): array
    {
        $json = app(EmbeddedJsonExtractor::class);

        return [$json->extract($html, 'PC0201'), $json->extract($html, 'PJ0315')];
    }

    private function control(ScrapingFetchLog $original, array $pc, array $pj): ScrapingFetchLog
    {
        $html = '<!-- ISOLATED ARTIFICIAL BOUNDARY CASE, NOT HISTORICAL EVIDENCE --><script>jsonData["PC0201"] = '
            .json_encode($pc, JSON_THROW_ON_ERROR).';jsonData["PJ0315"] = '.json_encode($pj, JSON_THROW_ON_ERROR).';</script>';
        $log = $original->replicate();
        $log->raw_file_path = 'control-'.bin2hex(random_bytes(8)).'.html';
        $log->sha256 = hash('sha256', $html);
        $log->response_size = strlen($html);
        $log->content_type = 'text/html; charset=UTF-8';
        $log->save();
        $this->writeNew($this->temporary->path().'/raw/'.$log->raw_file_path, $html);

        return $log;
    }

    private function writeNew(string $path, string $bytes): void
    {
        $stream = fopen($path, 'xb');
        if ($stream === false) {
            throw new RuntimeException('Cannot exclusively create isolated roster fixture.');
        }
        try {
            if (fwrite($stream, $bytes) !== strlen($bytes) || ! fflush($stream)) {
                throw new RuntimeException('Cannot write isolated roster fixture.');
            }
        } finally {
            fclose($stream);
        }
    }

    private function oldState(): array
    {
        return ['races' => DB::table('races')->orderBy('id')->get()->toJson(),
            'entries' => DB::table('race_entries')->orderBy('id')->get()->toJson(),
            'results' => DB::table('race_results')->count(), 'tactics' => DB::table('race_tactic_observations')->count(),
            'distance' => DB::table('race_distance_observations')->count(), 'features' => DB::table('statistic_feature_results')->count()];
    }
}
