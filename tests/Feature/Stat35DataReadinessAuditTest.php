<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Keirin\Audit\Stat35DataReadiness\Analysis;
use App\Domain\Keirin\Audit\Stat35DataReadiness\Contract;
use App\Domain\Keirin\Audit\Stat35DataReadiness\Extractor;
use App\Domain\Keirin\Audit\Stat35DataReadiness\History;
use App\Domain\Keirin\Audit\Stat35DataReadiness\RawReader;
use App\Domain\Keirin\Audit\Stat35DataReadiness\Service;
use App\Domain\Keirin\Audit\Stat35DataReadiness\Source;
use App\Domain\Keirin\Audit\Stat35DataReadiness\Targets;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\JsonlArtifact;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Backtest\Experiments\TacticalPredictionPipeline\ArtifactStore;
use App\Domain\Keirin\Backtest\Experiments\TacticalPredictionResult\ResultStore;
use Generator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class Stat35DataReadinessAuditTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/stat35-synthetic-'.bin2hex(random_bytes(8));
        mkdir($this->root);
        mkdir($this->root.'/audit');
        config(['tactical_prediction_pipeline.artifact_base' => $this->root]);
    }

    protected function tearDown(): void
    {
        if (isset($this->root)) {
            $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($items as $item) {
                $item->isDir() && ! $item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }
            rmdir($this->root);
        }
        parent::tearDown();
    }

    private function fixture(array $headers = Contract::HEADERS, ?array $rows = null, string $tbody = ''): array
    {
        $race = ['race_id' => 1, 'race_date' => '2024-02-01', 'scheduled_start_at' => '2024-02-01 12:00:00+09:00',
            'meeting_id' => 1, 'meeting_start' => '2024-02-01', 'meeting_grade' => 'F2', 'race_type' => 'Ａ級予選',
            'race_number' => 1, 'entrant_count' => 5, 'track_code' => '22'];
        $entries = $results = $default = [];
        foreach (range(1, 5) as $bike) {
            $entries[] = ['id' => $bike, 'race_id' => 1, 'bike_number' => $bike, 'player_id' => $bike, 'external_player_id' => '10000'.$bike];
            $results[] = ['race_id' => 1, 'race_entry_id' => $bike, 'player_id' => $bike, 'bike_number' => $bike,
                'race_result_import_id' => 1, 'result_status' => 'FINISHED'];
            $default[] = array_replace(array_fill_keys(Contract::ROW_KEYS, ''), ['syaban' => (string) $bike, 'agari' => '11.4',
                'sensyuRegistNo' => '10000'.$bike, 'tyaku' => 'DO_NOT_CONSUME', 'kojinStateItemSubData' => []]);
        }
        $rows ??= $default;
        $html = '<html><body><table id="rrDispTyakuJyun"><thead><tr>'.implode('', array_map(fn ($h) => '<td>'.$h.'</td>', $headers))
            .'</tr></thead><tbody>'.$tbody.'</tbody></table><script>jsonData["PC0201"] = '
            .json_encode(['C0201data' => ['selKaisai' => '20240201', 'selKjyoCd' => '22', 'selRaceNo' => 1]])
            .'; jsonData["PJ0326"] = '.json_encode(['tyakujyunItemSubData' => $rows]).';</script></body></html>';

        return compact('race', 'entries', 'results', 'rows', 'html');
    }

    public function test_header_driven_extraction_does_not_consume_rank_and_preserves_decimal(): void
    {
        $f = $this->fixture();
        $out = (new Extractor)->parse($f['html'], $f['race'], $f['entries'], $f['results']);
        $this->assertCount(5, $out['rows']);
        $this->assertSame('11.4', $out['rows'][0]['normalized_agari_seconds']);
        $this->assertNull($out['rows'][0]['row_cell_count']);
        $this->assertSame(12, $out['rows'][0]['logical_header_count']);
        $this->assertArrayNotHasKey('rank', $out['rows'][0]);
    }

    public function test_header_order_changes_signature_but_not_value_identity(): void
    {
        $f = $this->fixture();
        $r = $this->fixture(array_reverse(Contract::HEADERS));
        $a = (new Extractor)->parse($f['html'], $f['race'], $f['entries'], $f['results']);
        $b = (new Extractor)->parse($r['html'], $r['race'], $r['entries'], $r['results']);
        $this->assertNotSame($a['header_signature'], $b['header_signature']);
        $this->assertSame(array_column($a['rows'], 'normalized_agari_seconds'), array_column($b['rows'], 'normalized_agari_seconds'));
    }

    public static function malformed(): array
    {
        return array_map(fn ($v) => [$v], ['duplicate_header', 'missing_header', 'unknown_header', 'cell_mismatch', 'duplicate_bike',
            'bike_zero', 'bike_ten', 'entry_mismatch', 'result_mismatch', 'player_mismatch', 'row_schema', 'race_context', 'empty_rows']);
    }

    #[DataProvider('malformed')]
    public function test_malformed_raw_is_rejected(string $kind): void
    {
        $f = $this->fixture();
        $h = Contract::HEADERS;
        $rows = $f['rows'];
        $body = '';
        match ($kind) {
            'duplicate_header' => $h[] = '上り',
            'missing_header' => $h = [],
            'unknown_header' => $h[8] = '未確認名称',
            'cell_mismatch' => $body = '<tr><td>11.4</td></tr>',
            'duplicate_bike' => $rows[1]['syaban'] = '1',
            'bike_zero' => $rows[0]['syaban'] = '0',
            'bike_ten' => $rows[0]['syaban'] = '10',
            'entry_mismatch' => $f['entries'][0]['bike_number'] = 9,
            'result_mismatch' => $f['results'][0]['bike_number'] = 9,
            'player_mismatch' => $rows[0]['sensyuRegistNo'] = '999999',
            'row_schema' => $rows[0]['unexpected'] = 'x',
            'race_context' => $f['race']['race_date'] = '2024-02-02',
            'empty_rows' => $rows = [],
        };
        $html = $this->fixture($h, $rows, $body)['html'];
        $this->expectException(RuntimeException::class);
        (new Extractor)->parse($html, $f['race'], $f['entries'], $f['results']);
    }

    public static function values(): array
    {
        return [['11.400', 'VALID', '11.400'], ['11', 'VALID', '11'], ['', 'MISSING_AGARI', null],
            ['-', 'MISSING_AGARI', null], [null, 'MISSING_AGARI', null], ['abc', 'NON_NUMERIC_AGARI', null],
            ['1e1', 'INVALID_AGARI_FORMAT', null], ['0', 'OUT_OF_RANGE_AGARI', null], [11.4, 'INVALID_AGARI_FORMAT', null]];
    }

    #[DataProvider('values')]
    public function test_value_semantics(mixed $raw, string $status, ?string $value): void
    {
        $this->assertSame(['normalized_agari_seconds' => $value, 'agari_status' => $status], (new Extractor)->value($raw));
    }

    public static function resultStatuses(): array
    {
        return array_map(fn ($s) => [$s], Contract::STATUSES);
    }

    #[DataProvider('resultStatuses')]
    public function test_quality_status_is_preserved_without_rank(string $status): void
    {
        $f = $this->fixture();
        $f['results'][0]['result_status'] = $status;
        $out = (new Extractor)->parse($f['html'], $f['race'], $f['entries'], $f['results']);
        $this->assertSame($status, $out['rows'][0]['result_status']);
        $this->assertSame('VALID', $out['rows'][0]['agari_status']);
    }

    private function history(): array
    {
        return ['race_id' => 2, 'import_id' => 1, 'race_date' => '2023-12-01', 'scheduled_start_at' => '2023-12-01T12:00:00+09:00',
            'fetched_at' => '2024-01-01T12:00:00+09:00', 'player_id' => 1, 'meeting_id' => 2, 'agari_status' => 'VALID',
            'result_status' => 'FINISHED', 'status_import_id' => 1];
    }

    public static function temporal(): array
    {
        return [
            ['before', 'PRE_MEETING'], ['equal', 'LATER_OR_EQUAL_OBSERVATION_EXCLUDED'],
            ['after', 'LATER_OR_EQUAL_OBSERVATION_EXCLUDED'], ['self', 'TARGET_SELF_EXCLUDED'],
            ['future', 'FUTURE_EVENT_EXCLUDED'], ['same_meeting', 'IN_MEETING'],
            ['unknown', 'UNKNOWN_TIMING'], ['invalid', 'INVALID_AGARI'],
        ];
    }

    #[DataProvider('temporal')]
    public function test_temporal_cutoff(string $case, string $expected): void
    {
        $t = $this->fixture()['race'] + ['player_id' => 1];
        $h = $this->history();
        match ($case) {
            'equal' => $h['fetched_at'] = $t['scheduled_start_at'],
            'after' => $h['fetched_at'] = '2024-02-02T12:00:00+09:00',
            'self' => $h['race_id'] = 1,
            'future' => $h['scheduled_start_at'] = '2024-02-03T12:00:00+09:00',
            'same_meeting' => $h['meeting_id'] = 1,
            'unknown' => $h['fetched_at'] = null,
            'invalid' => $h['agari_status'] = 'MISSING_AGARI',
            default => null,
        };
        $this->assertSame($expected, (new History)->candidate($h, $t));
    }

    public function test_latest_valid_pre_target_is_selected_not_later_correction(): void
    {
        $a = $this->history();
        $b = array_replace($a, ['import_id' => 2, 'fetched_at' => '2024-01-02T12:00:00+09:00']);
        $c = array_replace($a, ['import_id' => 3, 'fetched_at' => '2024-02-02T12:00:00+09:00']);
        $out = (new History)->latest([$c, $a, $b], $this->fixture()['race'] + ['player_id' => 1]);
        $this->assertSame(2, $out[0]['row']['import_id']);
        $this->assertCount(1, $out);
    }

    public function test_2026_rejected_at_command_and_history_boundaries(): void
    {
        $this->artisan('keirin:audit:stat35-data-readiness', ['--plan' => true, '--to' => '2026-01-01'])->assertFailed();
        $h = array_replace($this->history(), ['race_date' => '2026-01-01']);
        $this->expectException(RuntimeException::class);
        (new History)->candidate($h, $this->fixture()['race'] + ['player_id' => 1]);
    }

    private function raw(array $f, string $name = 'raw'): array
    {
        $path = $this->root.'/'.$name.'.html';
        file_put_contents($path, $f['html']);

        return ['absolute_path' => $path, 'race_date' => $f['race']['race_date'], 'source_hash' => hash('sha256', $f['html']),
            'raw_response_size' => strlen($f['html']), 'fetch_hash' => hash('sha256', $f['html']),
            'fetch_bytes' => strlen($f['html']), 'converted_hash' => hash('sha256', $f['html'])];
    }

    public static function corruptions(): array
    {
        return array_map(fn ($v) => [$v], ['missing', 'symlink', 'parent_symlink', 'hash', 'bytes', 'source_conflict', 'converted']);
    }

    #[DataProvider('corruptions')]
    public function test_source_integrity_failure_is_fatal(string $case): void
    {
        $raw = $this->raw($this->fixture());
        if ($case === 'missing') {
            unlink($raw['absolute_path']);
        } elseif ($case === 'symlink') {
            symlink($raw['absolute_path'], $this->root.'/link.html');
            $raw['absolute_path'] = $this->root.'/link.html';
        } elseif ($case === 'parent_symlink') {
            symlink($this->root, $this->root.'/parent');
            $raw['absolute_path'] = $this->root.'/parent/raw.html';
        } elseif ($case === 'hash') {
            $s = file_get_contents($raw['absolute_path']);
            $s[50] = $s[50] === 'x' ? 'y' : 'x';
            file_put_contents($raw['absolute_path'], $s);
        } elseif ($case === 'bytes') {
            $raw['raw_response_size']++;
        } elseif ($case === 'source_conflict') {
            $raw['fetch_hash'] = str_repeat('a', 64);
        } else {
            $raw['converted_hash'] = str_repeat('a', 64);
        }
        $this->expectException(RuntimeException::class);
        (new RawReader)->read($raw);
    }

    public function test_plan_does_not_connect_to_database(): void
    {
        config(['database.default' => 'stat35_disabled']);
        $this->artisan('keirin:audit:stat35-data-readiness', ['--plan' => true])->assertSuccessful();
    }

    public function test_registered_query_has_scope_and_rejects_mutation(): void
    {
        $s = new Source;
        foreach (['races', 'entries', 'results', 'imports'] as $kind) {
            $q = $s->query($kind, ids: [1]);
            $s->approved($q);
            $this->assertContains('2022-01-01', $q->getBindings());
            $this->assertContains('2025-12-31', $q->getBindings());
            $this->assertStringNotContainsString('"rank"', $q->toSql());
            $this->assertStringNotContainsString('deleted_at', $q->toSql());
        }
        $q->where('r.id', 999);
        $this->expectException(RuntimeException::class);
        $s->approved($q);
    }

    public function test_read_only_session_rejects_write(): void
    {
        DB::statement('CREATE TABLE stat35_test (id INTEGER)');
        $this->expectException(QueryException::class);
        (new Source)->session(fn () => DB::insert('INSERT INTO stat35_test VALUES (1)'));
    }

    private function service(array $f, bool $duplicate = false, bool $endDrift = false, bool $withPrior = false): Service
    {
        $raw = $this->raw($f);
        $import = $raw + ['import_id' => 1, 'race_id' => 1, 'scraping_fetch_log_id' => 1, 'source_url' => 'https://example.invalid/result',
            'raw_file_path' => 'raw.html', 'parser_version' => 'synthetic', 'parsed_page_status' => $f['parsed_page_status'] ?? 'RESULTS_AVAILABLE',
            'import_status' => 'SUCCEEDED', 'fetched_at' => '2024-02-01T13:00:00+09:00', 'imported_at' => '2024-02-01T13:00:01+09:00'];
        $sourceRow = ['race' => $f['race'], 'entries' => $f['entries'], 'results' => $f['results'], 'imports' => $duplicate ? [$import, $import] : [$import]];
        $sourceRows = [$sourceRow];
        if ($withPrior) {
            $prior = $this->fixture();
            $prior['race'] = array_replace($prior['race'], ['race_id' => 2, 'race_date' => '2023-12-01',
                'scheduled_start_at' => '2023-12-01T12:00:00+09:00', 'meeting_id' => 2, 'meeting_start' => '2023-12-01']);
            $prior['html'] = str_replace('20240201', '20231201', $prior['html']);
            foreach ($prior['entries'] as &$entry) {
                $entry['id'] += 10;
                $entry['race_id'] = 2;
            }
            unset($entry);
            foreach ($prior['results'] as &$result) {
                $result['race_entry_id'] += 10;
                $result['race_id'] = 2;
                $result['race_result_import_id'] = 2;
            }
            unset($result);
            $prior['imports'] = [array_replace($import, $this->raw($prior, 'prior'), ['import_id' => 2, 'race_id' => 2,
                'parsed_page_status' => 'RESULTS_AVAILABLE', 'fetched_at' => '2023-12-01T13:00:00+09:00'])];
            $sourceRows[] = array_intersect_key($prior, array_flip(['race', 'entries', 'results', 'imports']));
        }
        $source = new class($sourceRows, $endDrift) extends Source
        {
            private int $calls = 0;

            public function __construct(private array $rows, private bool $endDrift) {}

            public function session(callable $work): mixed
            {
                return $work();
            }

            public function rows(array &$digest): Generator
            {
                yield from $this->rows;
                $digest = ['sha256' => hash('sha256', Files::canonical($this->rows))];
                if (++$this->calls > 1 && $this->endDrift) {
                    $digest['sha256'] = str_repeat('a', 64);
                }
            }
        };
        $targets = new class extends Targets
        {
            public function open(string $root): array
            {
                return ['files' => []];
            }

            public function identities(array $source): Generator
            {
                foreach (range(1, 5) as $bike) {
                    yield ['year' => 2024, 'race_id' => 1, 'entry_id' => $bike, 'bike' => $bike];
                }
            }
        };

        return new Service($source, $targets, app(ResultStore::class), new ArtifactStore, new RawReader);
    }

    public function test_real_execution_path_and_db_disabled_byte_exact_reproduction(): void
    {
        $service = $this->service($this->fixture());
        $result = $service->execute($this->root.'/audit', 'synthetic-01', $this->root.'/source');
        $this->assertSame(5, $result['counts']['extraction']);
        $this->assertSame('BLOCKED_INSUFFICIENT_RAW_HISTORY', $result['readiness']['status']);
        config(['database.default' => 'stat35_disabled']);
        $repeat = $service->reproduce($this->root.'/audit', 'synthetic-01');
        $this->assertSame('BYTE_EXACT', $repeat['status']);
        $this->assertSame('NONE', $repeat['db']);
        $repeat2 = $service->reproduce($this->root.'/audit', 'synthetic-01');
        $this->assertSame('BYTE_EXACT', $repeat2['status']);
        $this->assertNotSame($repeat['attempt_path'], $repeat2['attempt_path']);
        $this->assertSame(Files::identity($repeat['attempt_path'].'/identity-mapping-audit.json'), Files::identity($repeat2['attempt_path'].'/identity-mapping-audit.json'));
        $this->assertLessThan(128 * 1024 * 1024, $repeat['peak_memory_bytes']);
        $audit = Files::json($this->root.'/audit/synthetic-01/data_quality_access_audit.json');
        $this->assertSame(0, $audit['target_rank_semantic_reads']);
        $this->assertSame(0, $audit['predictive_metric_computations']);
    }

    public function test_duplicate_import_refuses_publication(): void
    {
        $service = $this->service($this->fixture(), true);
        try {
            $service->execute($this->root.'/audit', 'duplicate-01', $this->root.'/source');
            $this->fail('Duplicate accepted');
        } catch (\PDOException $e) {
            $this->assertStringContainsString('UNIQUE', $e->getMessage());
        }
        $this->assertFileDoesNotExist($this->root.'/audit/duplicate-01/LOCKED.json');
    }

    public function test_explicit_cancelled_empty_page_is_not_an_identity_or_raw_gap_error(): void
    {
        $f = $this->fixture(rows: []);
        $f['results'] = [];
        $f['parsed_page_status'] = 'CANCELLED';
        $service = $this->service($f);
        $result = $service->execute($this->root.'/audit', 'cancelled-01', $this->root.'/source');
        $this->assertSame(0, $result['counts']['extraction']);
        $coverage = Files::json($this->root.'/audit/cancelled-01/coverage-total.json');
        $this->assertSame(1, $coverage['cancelled_imports_without_rows']);
        $this->assertArrayNotHasKey('error:RESULT_ROWS_UNAVAILABLE', $coverage);
        $this->assertSame('BYTE_EXACT', $service->reproduce($this->root.'/audit', 'cancelled-01')['status']);
    }

    public function test_source_end_drift_refuses_publication_and_keeps_evidence(): void
    {
        $service = $this->service($this->fixture(), endDrift: true);
        try {
            $service->execute($this->root.'/audit', 'end-drift-01', $this->root.'/source');
            $this->fail('Source END drift accepted');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('database START/END', $error->getMessage());
        }
        $this->assertFileDoesNotExist($this->root.'/audit/end-drift-01/LOCKED.json');
        $this->assertFileExists($this->root.'/audit/.stage-end-drift-01/source-start.json');
    }

    public function test_reproduce_rejects_same_length_raw_drift(): void
    {
        $s = $this->service($this->fixture());
        $s->execute($this->root.'/audit', 'drift-01', $this->root.'/source');
        $body = file_get_contents($this->root.'/raw.html');
        file_put_contents($this->root.'/raw.html', str_replace('11.4', '11.5', $body));
        try {
            $s->reproduce($this->root.'/audit', 'drift-01');
            $this->fail('Raw drift accepted');
        } catch (RuntimeException $error) {
            $this->assertNotSame('', $error->getMessage());
        }
        $failed = glob($this->root.'/audit/.reproduce-drift-01-*/failure.json');
        $this->assertCount(1, $failed);
        $failureSeal = Files::identity($failed[0]);
        file_put_contents($this->root.'/raw.html', $body);
        $this->assertSame('BYTE_EXACT', $s->reproduce($this->root.'/audit', 'drift-01')['status']);
        $this->assertSame($failureSeal, Files::identity($failed[0]));
    }

    public static function cancellations(): array
    {
        return [
            'empty' => [[], false, 'EXPLICIT_CANCELLED_NO_RESULT_ROWS', 0],
            'one blank' => [[''], false, 'EXPLICIT_CANCELLED_PARTIAL_ROWS_NO_AGARI', 0],
            'multiple blank' => [['', null, " \t　 "], false, 'EXPLICIT_CANCELLED_PARTIAL_ROWS_NO_AGARI', 0],
            'agari present' => [['', '11.4'], false, 'CANCELLED_WITH_AGARI_DATA_REQUIRES_REVIEW', 1],
            'malformed agari' => [[false], false, 'CANCELLED_WITH_AGARI_DATA_REQUIRES_REVIEW', 1],
            'db rows' => [[''], true, 'CANCELLED_WITH_DB_RESULTS_CONFLICT', 1],
            'db rows and agari' => [['11.4'], true, 'CANCELLED_WITH_DB_RESULTS_CONFLICT', 2],
        ];
    }

    #[DataProvider('cancellations')]
    public function test_cancelled_audit_path_never_creates_history(array $values, bool $dbRows, string $status, int $blockers): void
    {
        $rows = array_slice($this->fixture()['rows'], 0, count($values));
        foreach ($values as $i => $value) {
            $rows[$i]['agari'] = $value;
        }
        $f = $this->fixture(rows: $rows);
        if (! $dbRows) {
            $f['results'] = [];
        }
        $f['parsed_page_status'] = 'CANCELLED';
        $result = $this->service($f)->execute($this->root.'/audit', 'cancelled-review', $this->root.'/source');
        $path = $result['path'];
        $this->assertSame(0, $result['counts']['extraction']);
        $this->assertSame(0, $result['readiness']['historical_as_of_target_count']);
        $this->assertSame($blockers === 0 ? 'BLOCKED_INSUFFICIENT_RAW_HISTORY' : 'BLOCKED_IDENTITY_MAPPING', $result['readiness']['status']);
        $identity = Files::json($path.'/identity-mapping-audit.json');
        $this->assertSame($blockers, $identity['identity_blocker_total']);
        $this->assertSame($blockers === 0, $identity['identity_safe']);
        $this->assertSame(0, $identity['normal_entry_result_mismatch']);
        $raw = iterator_to_array(JsonlArtifact::read($path.'/raw-source-inventory.jsonl'))[0];
        $this->assertSame($status, $raw['extraction_status']);
        $this->assertSame(count($values), $raw['cancelled_audit']['partial_rows']);
        if ($status === 'EXPLICIT_CANCELLED_PARTIAL_ROWS_NO_AGARI') {
            $coverage = Files::json($path.'/coverage-total.json');
            $this->assertSame(1, $coverage['cancelled_partial_imports']);
            $this->assertSame(count($values), $coverage['cancelled_partial_rows']);
            $this->assertSame(0, $coverage['cancelled_partial_nonempty_agari_rows']);
            $this->assertArrayNotHasKey('parsed_imports', $coverage);
        }
        if ($blockers > 0) {
            $this->assertContains('INSUFFICIENT_RAW_HISTORY', $result['readiness']['secondary_blockers']);
        }
    }

    public function test_cancelled_blank_entry_mapping_anomalies_are_separate_diagnostics(): void
    {
        $row = $this->fixture()['rows'][0];
        $row['agari'] = '';
        $row['syaban'] = '9';
        $row['sensyuRegistNo'] = 'invalid';
        $f = $this->fixture(rows: [$row]);
        $parsed = (new Extractor)->parse($f['html'], $f['race'], $f['entries'], [], 'CANCELLED');
        $this->assertSame('EXPLICIT_CANCELLED_PARTIAL_ROWS_NO_AGARI', $parsed['cancelled']['status']);
        $this->assertSame(1, $parsed['cancelled']['entry_mapping_errors']);
        $this->assertSame(1, $parsed['cancelled']['registration_identity_errors']);
        $this->assertSame([], $parsed['rows']);
    }

    public static function invalidCancelledRows(): array
    {
        return [['duplicate'], ['zero'], ['ten'], ['schema'], ['nonobject']];
    }

    #[DataProvider('invalidCancelledRows')]
    public function test_cancelled_rows_still_validate_schema_bike_range_and_uniqueness(string $kind): void
    {
        $rows = array_slice($this->fixture()['rows'], 0, 2);
        foreach ($rows as &$row) {
            $row['agari'] = '';
        }
        unset($row);
        match ($kind) {
            'duplicate' => $rows[1]['syaban'] = '1',
            'zero' => $rows[0]['syaban'] = '0',
            'ten' => $rows[0]['syaban'] = '10',
            'schema' => $rows[0]['extra'] = 'x',
            'nonobject' => $rows[0] = 'x',
        };
        $f = $this->fixture(rows: $rows);
        $this->expectException(RuntimeException::class);
        (new Extractor)->parse($f['html'], $f['race'], $f['entries'], [], 'CANCELLED');
    }

    public function test_normal_page_missing_results_remains_an_identity_blocker(): void
    {
        $f = $this->fixture();
        $f['results'] = [];
        $result = $this->service($f)->execute($this->root.'/audit', 'normal-mismatch', $this->root.'/source');
        $audit = Files::json($result['path'].'/identity-mapping-audit.json');
        $this->assertSame(1, $audit['normal_entry_result_mismatch']);
        $this->assertFalse($audit['identity_safe']);
        $this->assertSame('BLOCKED_IDENTITY_MAPPING', $result['readiness']['status']);
    }

    public function test_unresolved_target_alone_blocks_even_with_other_targets_usable_history(): void
    {
        $f = $this->fixture(rows: []);
        $f['results'] = [];
        $f['parsed_page_status'] = 'CANCELLED';
        $f['entries'][0]['player_id'] = null;
        $result = $this->service($f, withPrior: true)->execute($this->root.'/audit', 'unresolved-target-only', $this->root.'/source');
        $identity = Files::json($result['path'].'/identity-mapping-audit.json');
        $this->assertSame(0, $identity['unresolved_extracted_player_rows']);
        $this->assertSame(1, $identity['unresolved_target_entries']);
        $this->assertSame(1, $identity['identity_blocker_total']);
        $this->assertFalse($identity['identity_safe']);
        $this->assertSame(4, $result['readiness']['historical_as_of_target_count']);
        $this->assertSame('BLOCKED_IDENTITY_MAPPING', $result['readiness']['status']);
    }

    public static function playerResolution(): array
    {
        return [['unresolved_without_prior', true, false, 0], ['unresolved_with_other_prior', true, true, 4], ['resolved_with_prior', false, true, 5]];
    }

    #[DataProvider('playerResolution')]
    public function test_extracted_and_target_unresolved_identities_are_explicit_blockers(string $id, bool $unresolved, bool $prior, int $history): void
    {
        $f = $this->fixture();
        if ($unresolved) {
            $f['entries'][0]['player_id'] = null;
            $f['results'][0]['player_id'] = null;
        }
        $result = $this->service($f, withPrior: $prior)->execute($this->root.'/audit', str_replace('_', '-', $id), $this->root.'/source');
        $audit = Files::json($result['path'].'/identity-mapping-audit.json');
        $this->assertSame((int) $unresolved, $audit['unresolved_extracted_player_rows']);
        $this->assertSame((int) $unresolved, $audit['unresolved_target_entries']);
        $this->assertSame($unresolved ? 2 : 0, $audit['identity_blocker_total']);
        $this->assertSame(! $unresolved, $audit['identity_safe']);
        $this->assertSame($history, $result['readiness']['historical_as_of_target_count']);
        $this->assertSame($unresolved ? 'BLOCKED_IDENTITY_MAPPING' : 'STRUCTURALLY_READY_FOR_STAT35_STORAGE_BACKFILL_REVIEW', $result['readiness']['status']);
        $details = iterator_to_array(JsonlArtifact::read($result['path'].'/target-history-detail.jsonl'));
        $this->assertSame($unresolved ? 'UNRESOLVED_PLAYER' : 'RESOLVED_PLAYER', $details[0]['identity']);
        $this->assertSame($unresolved ? 0 : 1, $details[0]['pre_meeting_count']);
    }

    public function test_type7_histogram_and_revision_are_deterministic(): void
    {
        $d = Analysis::distribution(['11.4' => 1, '10.2' => 1, '12.6' => 1]);
        $this->assertSame(11.4, $d['P50']);
        $this->assertSame(10.2, (float) $d['min']);
        $a = ['header_signature' => 'x', 'agari_status' => 'VALID', 'normalized_agari_seconds' => '11.4', 'raw_agari_text' => '11.4'];
        $this->assertSame('UNCHANGED', Analysis::revision($a, $a));
        $this->assertSame('FORMAT_CHANGED', Analysis::revision($a, array_replace($a, ['raw_agari_text' => '11.40', 'normalized_agari_seconds' => '11.40'])));
        $this->assertSame('VALUE_CHANGED', Analysis::revision($a, array_replace($a, ['normalized_agari_seconds' => '11.5'])));
        $this->assertSame('VALUE_CHANGED', Analysis::revision($a, array_replace($a, ['normalized_agari_seconds' => '11.4000000000000001'])));
    }

    public function test_as_of_block_is_not_downgraded_to_partial_storage_readiness(): void
    {
        $this->assertSame('BLOCKED_INSUFFICIENT_RAW_HISTORY', Analysis::readiness(0, ['error:RAW_MISSING' => 1], 0));
        $this->assertSame('BLOCKED_IDENTITY_MAPPING', Analysis::readiness(1, [], 0));
        $this->assertSame('BLOCKED_AMBIGUOUS_AGARI_SEMANTICS', Analysis::readiness(0, ['error:INCOMPATIBLE_TABLE_DEFINITION' => 1], 10));
        $this->assertSame('PARTIAL_READY_REQUIRES_RAW_GAP_POLICY', Analysis::readiness(0, ['error:RAW_MISSING' => 1], 10));
        $this->assertSame('STRUCTURALLY_READY_FOR_STAT35_STORAGE_BACKFILL_REVIEW', Analysis::readiness(0, [], 10));
    }
}
