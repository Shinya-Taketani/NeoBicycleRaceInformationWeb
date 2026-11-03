<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Keirin\Backtest\ChronologicalValidation\Json;
use App\Domain\Keirin\Backtest\InitialHistoryMetadata\Evidence;
use App\Domain\Keirin\Backtest\InitialHistoryMetadata\MetadataSource;
use App\Domain\Keirin\Backtest\InitialHistoryMetadata\Planner;
use App\Domain\Keirin\Backtest\InitialHistoryMetadata\PostgresSource;
use App\Domain\Keirin\Backtest\InitialHistoryMetadata\Scope;
use Closure;
use PDO;
use RuntimeException;

/** Artificial metadata only. Never accepted as real Raw or real historical evidence. All files retained. */
final class InitialHistoryMetadataFixture
{
    public readonly CompositionResultTemporaryDirectory $temporary;

    public readonly string $root;

    public function __construct()
    {
        $this->temporary = CompositionResultTemporaryDirectory::create('/tmp');
        $this->root = $this->temporary->path();
    }

    public function audit(): string
    {
        foreach (['B', 'C', 'R'] as $name) {
            Evidence::create($this->root.'/'.$name);
        }
        $database = $targets = $inputLogs = $resultLogs = [];
        foreach (range(1, 4) as $rid) {
            $race = ['race_id' => $rid, 'race_date' => '2025-01-0'.$rid, 'scheduled_start_at' => '2025-01-0'.$rid.'T12:00:00+09:00',
                'race_number' => 1, 'race_type' => 'ARTIFICIAL_A_CLASS', 'entrant_count' => 5, 'meeting_id' => $rid,
                'meeting_start' => '2025-01-0'.$rid, 'meeting_grade' => null, 'track_code' => '28'];
            $entries = [];
            foreach (range(1, 5) as $bike) {
                $entries[] = ['id' => $rid * 10 + $bike, 'race_id' => $rid, 'bike_number' => $bike, 'player_id' => $rid * 100 + $bike,
                    'external_player_id' => str_pad((string) ($rid * 100 + $bike), 6, '0', STR_PAD_LEFT)];
            }
            $imports = $results = [];
            $versions = [1 => [100, 101], 2 => [200], 3 => [], 4 => [300]][$rid];
            foreach ($versions as $id) {
                $log = $this->log($rid, $id + 1000, 'PJ0326');
                $imports[] = ['import_id' => $id, 'race_id' => $rid, 'scraping_fetch_log_id' => $log['id'], 'source_hash' => $log['sha256'], 'converted_hash' => hash('sha256', 'synthetic-converted-'.$id),
                    'raw_file_path' => $log['raw_file_path'], 'raw_response_size' => $log['response_size'], 'parser_version' => 'ARTIFICIAL_METADATA_ONLY',
                    'parsed_page_status' => $rid === 2 ? 'CANCELLED' : 'RESULTS_AVAILABLE', 'import_status' => 'SUCCEEDED', 'imported_at' => '2026-07-01T00:00:01+09:00'];
                $resultLogs[] = $log;
            }
            if (in_array($rid, [1, 4], true)) {
                foreach ($entries as $e) {
                    $results[] = ['race_id' => $rid, 'bike_number' => $e['bike_number'], 'race_entry_id' => null, 'player_id' => null,
                        'race_result_import_id' => end($versions), 'result_status' => 'FINISHED'];
                }
            }
            $database[] = ['race' => $race, 'entries' => $entries, 'imports' => $imports, 'results' => $results];
            $targets[] = ['race' => array_intersect_key($race, array_flip(['race_id', 'race_date', 'track_code', 'race_number'])),
                'entries' => array_map(fn ($e) => array_intersect_key($e, array_flip(['id', 'race_id', 'external_player_id', 'bike_number'])), $entries)];
            $inputLogs[] = $this->log($rid, $rid + 2000, 'PJ0315');
        }
        $failed = $this->log(4, 3001, 'PJ0315');
        $failed = [...$failed, 'http_status' => null, 'raw_file_path' => null, 'sha256' => null, 'response_size' => 0, 'error_type' => 'DNS_FAILURE'];
        $inputLogs[] = $failed;
        $bFile = Evidence::writeRows($this->root.'/B', 'database-inventory.jsonl', $database);
        $bSeal = Evidence::json($this->root.'/B', 'manifest.json', ['files' => ['database-inventory.jsonl' => $bFile]]);
        Evidence::json($this->root.'/B', 'LOCKED.json', $bSeal);
        $cFiles = [];
        foreach (['targets.jsonl' => $targets, 'historical-requests.jsonl' => $resultLogs, 'sources.jsonl' => $inputLogs] as $name => $rows) {
            $cFiles[$name] = Evidence::writeRows($this->root.'/C', $name, $rows);
        }
        $cSeal = Evidence::json($this->root.'/C', 'manifest.json', ['files' => $cFiles]);
        Evidence::json($this->root.'/C', 'COMPLETE.json', $cSeal);
        $r = $this->root.'/R';
        $db = new PDO('sqlite:'.$r.'/audit.sqlite');
        $db->exec('PRAGMA journal_mode=MEMORY; CREATE TABLE atargets(mid INTEGER,ms TEXT,me TEXT); CREATE TABLE draces(rid INTEGER,status TEXT)');
        foreach (range(1, 4) as $id) {
            $db->prepare('INSERT INTO atargets VALUES (?,?,?)')->execute([$id, '2025-01-0'.$id, '2025-01-0'.$id]);
            $db->prepare('INSERT INTO draces VALUES (?,?)')->execute([$id, [1 => 'CONFIRMED', 2 => 'CANCELLED', 3 => 'UNAVAILABLE', 4 => 'CONFIRMED'][$id]]);
        }
        $db = null;
        $rFiles = ['audit.sqlite' => Json::seal($r.'/audit.sqlite')];
        foreach (['read-plan.json', 'metadata-seals.json', 'history-event-field-map-v3.json', 'next-stage-specification-v2.json',
            'metadata-supplement-proposal.sql', 'review-packet-final.txt', 'document-validation-v2.json', 'syntax-validation-v3.json'] as $file) {
            $rFiles[$file] = Evidence::json($r, $file, ['synthetic' => true]);
        }
        $rFiles['validate3-exit.json'] = Evidence::json($r, 'validate3-exit.json', ['exit' => 0]);
        $rFiles['source-inventory.json'] = Evidence::json($r, 'source-inventory.json', ['roots' => ['B' => $this->root.'/B', 'C' => $this->root.'/C']]);
        $rFiles['entry-seals.json'] = Evidence::json($r, 'entry-seals.json', array_map(fn ($key, $seal) => ['path' => $this->root.'/'.$key.'/manifest.json', 'bytes' => $seal['bytes'], 'measured_sha256' => $seal['sha256']], ['B', 'C'], [$bSeal, $cSeal]));
        $tables = [];
        foreach (['races' => 4, 'entries' => 20, 'fetches' => 9, 'imports' => 4, 'results' => 10] as $key => $count) {
            $tables[$key] = ['rows' => $count];
        }
        $rFiles['metadata-summary.json'] = Evidence::json($r, 'metadata-summary.json', ['tables' => $tables]);
        $manifest = Evidence::json($r, 'manifest.json', ['schema' => 'STAT2026_INITIAL_HISTORY_SOURCE_AUDIT_v1', 'O1' => 'NOT_ESTABLISHED', 'files' => $rFiles]);
        Evidence::json($r, 'COMPLETE.json', ['manifest' => $manifest]);

        return $r;
    }

    public function plan(): string
    {
        (new Planner)->plan($this->audit(), $this->root.'/plan');

        return $this->root.'/plan/scope-manifest.json';
    }

    private function log(int $race, int $id, string $disp): array
    {
        return ['race_id' => $race, 'id' => $id, 'source' => 'keirin_jp', 'request_method' => 'POST', 'request_key' => hash('sha256', 'artificial-'.$id),
            'request_parameters' => (object) ['disp' => $disp, 'encp' => 'ARTIFICIAL_PRIVATE_NEVER_OUTPUT'], 'http_status' => 200,
            'fetched_at' => '2026-07-01T00:00:00+09:00', 'raw_file_path' => 'artificial/log-'.$id.'.html', 'sha256' => hash('sha256', 'not-a-real-raw-'.$id),
            'response_size' => 100, 'parser_version' => 'ARTIFICIAL_METADATA_ONLY', 'error_type' => null];
    }

    public function source(?Closure $change = null, bool $fail = false): MetadataSource
    {
        return new class($change, $fail) implements MetadataSource
        {
            public bool $disconnected = false;

            public function __construct(private readonly ?Closure $change, private readonly bool $fail) {}

            public function capture(PDO $scope, callable $emit, callable $connectionEvidence): array
            {
                [$pre, $snapshot] = InitialHistoryMetadataFixture::connection();
                PostgresSource::guard($pre, $snapshot);
                $connectionEvidence('connection-precheck.json', $pre);
                $connectionEvidence('snapshot.json', $snapshot);
                try {
                    foreach (Evidence::TYPES as $type) {
                        foreach (Scope::rows($scope, $type) as $r) {
                            $current = InitialHistoryMetadataFixture::current($type, $r['expected']);
                            $current = $this->change === null ? $current : ($this->change)($type, $r, $current);
                            $emit($type, $r['key'], $current);
                            if ($this->fail) {
                                throw new RuntimeException('Artificial SQL failure.');
                            }
                        }
                    }
                    if ($this->change !== null) {
                        foreach (($this->change)('ADDITIONAL', [], null) ?? [] as $row) {
                            $emit($row['record_type'], '', $row['current']);
                        }
                    }

                    return ['fixture' => 'ARTIFICIAL_NO_BUSINESS_DB', 'business_writes' => 0, 'disconnected' => true];
                } finally {
                    $this->disconnected = true;
                }
            }
        };
    }

    public static function connection(): array
    {
        return [['database' => 'neo_keirin_prediction_db', 'schema' => 'public', 'host' => '127.0.0.1', 'port' => 5432, 'session_read_only' => 'on',
            'transaction_read_only' => 'on', 'statement_timeout' => '15min', 'lock_timeout' => '5s'], ['isolation' => 'repeatable read', 'snapshot_read_only' => 'on', 'snapshot' => '1:2:']];
    }

    public static function current(string $type, array $e): array
    {
        $times = ['created_at' => '2026-07-01T00:00:01+09:00', 'updated_at' => '2026-07-01T00:00:04+09:00'];

        return match ($type) {
            'CONTEXT' => [...$e, 'source' => 'keirin_jp', 'external_race_id' => 'ARTIFICIAL_'.$e['id'], 'racetrack_id' => 10, 'race_day_id' => $e['id'] + 10,
                'race_day_date' => $e['race_date'], 'day_number' => 1, 'meeting_day_kind' => null, 'sales_close_at' => null],
            'ENTRY' => $e,
            'FETCH' => [...$e, ...$times],
            'IMPORT' => [...$e, ...$times, 'requested_result_status' => $e['parsed_page_status'] === 'CANCELLED' ? 'CANCELLED' : 'CONFIRMED'],
            'RESULT' => [...$e, 'id' => $e['race_id'] * 100 + $e['bike_number'], 'rank' => $e['bike_number'], 'winning_technique' => null,
                'fetched_at' => '2026-07-01T00:00:03+09:00', ...$times, 'created_at' => '2025-01-01T00:00:00+09:00'],
        };
    }
}
