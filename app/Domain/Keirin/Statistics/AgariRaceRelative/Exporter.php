<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\AgariRaceRelative;

use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use Generator;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class Exporter
{
    public function export(string $from, string $to, int $chunk, string $output): array
    {
        Contract::dates($from, $to);
        if ($chunk < 1 || $chunk > 1000) {
            throw new RuntimeException('Chunk must be between 1 and 1000 complete races.');
        }
        $code = Artifacts::code();
        $db = DB::connection();
        $db->disableQueryLog();
        if ($db->transactionLevel() !== 0) {
            throw new RuntimeException('A fresh read-only connection is required.');
        }
        $sqlite = app()->runningUnitTests() && $db->getDriverName() === 'sqlite' && $db->getDatabaseName() === ':memory:';
        if ($sqlite) {
            $prior = (int) $db->selectOne('PRAGMA query_only')->query_only;
            $db->statement('PRAGMA query_only=ON');
            $settings = ['test_database' => 'sqlite::memory:', 'read_only' => true];
        } else {
            if ($db->getDriverName() !== 'pgsql') {
                throw new RuntimeException('Production export requires PostgreSQL.');
            }
            $settings = (array) $db->selectOne("SELECT current_database() AS database, current_schema() AS schema,
                inet_server_addr()::text AS host, inet_server_port() AS port,
                current_setting('default_transaction_read_only') AS session_read_only,
                current_setting('transaction_read_only') AS transaction_read_only");
            if ($settings !== ['database' => 'neo_keirin_prediction_db', 'schema' => 'public', 'host' => '127.0.0.1', 'port' => 5432,
                'session_read_only' => 'on', 'transaction_read_only' => 'on']) {
                throw new RuntimeException('Unexpected endpoint or READ ONLY was not enabled before connecting.');
            }
        }
        try {
            $db->beginTransaction();
            if (! $sqlite) {
                $db->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
                $settings += (array) $db->selectOne("SELECT current_setting('transaction_isolation') AS isolation,
                    current_setting('transaction_read_only') AS snapshot_read_only, pg_current_snapshot()::text AS snapshot");
                if ($settings['isolation'] !== 'repeatable read' || $settings['snapshot_read_only'] !== 'on') {
                    throw new RuntimeException('Invalid export snapshot isolation.');
                }
            }
            Artifacts::create($output);
            $counts = ['race_count' => 0, 'result_count' => 0];
            $seal = Artifacts::write($output, 'races.jsonl', $this->lines($db, $from, $to, $chunk, $counts));
            Files::same($code, Artifacts::code(), 'export code start/end');
            $manifest = [...Contract::DISCLOSURE, 'kind' => 'INPUT', 'version' => Contract::VERSION,
                'from' => $from, 'to' => $to, 'source' => 'keirin_jp', 'order' => 'race_id_ASC',
                ...$counts, 'connection' => $settings, 'code' => $code, 'files' => ['races.jsonl' => $seal]];
            Artifacts::publish($output, $manifest);

            return $manifest;
        } finally {
            if ($db->transactionLevel() > 0) {
                $db->rollBack();
            }
            if ($sqlite) {
                $db->statement('PRAGMA query_only='.$prior);
            }
        }
    }

    private function lines(Connection $db, string $from, string $to, int $chunk, array &$counts): Generator
    {
        $last = 0;
        while (true) {
            $races = $db->table('races as r')->leftJoin('race_days as d', 'd.id', '=', 'r.race_day_id')
                ->leftJoin('race_meetings as m', 'm.id', '=', 'd.race_meeting_id')
                ->leftJoin('racetracks as t', 't.id', '=', 'r.racetrack_id')
                ->where('r.source', 'keirin_jp')->whereBetween('r.race_date', [$from, $to])->where('r.id', '>', $last)
                ->orderBy('r.id')->limit($chunk)->get(['r.id as race_id', 'r.source', 'r.race_date', 'r.race_number',
                    'r.result_status as race_status', 'r.race_type', 'r.entrant_count', 'r.grade as race_grade_raw',
                    'r.race_day_id', 'r.racetrack_id', 'd.race_date as day_date', 'm.id as meeting_id',
                    'm.grade as meeting_grade_raw', 'm.starts_on', 'm.ends_on', 'm.racetrack_id as meeting_track_id',
                    't.external_track_id as track_code']);
            if ($races->isEmpty()) {
                break;
            }
            $ids = $races->pluck('race_id')->all();
            $meetingIds = $races->pluck('meeting_id')->filter()->unique()->values()->all();
            $headers = [];
            // Capture all target header variants for each meeting in this same snapshot, not a majority vote.
            foreach ($db->table('races as r')->join('race_days as d', 'd.id', '=', 'r.race_day_id')
                ->where('r.source', 'keirin_jp')->whereBetween('r.race_date', [$from, $to])
                ->whereIn('d.race_meeting_id', $meetingIds)->select('d.race_meeting_id', 'r.grade')->distinct()->get() as $header) {
                $headers[$header->race_meeting_id][] = $header->grade;
            }
            $results = [];
            $columns = ['rr.id', 'rr.race_id', 'rr.race_result_import_id', 'rr.race_entry_id', 'rr.player_id', 'rr.bike_number',
                'rr.result_status', 'rr.agari_time_seconds', 'rr.agari_raw_text', 'rr.agari_status', 'rr.fetched_at'];
            $importColumns = ['id', 'race_id', 'import_status', 'result_count', 'source_hash', 'converted_hash', 'source_url',
                'parser_version', 'imported_at', 'requested_result_status', 'parsed_page_status'];
            $observationColumns = ['id', 'race_id', 'race_result_import_id', 'race_entry_id', 'player_id', 'external_player_id',
                'bike_number', 'result_status', 'agari_time_seconds', 'agari_raw_text', 'agari_status', 'source_url',
                'fetched_at', 'parser_version', 'metadata', 'created_at'];
            foreach (['i' => $importColumns, 'o' => $observationColumns] as $alias => $names) {
                foreach ($names as $name) {
                    $columns[] = $alias.'.'.$name.' as '.$alias.'_'.$name;
                }
            }
            $query = $db->table('race_results as rr')->join('races as r', 'r.id', '=', 'rr.race_id')
                ->leftJoin('race_result_imports as i', 'i.id', '=', 'rr.race_result_import_id')
                ->leftJoin('race_result_agari_observations as o', function ($join): void {
                    $join->on('o.race_result_import_id', '=', 'rr.race_result_import_id')->on('o.bike_number', '=', 'rr.bike_number');
                })->whereIn('rr.race_id', $ids)->where('r.source', 'keirin_jp')->whereBetween('r.race_date', [$from, $to])
                ->orderBy('rr.race_id')->orderBy('rr.bike_number')->orderBy('rr.id');
            foreach ($query->get($columns) as $record) {
                $entry = (array) $record;
                foreach (['i' => ['import', $importColumns], 'o' => ['observation', $observationColumns]] as $prefix => [$key, $names]) {
                    $nested = [];
                    foreach ($names as $name) {
                        $nested[$name] = $entry[$prefix.'_'.$name];
                        unset($entry[$prefix.'_'.$name]);
                    }
                    $entry[$key] = $nested['id'] === null ? null : $this->types($nested);
                }
                if ($entry['observation'] !== null) {
                    $entry['observation']['metadata'] = json_decode($entry['observation']['metadata'], true, 64, JSON_THROW_ON_ERROR);
                }
                $entry = $this->types($entry);
                $results[$entry['race_id']][] = $entry;
            }
            foreach ($races as $record) {
                $race = $this->types((array) $record);
                $context = array_diff_key($race, array_flip(['race_id', 'source', 'race_date', 'race_status', 'race_type']));
                $variants = $headers[$race['meeting_id']] ?? [$race['race_grade_raw']];
                usort($variants, fn ($a, $b) => Files::canonical([$a]) <=> Files::canonical([$b]));
                $context['meeting_race_grade_raw_values'] = $variants;
                $row = [...Contract::DISCLOSURE, 'schema' => Contract::VERSION, 'race_id' => $race['race_id'], 'source' => $race['source'],
                    'race_date' => $race['race_date'], 'race_status' => $race['race_status'], 'race_type' => $race['race_type'],
                    'context' => $context, 'results' => $results[$race['race_id']] ?? []];
                Contract::race($row, $from, $to, $last);
                $last = $race['race_id'];
                $counts['race_count']++;
                $counts['result_count'] += count($row['results']);
                yield Files::canonical($row)."\n";
            }
            if ($races->count() < $chunk) {
                break;
            }
        }
    }

    private function types(array $row): array
    {
        foreach (['id', 'race_id', 'race_result_import_id', 'race_entry_id', 'player_id', 'bike_number', 'result_count',
            'race_number', 'entrant_count', 'race_day_id', 'racetrack_id', 'meeting_id', 'meeting_track_id'] as $key) {
            if (isset($row[$key])) {
                $row[$key] = (int) $row[$key];
            }
        }
        if (isset($row['agari_time_seconds'])) {
            if (is_float($row['agari_time_seconds'])) {
                throw new RuntimeException('Database returned a lossy numeric type.');
            }
            $row['agari_time_seconds'] = (string) $row['agari_time_seconds'];
        }

        return $row;
    }
}
