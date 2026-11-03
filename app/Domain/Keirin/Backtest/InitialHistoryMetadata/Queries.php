<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\InitialHistoryMetadata;

use Generator;
use PDO;

final class Queries
{
    public const CHUNK = 200;

    public static function all(): array
    {
        $scope = 'WITH s AS (SELECT * FROM jsonb_to_recordset(?::jsonb) AS x(key text,race_id bigint,race_date date,id bigint,bike_number integer)) ';
        $race = " FROM s LEFT JOIN public.races r ON r.id=s.race_id AND r.source='keirin_jp'
            AND r.race_date >= DATE '2022-01-01' AND r.race_date < DATE '2026-01-01' ";
        $prefix = 'SELECT s.key AS scope_key,r.id AS parent_present,';
        $entry = 'e.id,e.race_id,e.player_id,e.external_player_id,e.bike_number';
        $result = 'x.id,x.race_id,x.race_result_import_id,x.race_entry_id,x.player_id,x.bike_number,x.rank,x.result_status,x.winning_technique,x.fetched_at,x.created_at,x.updated_at';
        $queries = [
            'CONTEXT' => $scope.$prefix.'r.id,r.source,r.external_race_id,r.race_date,r.race_number,r.race_type,r.entrant_count,r.racetrack_id,r.race_day_id,
                r.scheduled_start_at,r.sales_close_at,r.result_status,t.external_track_id AS track_code,d.race_meeting_id AS meeting_id,d.race_date AS race_day_date,d.day_number,
                m.starts_on AS meeting_start,m.ends_on AS meeting_end,m.grade AS meeting_grade,m.day_kind AS meeting_day_kind'.$race.'
                LEFT JOIN public.racetracks t ON t.id=r.racetrack_id LEFT JOIN public.race_days d ON d.id=r.race_day_id
                LEFT JOIN public.race_meetings m ON m.id=d.race_meeting_id',
            'ENTRY' => $scope.$prefix.$entry.$race.'LEFT JOIN public.race_entries e ON e.id=s.id AND e.race_id=r.id',
            'FETCH' => $scope.$prefix.'f.id,r.id AS race_id,f.source,f.request_method,f.request_key,f.request_parameters::text AS request_parameters,
                f.fetched_at,f.created_at,f.updated_at,f.http_status,f.raw_file_path,f.sha256,f.response_size,f.parser_version,f.error_type'.$race.
                "LEFT JOIN public.scraping_fetch_logs f ON f.id=s.id AND r.id IS NOT NULL AND f.source='keirin_jp'",
            'IMPORT' => $scope.$prefix.'i.id,i.race_id,i.scraping_fetch_log_id,i.requested_result_status,i.parsed_page_status,i.import_status,
                i.imported_at,i.created_at,i.updated_at,i.source_hash,i.converted_hash,i.raw_file_path,i.raw_response_size,i.parser_version'.$race.
                'LEFT JOIN public.race_result_imports i ON i.id=s.id AND i.race_id=r.id',
            'RESULT' => $scope.$prefix.$result.$race.'LEFT JOIN public.race_results x ON x.race_id=r.id AND x.bike_number=s.bike_number',
            'EXTRA_ENTRY' => $scope.$prefix.$entry.$race.'JOIN public.race_entries e ON e.race_id=r.id',
            'EXTRA_RESULT' => $scope.$prefix.'x.id,x.race_id,x.bike_number,x.race_result_import_id,x.race_entry_id,x.player_id'.$race.'JOIN public.race_results x ON x.race_id=r.id',
        ];
        foreach ($queries as $type => &$sql) {
            $sql .= ' ORDER BY s.race_id,s.key'.($type === 'EXTRA_ENTRY' ? ',e.id' : ($type === 'EXTRA_RESULT' ? ',x.bike_number,x.id' : ''));
        }

        return $queries;
    }

    public static function chunks(PDO $scope, string $type): Generator
    {
        $chunk = [];
        foreach (Scope::rows($scope, str_starts_with($type, 'EXTRA_') ? 'CONTEXT' : $type) as $r) {
            $chunk[] = ['key' => $r['key'], 'race_id' => $r['race_id'], 'race_date' => $r['race_date'],
                'id' => $r['expected']['id'] ?? null, 'bike_number' => $r['expected']['bike_number'] ?? null];
            if (count($chunk) === self::CHUNK) {
                yield $chunk;
                $chunk = [];
            }
        }
        if ($chunk !== []) {
            yield $chunk;
        }
    }

    public static function current(string $type, array $row): ?array
    {
        if ($row['parent_present'] === null || $row['id'] === null) {
            return null;
        }
        unset($row['parent_present'], $row['scope_key']);
        foreach (['id', 'race_id', 'race_number', 'entrant_count', 'racetrack_id', 'race_day_id', 'meeting_id', 'day_number', 'player_id', 'bike_number',
            'http_status', 'response_size', 'scraping_fetch_log_id', 'raw_response_size', 'race_result_import_id', 'race_entry_id', 'rank'] as $field) {
            if (isset($row[$field]) && is_string($row[$field])) {
                Evidence::require(ctype_digit($row[$field]) && (string) (int) $row[$field] === $row[$field], 'Noncanonical metadata integer.');
                $row[$field] = (int) $row[$field];
            }
        }
        if ($type === 'FETCH') {
            $row['parameters'] = Parameters::fingerprint(json_decode($row['request_parameters'], false, 64, JSON_THROW_ON_ERROR));
            unset($row['request_parameters']);
        }

        return $row;
    }
}
