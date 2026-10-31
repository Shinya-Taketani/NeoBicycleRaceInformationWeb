<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\SavedWeather;

use DateTimeImmutable;
use PDO;
use RuntimeException;

final class Snapshot
{
    /** Compare only recorded fields; absent, NULL and empty are not interchangeable. */
    public static function matches(array $old, array $current, array $except = []): bool
    {
        foreach ($old as $key => $value) {
            if (in_array($key, $except, true)) {
                continue;
            }
            if (! array_key_exists($key, $current)) {
                return false;
            }
            $other = $current[$key];
            if ($value === null || $other === null) {
                if ($value !== $other) {
                    return false;
                }
            } elseif (str_ends_with($key, '_at')) {
                if ((new DateTimeImmutable((string) $value))->format('U.u') !== (new DateTimeImmutable((string) $other))->format('U.u')) {
                    return false;
                }
            } elseif (in_array($key, ['race_score', 'agari_time_seconds'], true)
                && is_numeric($value) && is_numeric($other)) {
                // PostgreSQL NUMERIC strings lose trailing zeroes in the original SQLite adapter.
                if (! is_finite((float) $value) || (float) $value !== (float) $other) {
                    return false;
                }
            } elseif ((is_scalar($value) && is_scalar($other)) ? (string) $value !== (string) $other : $value !== $other) {
                return false;
            }
        }

        return true;
    }

    public static function binding(array $old, array $current): array
    {
        $pointer = array_key_exists('race_result_import_id', $old)
            ? ($old['race_result_import_id'] === null ? 'EXPLICIT_NULL' : 'RECORDED') : 'NOT_RECORDED';
        if ($pointer === 'RECORDED') {
            return ['state' => 'FIXED_OLD_POINTER', 'import_id' => (int) $old['race_result_import_id'], 'snapshot_import_pointer' => $pointer];
        }
        $ok = isset($old['id']) && self::matches($old, $current, ['race_result_import_id']);

        return ['state' => $ok ? 'CURRENT_SOURCE_ROW_MATCHED' : 'SOURCE_ROW_DRIFT',
            'import_id' => $ok ? ($current['race_result_import_id'] ?? null) : null,
            'snapshot_import_pointer' => $pointer, 'binding_origin' => 'CURRENT_SOURCE_ROW_READ_ONLY_EXPORT',
            'source_row_payload_matches_snapshot' => $ok, 'historical_import_pointer_at_snapshot' => 'NOT_PROVEN',
            'historical_as_of_available' => false, 'old_pointer' => $old['race_result_import_id'] ?? null,
            'observed_pointer' => $current['race_result_import_id'] ?? null];
    }

    public function scope(PDO $db, string $snapshot, string $output): array
    {
        $manifest = Files::read($snapshot.'/manifest.json');
        if (Files::read($snapshot.'/COMPLETE.json')['manifest_sha256'] !== hash_file('sha256', $snapshot.'/manifest.json')) {
            throw new RuntimeException('SNAPSHOT_MANIFEST_MISMATCH');
        }
        $audit = ['normalization' => 'recorded scalar string representation; race_score/agari_time_seconds finite numeric equality after SQLite affinity; timestamps UTC microseconds; NULL strict',
            'original_sql' => [], 'tables' => [], 'w45_adapter' => 'Only imports/logs added; raw_file_path mapped to fetch-log-id.html; no result timestamps updated'];
        $unrecorded = [];
        foreach (['races', 'race_entries', 'race_results'] as $table) {
            $ref = $manifest['files'][$table];
            Files::verify(['path' => $snapshot.'/'.$ref['path'], 'bytes' => $ref['bytes'], 'sha256' => $ref['sha256']]);
            $counts = ['rows' => 0, 'NOT_RECORDED' => 0, 'EXPLICIT_NULL' => 0, 'RECORDED' => 0,
                'duplicate_ids' => 0, 'missing_ids' => 0, 'extra_ids' => 0, 'payload_mismatches' => 0];
            $ids = [];
            foreach (Files::rows($snapshot.'/'.$ref['path']) as $row) {
                if (! is_int($row['id'] ?? null) || $row['id'] < 1) {
                    throw new RuntimeException('INVALID_SNAPSHOT_PRIMARY_KEY');
                }
                if (isset($ids[$row['id']])) {
                    $counts['duplicate_ids']++;
                }
                $ids[$row['id']] = true;
                $current = Files::select($db, "SELECT * FROM $table WHERE id=?", [$row['id']]);
                if ($current === []) {
                    $counts['missing_ids']++;
                } elseif (count($current) !== 1 || ! self::matches($row, $current[0])) {
                    $counts['payload_mismatches']++;
                }
                $counts['rows']++;
                if ($table === 'race_results') {
                    $state = ! array_key_exists('race_result_import_id', $row) ? 'NOT_RECORDED' : ($row['race_result_import_id'] === null ? 'EXPLICIT_NULL' : 'RECORDED');
                    $counts[$state]++;
                    if ($state === 'NOT_RECORDED') {
                        $unrecorded[$row['id']] = true;
                    }
                }
            }
            $sqliteCount = 0;
            foreach ($db->query("SELECT id FROM $table ORDER BY id") as $row) {
                $sqliteCount++;
                if (! isset($ids[$row['id']])) {
                    $counts['extra_ids']++;
                }
            }
            $counts['snapshot_unique_ids'] = count($ids);
            $counts['sqlite_ids'] = $sqliteCount;
            unset($ids);
            if ($counts['duplicate_ids'] + $counts['missing_ids'] + $counts['extra_ids'] + $counts['payload_mismatches'] > 0
                || (isset($ref['rows']) && $ref['rows'] !== $counts['rows'])) {
                throw new RuntimeException('SNAPSHOT_SET_OR_PAYLOAD_MISMATCH:'.Files::json(['table' => $table, 'counts' => $counts]));
            }
            $audit['tables'][$table] = $counts;
            $audit['original_sql'][$table] = $ref['sql'];
        }
        $stream = Files::create($output);
        $count = 0;
        try {
            $query = $db->query('SELECT r.*,t.external_track_id AS track_code FROM races r LEFT JOIN racetracks t ON t.id=r.racetrack_id ORDER BY r.id');
            while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
                if ($row['race_date'] < '2024-01-01' || $row['race_date'] > '2025-01-04' || $row['source'] !== 'keirin_jp') {
                    throw new RuntimeException('FIXED_SCOPE_OUT_OF_RANGE');
                }
                if ($row['track_code'] === null) {
                    throw new RuntimeException('FIXED_RACE_TRACK_METADATA_MISSING');
                }
                $race = array_intersect_key($row, array_flip(['id', 'source', 'external_race_id', 'race_date', 'race_number', 'racetrack_id', 'result_status', 'track_code']));
                $entries = Files::select($db, 'SELECT * FROM race_entries WHERE race_id=? ORDER BY id', [$row['id']]);
                $results = Files::select($db, 'SELECT * FROM race_results WHERE race_id=? ORDER BY id', [$row['id']]);
                foreach ($results as &$result) {
                    if (isset($unrecorded[$result['id']])) {
                        unset($result['race_result_import_id']);
                    }
                }
                unset($result);
                Files::append($stream, ['race' => $race, 'race_digest' => hash('sha256', Files::json($row)), 'entries' => $entries, 'results' => $results]);
                $count++;
            }
        } finally {
            fclose($stream);
        }
        $audit['races'] = $count;
        if ($count !== $audit['tables']['races']['snapshot_unique_ids']) {
            throw new RuntimeException('FIXED_RACE_DENOMINATOR_MISMATCH');
        }
        $audit['scope'] = Files::seal($output);

        return $audit;
    }
}
