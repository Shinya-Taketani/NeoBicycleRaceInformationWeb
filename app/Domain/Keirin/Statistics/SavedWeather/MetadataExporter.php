<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\SavedWeather;

use App\Domain\Keirin\Statistics\AgariC1Context\ReadOnlySession;
use Illuminate\Database\Connection;
use PDO;

final class MetadataExporter
{
    public function __construct(private readonly ReadOnlySession $session) {}

    public static function queries(): array
    {
        $scope = 'WITH scope AS (SELECT * FROM jsonb_to_recordset(?::jsonb) AS s(id bigint,race_date date,race_number integer,track_code text,external_race_id text,import_ids jsonb,needs_rows boolean))';
        $join = " FROM scope s JOIN public.races r ON r.id=s.id AND r.source='keirin_jp' AND r.external_race_id=s.external_race_id
            AND r.race_date=s.race_date AND r.race_number=s.race_number
            AND r.race_date BETWEEN DATE '2024-01-01' AND DATE '2025-01-04'
            JOIN public.racetracks t ON t.id=r.racetrack_id AND t.external_track_id=s.track_code";
        $ids = "$scope, targets AS (SELECT DISTINCT i.* $join JOIN public.race_result_imports i ON i.race_id=r.id
            AND (i.id IN (SELECT value::bigint FROM jsonb_array_elements_text(s.import_ids))
              OR (s.needs_rows AND EXISTS(SELECT 1 FROM public.race_results v WHERE v.race_id=r.id AND v.race_result_import_id=i.id))))";

        return [
            'races' => "$scope SELECT r.id,r.source,r.external_race_id,r.race_date,r.race_number,r.racetrack_id,r.result_status,t.external_track_id AS track_code $join ORDER BY r.id",
            'entries' => "$scope SELECT e.* $join JOIN public.race_entries e ON e.race_id=r.id WHERE s.needs_rows ORDER BY e.id",
            'results' => "$scope SELECT v.* $join JOIN public.race_results v ON v.race_id=r.id WHERE s.needs_rows ORDER BY v.id",
            'imports' => "$ids SELECT i.* FROM targets i ORDER BY i.id",
            // Request identifiers/encp and free-form metadata are deliberately not exported.
            'logs' => "$ids SELECT DISTINCT f.id,f.source,f.request_method,f.request_url,f.request_key,
                jsonb_build_object('disp',f.request_parameters->>'disp')::text AS request_parameters,
                f.http_status,f.fetched_at,f.content_type,f.detected_encoding,f.utf8_conversion_succeeded,
                f.response_size,f.sha256,f.raw_file_path,f.retry_count,f.parser_version,f.error_type,f.created_at,f.updated_at
                FROM targets i JOIN public.scraping_fetch_logs f ON f.id=i.scraping_fetch_log_id AND f.source='keirin_jp' ORDER BY f.id",
        ];
    }

    public function export(string $scopeFile, PDO $parent, callable $save): array
    {
        $started = microtime(true);
        $queries = self::queries();
        $counts = array_fill_keys(array_keys($queries), 0);
        $settings = $this->session->run(function (Connection $db, array $settings) use ($scopeFile, $parent, $save, $queries, &$counts): void {
            $observed = (array) $db->selectOne("SELECT current_setting('statement_timeout') AS statement_timeout,current_setting('lock_timeout') AS lock_timeout");
            $save('settings', $settings + $observed);
            $chunk = [];
            $flush = function () use (&$chunk, $db, $save, $queries, &$counts): void {
                if ($chunk === []) {
                    return;
                }
                $payload = Files::json($chunk);
                foreach ($queries as $table => $sql) {
                    $begin = microtime(true);
                    $n = 0;
                    foreach ($db->cursor($sql, [$payload]) as $row) {
                        $save($table, (array) $row);
                        $counts[$table]++;
                        $n++;
                    }
                    $save('query', ['table' => $table, 'sql' => $sql, 'scope_race_ids' => array_column($chunk, 'id'),
                        'rows' => $n, 'started_at_unix' => $begin, 'duration_seconds' => microtime(true) - $begin,
                        'exit_code' => 0, 'peak_memory_bytes' => memory_get_peak_usage(true)]);
                }
                $chunk = [];
            };
            foreach (Files::rows($scopeFile) as $row) {
                $pointers = array_values(array_unique(array_filter(array_column($row['results'], 'race_result_import_id'), fn ($x) => $x !== null)));
                $missing = false;
                foreach ($pointers as $id) {
                    if (Files::select($parent, 'SELECT i.id FROM race_result_imports i JOIN scraping_fetch_logs f ON f.id=i.scraping_fetch_log_id WHERE i.id=?', [$id]) === []) {
                        $missing = true;
                    }
                }
                $needs = count(array_filter($row['results'], fn ($v) => ! isset($v['race_result_import_id']))) > 0;
                if (! $missing && ! $needs) {
                    continue;
                }
                $chunk[] = array_replace($row['race'], ['import_ids' => $pointers, 'needs_rows' => $needs]);
                if (count($chunk) === 100) {
                    $flush();
                }
            }
            $flush();
        });

        return ['connections' => 1, 'settings' => $settings, 'rows' => $counts, 'writes' => 0,
            'rollback_and_disconnect' => true, 'duration_seconds' => microtime(true) - $started];
    }
}
