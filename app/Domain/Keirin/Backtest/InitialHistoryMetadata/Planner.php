<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\InitialHistoryMetadata;

use App\Domain\Keirin\Backtest\ChronologicalValidation\Json;
use PDO;
use Throwable;

final class Planner
{
    public function plan(string $audit, string $output): array
    {
        Evidence::create($output);
        $started = Evidence::now();
        $clock = hrtime(true);
        try {
            $marker = Json::read($audit.'/COMPLETE.json');
            Json::verify($audit.'/manifest.json', $marker['manifest']);
            $a = Json::read($audit.'/manifest.json');
            Evidence::require($a['schema'] === 'STAT2026_INITIAL_HISTORY_SOURCE_AUDIT_v1' && $a['O1'] === 'NOT_ESTABLISHED', 'Accepted source audit required.');
            foreach (['read-plan.json', 'entry-seals.json', 'source-inventory.json', 'metadata-seals.json', 'metadata-summary.json', 'history-event-field-map-v3.json',
                'next-stage-specification-v2.json', 'metadata-supplement-proposal.sql', 'review-packet-final.txt', 'document-validation-v2.json', 'syntax-validation-v3.json', 'validate3-exit.json', 'audit.sqlite'] as $file) {
                Json::verify($audit.'/'.$file, $a['files'][$file]);
            }
            Evidence::require(Json::read($audit.'/validate3-exit.json')['exit'] === 0, 'Audit final verification not successful.');
            $roots = Json::read($audit.'/source-inventory.json')['roots'];
            $b = Json::read($roots['B'].'/manifest.json');
            $c = Json::read($roots['C'].'/manifest.json');
            foreach ([['B', 'LOCKED.json'], ['C', 'COMPLETE.json']] as [$key, $terminal]) {
                Json::verify($roots[$key].'/manifest.json', Json::read($roots[$key].'/'.$terminal));
                $entry = array_values(array_filter(Json::read($audit.'/entry-seals.json'), fn ($s) => $s['path'] === $roots[$key].'/manifest.json'));
                Evidence::require(count($entry) === 1, 'Source manifest audit reference missing.');
                Json::verify($roots[$key].'/manifest.json', ['bytes' => $entry[0]['bytes'], 'sha256' => $entry[0]['measured_sha256']]);
            }
            $index = Scope::index($output.'/scope-index.sqlite');
            $old = new PDO('sqlite:file:'.$audit.'/audit.sqlite?mode=ro', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $old->exec('PRAGMA query_only=ON');
            $ends = [];
            foreach ($old->query('SELECT mid,ms,min(me) me,count(distinct me) variants FROM atargets GROUP BY mid,ms') as $r) {
                Evidence::require($r['variants'] === 1 && count($ends) < 10000, 'Ambiguous/bounded meeting crosswalk.');
                $ends[$r['mid'].':'.$r['ms']] = $r['me'];
            }
            $status = $old->prepare('SELECT status FROM draces WHERE rid=?');
            $sources = ['audit' => ['path' => $audit, 'manifest' => $marker['manifest']], 'B' => ['path' => $roots['B'], 'manifest' => Json::seal($roots['B'].'/manifest.json')],
                'C' => ['path' => $roots['C'], 'manifest' => Json::seal($roots['C'].'/manifest.json')]];
            $index->beginTransaction();
            $index->exec('CREATE TABLE c_targets (rid INTEGER PRIMARY KEY); CREATE TABLE c_entries (eid INTEGER PRIMARY KEY)');
            foreach (Evidence::rows($roots['B'].'/database-inventory.jsonl', $b['files']['database-inventory.jsonl']) as $r) {
                $race = $r['race'];
                $rid = $race['race_id'];
                $day = $race['race_date'];
                $status->execute([$rid]);
                $raceStatus = $status->fetchColumn();
                Evidence::require(is_string($raceStatus), 'Fixed outcome context missing.');
                $context = ['id' => $rid, 'race_date' => $day, 'race_number' => $race['race_number'], 'race_type' => $race['race_type'],
                    'entrant_count' => $race['entrant_count'], 'meeting_id' => $race['meeting_id'], 'meeting_start' => $race['meeting_start'],
                    'meeting_end' => $ends[$race['meeting_id'].':'.$race['meeting_start']] ?? null, 'meeting_grade' => $race['meeting_grade'],
                    'track_code' => $race['track_code'], 'scheduled_start_at' => $race['scheduled_start_at'], 'result_status' => $raceStatus];
                $this->add($index, 'CONTEXT', (string) $rid, $rid, $day, $context);
                foreach ($r['entries'] as $e) {
                    $this->add($index, 'ENTRY', (string) $e['id'], $rid, $day, $e);
                }
                foreach ($r['imports'] as $i) {
                    $expect = ['id' => $i['import_id'], 'race_id' => $i['race_id'], 'scraping_fetch_log_id' => $i['scraping_fetch_log_id']];
                    foreach (['source_hash', 'converted_hash', 'raw_file_path', 'raw_response_size', 'parser_version', 'parsed_page_status', 'import_status', 'imported_at'] as $f) {
                        $expect[$f] = $i[$f];
                    }
                    $this->add($index, 'IMPORT', (string) $i['import_id'], $rid, $day, $expect);
                }
                foreach ($r['results'] as $e) {
                    $this->add($index, 'RESULT', $rid.':'.$e['bike_number'], $rid, $day, $e);
                }
            }
            $targetCount = $targetEntries = 0;
            foreach (Evidence::rows($roots['C'].'/targets.jsonl', $c['files']['targets.jsonl']) as $target) {
                $t = $target['race'];
                $index->prepare('INSERT INTO c_targets VALUES (?)')->execute([$t['race_id']]);
                $parent = Scope::get($index, 'CONTEXT', (string) $t['race_id']);
                Evidence::require($parent !== null && $parent['race_date'] === $t['race_date'], 'B/C target race mismatch.');
                foreach (['track_code', 'race_number'] as $f) {
                    Evidence::require($parent['expected'][$f] === $t[$f], 'B/C target identity mismatch.');
                }
                foreach ($target['entries'] as $e) {
                    $entry = Scope::get($index, 'ENTRY', (string) $e['id']);
                    $index->prepare('INSERT INTO c_entries VALUES (?)')->execute([$e['id']]);
                    Evidence::require($entry !== null && Json::hash(array_intersect_key($entry['expected'], $e)) === Json::hash($e), 'B/C target roster mismatch.');
                    $targetEntries++;
                }
                $targetCount++;
            }
            foreach (['historical-requests.jsonl', 'sources.jsonl'] as $name) {
                foreach (Evidence::rows($roots['C'].'/'.$name, $c['files'][$name], true) as $r) {
                    $parent = Scope::get($index, 'CONTEXT', (string) $r['race_id']);
                    Evidence::require($parent !== null, 'Fetch outside fixed races.');
                    $expected = array_intersect_key($r, array_flip(['id', 'race_id', 'source', 'request_method', 'request_key', 'fetched_at', 'http_status', 'raw_file_path', 'sha256', 'response_size', 'parser_version', 'error_type']));
                    $expected['parameters'] = Parameters::fingerprint($r['request_parameters']);
                    Evidence::require($expected['parameters']['disp_value'] === ($name === 'sources.jsonl' ? 'PJ0315' : 'PJ0326'), 'Unexpected fixed source.');
                    $this->add($index, 'FETCH', (string) $r['id'], $r['race_id'], $parent['race_date'], $expected);
                }
            }
            $counts = Scope::validate($index);
            $summary = Json::read($audit.'/metadata-summary.json')['tables'];
            $expectedCounts = ['CONTEXT' => $summary['races']['rows'], 'ENTRY' => $summary['entries']['rows'], 'FETCH' => $summary['fetches']['rows'], 'IMPORT' => $summary['imports']['rows'], 'RESULT' => $summary['results']['rows']];
            Evidence::require($counts === $expectedCounts && $targetCount === $counts['CONTEXT'] && $targetEntries === $counts['ENTRY'], 'Fixed source totals mismatch.');
            $index->commit();
            $files = [];
            foreach (Evidence::SCOPE_FILES as $type => $name) {
                $files[$name] = Evidence::writeRows($output, $name, Scope::rows($index, $type));
            }
            $index = null;
            $files['scope-index.sqlite'] = Json::seal($output.'/scope-index.sqlite');

            return Evidence::publish($output, 'scope-manifest.json', ['version' => Evidence::VERSION, 'kind' => 'SCOPE', 'period' => ['from' => '2022-01-01', 'to' => '2025-12-31'],
                'counts' => $counts, 'files' => $files, 'sources' => $sources, 'code' => Evidence::code(), 'parameter_hash_contract' => 'JSON_OBJECT_KEY_ORDER_AND_TYPES_v1',
                'started_at' => $started, 'finished_at' => Evidence::now(), 'elapsed_seconds' => (hrtime(true) - $clock) / 1e9,
                'peak_memory_bytes' => memory_get_peak_usage(true), 'business_connections' => 0, 'http_requests' => 0]);
        } catch (Throwable $e) {
            Evidence::failed($output, $e, 'PLAN', $started, $clock);
            throw $e;
        }
    }

    private function add(PDO $index, string $type, string $key, int $race, string $day, array $expected): void
    {
        Scope::add($index, $type, ['key' => $key, 'race_id' => $race, 'race_date' => $day, 'expected' => $expected]);
    }
}
