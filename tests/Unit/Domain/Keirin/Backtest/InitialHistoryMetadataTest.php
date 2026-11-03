<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Backtest;

use App\Domain\Keirin\Backtest\ChronologicalValidation\Json;
use App\Domain\Keirin\Backtest\InitialHistoryMetadata\Evidence;
use App\Domain\Keirin\Backtest\InitialHistoryMetadata\Exporter;
use App\Domain\Keirin\Backtest\InitialHistoryMetadata\MetadataSource;
use App\Domain\Keirin\Backtest\InitialHistoryMetadata\Parameters;
use App\Domain\Keirin\Backtest\InitialHistoryMetadata\Planner;
use App\Domain\Keirin\Backtest\InitialHistoryMetadata\PostgresSource;
use App\Domain\Keirin\Backtest\InitialHistoryMetadata\Queries;
use App\Domain\Keirin\Backtest\InitialHistoryMetadata\Reconciliation;
use App\Domain\Keirin\Backtest\InitialHistoryMetadata\Scope;
use App\Domain\Keirin\Backtest\InitialHistoryMetadata\Verifier;
use App\Domain\Keirin\Backtest\InitialHistoryMetadata\Writer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\InitialHistoryMetadataFixture as F;

final class InitialHistoryMetadataTest extends TestCase
{
    public function test_plan_preserves_all_fixed_keys_and_no_private_request_values(): void
    {
        $f = new F;
        $m = Json::read($f->plan());
        $this->assertSame(['CONTEXT' => 4, 'ENTRY' => 20, 'FETCH' => 9, 'IMPORT' => 4, 'RESULT' => 10], $m['counts']);
        foreach (Evidence::SCOPE_FILES as $name) {
            $this->assertStringNotContainsString('ARTIFICIAL_PRIVATE_NEVER_OUTPUT', file_get_contents($f->root.'/plan/'.$name));
        }
        $this->assertFileExists($f->root.'/plan/COMPLETE.json');
        $this->assertSame('000101', iterator_to_array(Evidence::rows($f->root.'/plan/entries.jsonl'))[0]['expected']['external_player_id']);
    }

    public function test_full_artificial_export_independent_verify_and_known_gaps_are_complete(): void
    {
        $f = new F;
        $scope = $f->plan();
        $source = $f->source();
        (new Exporter($source))->export($scope, $f->root.'/export');
        $this->assertTrue($source->disconnected);
        $s = Json::read($f->root.'/export/summary.json');
        foreach ($s['fixed'] as $type => $counts) {
            $this->assertSame($s['fixed_counts'][$type], $counts['MATCH']);
            $this->assertSame(0, $counts['CHANGED']);
        }
        $this->assertSame(1, $s['reasons']['CONTEXT']['NO_RESULT_FETCH_EVIDENCE']);
        $this->assertSame(1, $s['reasons']['CONTEXT']['KNOWN_CANCELLED_RACE']);
        $this->assertSame(1, $s['reasons']['FETCH']['KNOWN_FETCH_FAILURE']);
        $this->assertSame(2, $s['reasons']['IMPORT']['NOT_REFERENCED_BY_FIXED_CURRENT_RESULTS']);
        $this->assertSame(10, $s['timing']['RESULT']['RESULT_VERSION_PROCESSING_TIME']);
        $this->assertSame(8, $s['timing']['FETCH']['RAW_STORED_BEFORE_LOG_RECORD']);
        $this->assertSame(4, $s['timing']['IMPORT']['IMPORT_STATUS_RECORD_TIME']);
        $this->assertSame([['classification' => 'APPLICATION_PROCESSING_TIME_ALL_FIXED_RESULT_ROWS', 'versions' => 2],
            ['classification' => 'UNESTABLISHED_NO_FIXED_RESULT_ROWS', 'versions' => 2]], $s['version_timing']);
        $before = Json::seal($f->root.'/export/manifest.json');
        $v = (new Verifier)->verify($f->root.'/export', $f->root.'/verify');
        $this->assertSame('COMPLETE_METADATA_ONLY', $v['status']);
        $this->assertSame($before, Json::seal($f->root.'/export/manifest.json'));
        $this->assertSame(0, Json::read($f->root.'/verify/verification.json')['business_connections']);
        $this->assertFalse($s['meanings']['historical_as_of_available']);
        $this->assertSame('NOT_RUN', $s['history_event_assignment']);
    }

    public function test_missing_changed_import_and_additional_keys_are_distinct_and_verified(): void
    {
        $f = new F;
        $source = $f->source(function ($type, $scope, $current) {
            if ($type === 'ADDITIONAL') {
                return [
                    ['record_type' => 'EXTRA_ENTRY', 'current' => ['id' => 99, 'race_id' => 3, 'bike_number' => 6, 'player_id' => 999, 'external_player_id' => '000999']],
                    ['record_type' => 'EXTRA_RESULT', 'current' => ['id' => 999, 'race_id' => 3, 'bike_number' => 1, 'race_result_import_id' => 999]],
                ];
            }
            if ($type === 'ENTRY' && $scope['key'] === '11') {
                return null;
            }
            if ($type === 'RESULT' && $scope['key'] === '1:1') {
                return [...$current, 'race_result_import_id' => 999];
            }

            return $current;
        });
        (new Exporter($source))->export($f->plan(), $f->root.'/export');
        $s = Json::read($f->root.'/export/summary.json');
        $this->assertSame(1, $s['fixed']['ENTRY']['NOT_FOUND_IN_FIXED_PERIOD_SOURCE']);
        $this->assertSame(1, $s['fixed']['RESULT']['CHANGED']);
        $this->assertSame(1, $s['additional']['EXTRA_ENTRY']);
        $this->assertSame(1, $s['additional']['EXTRA_RESULT']);
        $this->assertSame(9, $s['timing']['RESULT']['RESULT_VERSION_PROCESSING_TIME']);
        $this->assertSame(1, $s['reasons']['RESULT']['RESULT_IMPORT_REPLACED']);
        (new Verifier)->verify($f->root.'/export', $f->root.'/verify');
        $this->assertFileExists($f->root.'/verify/COMPLETE.json');
    }

    public function test_scope_rejects_corrupt_seal_before_source_capture(): void
    {
        $f = new F;
        $scope = $f->plan();
        file_put_contents($f->root.'/plan/races.jsonl', "{}\n", FILE_APPEND);
        $source = $this->createMock(MetadataSource::class);
        $source->expects($this->never())->method('capture');
        try {
            (new Exporter($source))->export($scope, $f->root.'/export');
            $this->fail('Corrupt scope accepted.');
        } catch (RuntimeException) {
            $this->assertFileExists($f->root.'/export/FAILED.json');
            $this->assertFileDoesNotExist($f->root.'/export/COMPLETE.json');
        }
    }

    public function test_incomplete_source_audit_does_not_block_independent_tests_but_plan_fails(): void
    {
        $f = new F;
        $root = $f->audit();
        file_put_contents($root.'/metadata-summary.json', '{}');
        $this->expectException(RuntimeException::class);
        (new Planner)->plan($root, $f->root.'/rejected');
    }

    public static function badScopes(): array
    {
        return [['date'], ['binding'], ['duplicate']];
    }

    #[DataProvider('badScopes')]
    public function test_out_of_period_binding_and_duplicate_keys_are_rejected(string $fault): void
    {
        $f = new F;
        $m = $f->plan();
        [$manifest, $db] = Scope::load($m, $f->root.'/test-index.sqlite');
        $row = iterator_to_array(Scope::rows($db, 'ENTRY'))[0];
        $this->expectException(RuntimeException::class);
        if ($fault === 'date') {
            $row['race_date'] = '2026-01-01';
            $row['key'] = '999';
            $row['expected']['id'] = 999;
            Scope::add($db, 'ENTRY', $row);
        } elseif ($fault === 'binding') {
            $db->exec("UPDATE scope SET rid=999 WHERE type='ENTRY' AND k='11'");
            Scope::validate($db);
        } else {
            Scope::add($db, 'ENTRY', $row);
        }
    }

    public static function badGuards(): array
    {
        return [['database'], ['host'], ['session_read_only'], ['transaction_read_only'], ['statement_timeout'], ['lock_timeout'], ['isolation'], ['snapshot_read_only'], ['snapshot']];
    }

    #[DataProvider('badGuards')]
    public function test_connection_guard_rejects_wrong_endpoint_readonly_timeout_and_snapshot(string $field): void
    {
        [$pre, $snapshot] = F::connection();
        if (array_key_exists($field, $pre)) {
            $pre[$field] = 'WRONG';
        } else {
            $snapshot[$field] = null;
        }
        $this->expectException(RuntimeException::class);
        PostgresSource::guard($pre, $snapshot);
    }

    public function test_query_boundaries_are_left_joins_fixed_ids_and_race_period_not_acquisition_period(): void
    {
        foreach (Queries::all() as $sql) {
            $this->assertStringContainsString('LEFT JOIN public.races r ON r.id=s.race_id', $sql);
            $this->assertStringContainsString("r.race_date >= DATE '2022-01-01'", $sql);
            $this->assertStringContainsString("r.race_date < DATE '2026-01-01'", $sql);
            $this->assertStringNotContainsString(' WHERE ', $sql);
            $this->assertStringNotContainsString('players', $sql);
            $this->assertDoesNotMatchRegularExpression('/FOR UPDATE|INSERT|DELETE|CREATE TEMP|UPDATE public|request_url/', $sql);
        }
        $this->assertStringContainsString('m.day_kind AS meeting_day_kind', Queries::all()['CONTEXT']);
        $this->assertStringNotContainsString('JOIN public.race_results', Queries::all()['IMPORT']);
        $this->assertStringNotContainsString('x.rank', Queries::all()['EXTRA_RESULT']);
        $this->assertStringNotContainsString('x.winning_technique', Queries::all()['EXTRA_RESULT']);
    }

    public function test_parameter_fingerprints_preserve_types_null_missing_and_object_order_without_values(): void
    {
        $a = Parameters::fingerprint((object) ['disp' => 'PJ0315', 'encp' => 'PRIVATE']);
        $b = Parameters::fingerprint((object) ['encp' => 'PRIVATE', 'disp' => 'PJ0315']);
        $this->assertSame($a, $b);
        $this->assertStringNotContainsString('PRIVATE', Json::encode($a));
        $variants = [];
        foreach ([null, '', 0, false, [], (object) []] as $value) {
            $variants[] = Json::hash(Parameters::fingerprint((object) ['encp' => $value]));
        }
        $variants[] = Json::hash(Parameters::fingerprint((object) []));
        $this->assertCount(7, array_unique($variants));
    }

    public function test_reimport_old_created_at_and_equivalent_offsets_are_not_drift(): void
    {
        $f = new F;
        $source = $f->source(fn ($type, $scope, $current) => $type === 'ADDITIONAL' ? [] : ($type === 'FETCH' ? [...$current, 'fetched_at' => '2026-06-30T15:00:00Z'] : $current));
        (new Exporter($source))->export($f->plan(), $f->root.'/export');
        $s = Json::read($f->root.'/export/summary.json');
        $this->assertSame(9, $s['fixed']['FETCH']['MATCH']);
        $this->assertSame(10, $s['timing']['RESULT']['RESULT_VERSION_PROCESSING_TIME']);
        $this->assertArrayNotHasKey('VERSION_TIME_NOT_ESTABLISHED', $s['reasons']['RESULT'] ?? []);
    }

    public function test_true_fetch_after_result_processing_remains_unestablished(): void
    {
        $scope = ['key' => '1:1', 'race_id' => 1, 'race_date' => '2025-01-01', 'expected' => ['race_result_import_id' => 10]];
        $row = Reconciliation::row('RESULT', $scope, ['race_result_import_id' => 10, 'fetched_at' => '2026-07-01T00:00:00+09:00'],
            ['import' => ['classification' => 'MATCH'], 'fetch' => ['classification' => 'MATCH', 'current' => ['fetched_at' => '2026-07-01T00:00:01+09:00']]]);
        $this->assertSame([], $row['timing_evidence']);
        $this->assertContains('VERSION_TIME_NOT_ESTABLISHED', $row['reasons']);
        $this->assertNull(Reconciliation::instant('2026-02-30T00:00:00+09:00'));
        $this->assertNull(Reconciliation::instant('2026-07-01 00:00:00'));
    }

    public function test_sql_failure_keeps_partial_output_and_disconnects_without_complete(): void
    {
        $f = new F;
        $source = $f->source(fail: true);
        try {
            (new Exporter($source))->export($f->plan(), $f->root.'/failed');
            $this->fail('Expected artificial SQL failure.');
        } catch (RuntimeException) {
            $this->assertTrue($source->disconnected);
            $this->assertFileExists($f->root.'/failed/FAILED.json');
            $this->assertFileExists($f->root.'/failed/context.jsonl');
            $this->assertFileDoesNotExist($f->root.'/failed/COMPLETE.json');
        }
    }

    public static function mutations(): array
    {
        return [['extra'], ['missing'], ['sha'], ['duplicate']];
    }

    #[DataProvider('mutations')]
    public function test_offline_verify_rejects_missing_changed_extra_and_duplicate_files(string $fault): void
    {
        $f = new F;
        (new Exporter($f->source()))->export($f->plan(), $f->root.'/export');
        Evidence::create($f->root.'/mutated');
        foreach (scandir($f->root.'/export') as $file) {
            if ($file === '.' || $file === '..' || ($fault === 'missing' && $file === 'context.jsonl')) {
                continue;
            }
            copy($f->root.'/export/'.$file, $f->root.'/mutated/'.$file);
        }
        if ($fault === 'extra') {
            Evidence::json($f->root.'/mutated', 'FAILED.json', ['synthetic' => true]);
        } elseif ($fault === 'sha') {
            file_put_contents($f->root.'/mutated/context.jsonl', "{}\n", FILE_APPEND);
        } elseif ($fault === 'duplicate') {
            $path = $f->root.'/mutated/context.jsonl';
            $lines = file($path);
            file_put_contents($path, $lines[0], FILE_APPEND);
            $manifest = Json::read($f->root.'/mutated/manifest.json');
            $manifest['files']['context.jsonl'] = [...Json::seal($path), 'rows' => 5];
            file_put_contents($f->root.'/mutated/manifest.json', Json::encode($manifest));
            file_put_contents($f->root.'/mutated/COMPLETE.json', Json::encode(Json::seal($f->root.'/mutated/manifest.json')));
        }
        $this->expectException(RuntimeException::class);
        (new Verifier)->verify($f->root.'/mutated', $f->root.'/rejected');
    }

    public function test_chunking_does_not_build_a_single_large_parameter(): void
    {
        $f = new F;
        $db = Scope::index($f->root.'/chunks.sqlite');
        for ($i = 1; $i <= 405; $i++) {
            Scope::add($db, 'CONTEXT', ['key' => (string) $i, 'race_id' => $i, 'race_date' => '2025-01-01', 'expected' => ['id' => $i]]);
        }
        $counts = [];
        foreach (Queries::chunks($db, 'CONTEXT') as $chunk) {
            $counts[] = count($chunk);
        }
        $this->assertSame([200, 200, 5], $counts);
    }

    public function test_streamed_scope_and_output_digests_across_three_fetch_chunks(): void
    {
        $f = new F;
        $old = $f->plan();
        Evidence::create($f->root.'/expanded');
        [$manifest, $db] = Scope::load($old, $f->root.'/expanded/scope-index.sqlite');
        $row = iterator_to_array(Scope::rows($db, 'FETCH'))[0];
        for ($i = 4000; $i < 4405; $i++) {
            $r = [...$row, 'key' => (string) $i, 'expected' => [...$row['expected'], 'id' => $i]];
            Scope::add($db, 'FETCH', $r);
        }
        $files = [];
        foreach (Evidence::SCOPE_FILES as $type => $name) {
            $files[$name] = Evidence::writeRows($f->root.'/expanded', $name, Scope::rows($db, $type));
        }
        $counts = Scope::validate($db);
        $db = null;
        $files['scope-index.sqlite'] = Json::seal($f->root.'/expanded/scope-index.sqlite');
        Evidence::publish($f->root.'/expanded', 'scope-manifest.json', [...$manifest, 'counts' => $counts, 'files' => $files]);
        (new Exporter($f->source()))->export($f->root.'/expanded/scope-manifest.json', $f->root.'/export');
        (new Verifier)->verify($f->root.'/export', $f->root.'/verify');
        $this->assertSame(414, Json::read($f->root.'/export/summary.json')['fixed']['FETCH']['MATCH']);
        $this->assertSame(414, Json::read($f->root.'/verify/verification.json')['counts']['FETCH']);
    }

    public function test_io_collision_does_not_overwrite_or_remove_original(): void
    {
        $f = new F;
        Json::write($f->root.'/occupied.jsonl', 'RETAIN_THIS');
        try {
            new Writer($f->root.'/occupied.jsonl');
            $this->fail('Existing file accepted.');
        } catch (RuntimeException) {
            $this->assertSame('RETAIN_THIS', file_get_contents($f->root.'/occupied.jsonl'));
        }
    }
}
