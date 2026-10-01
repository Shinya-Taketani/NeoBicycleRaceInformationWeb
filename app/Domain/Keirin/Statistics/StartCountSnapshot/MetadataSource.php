<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\StartCountSnapshot;

use App\Domain\Keirin\Statistics\AgariRaceRelative\ConnectionGuard;
use Generator;
use Illuminate\Support\Facades\DB;
use PDO;
use RuntimeException;

class MetadataSource
{
    private const LOG_COLUMNS = ['id', 'batch_run_id', 'source', 'request_method', 'request_url', 'request_key',
        'request_parameters', 'http_status', 'fetched_at', 'content_type', 'detected_encoding',
        'utf8_conversion_succeeded', 'response_size', 'sha256', 'raw_file_path', 'parser_version', 'error_type'];

    public function capture(string $scope, callable $save): array
    {
        // A separate connection; never change the shared default connection or its config.
        $connection = DB::build(config('database.connections.pgsql'));
        $connection->disableQueryLog();
        $connection->setReconnector(static fn () => throw new RuntimeException('Metadata reconnect forbidden.'));
        $pdo = null;
        try {
            $pdo = $connection->getPdo();
            $pdo->exec('SET SESSION default_transaction_read_only = on');
            $pdo->exec("SET SESSION statement_timeout = '15min'");
            $pdo->exec("SET SESSION lock_timeout = '5s'");
            $observed = $pdo->query("SELECT current_database() AS database, current_schema() AS schema,
                host(inet_server_addr()) AS host, inet_server_port() AS port,
                current_setting('default_transaction_read_only') AS session_read_only,
                current_setting('transaction_read_only') AS transaction_read_only")->fetch(PDO::FETCH_ASSOC);
            $save('connection-precheck.jsonl', [$observed]);
            $endpoint = ConnectionGuard::endpoint($observed);
            $pdo->exec('BEGIN TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
            $snapshot = ConnectionGuard::snapshot($pdo->query("SELECT current_setting('transaction_isolation') AS isolation,
                current_setting('transaction_read_only') AS snapshot_read_only, pg_current_snapshot()::text AS snapshot")->fetch(PDO::FETCH_ASSOC));
            foreach (self::queries() as $name => $sql) {
                $save($name, $this->rows($pdo, $sql, $scope));
            }

            return ['endpoint' => $endpoint, 'snapshot' => $snapshot, 'exported_at' => date(DATE_ATOM),
                'request_identifiers_are_private' => true, 'business_writes' => 0];
        } finally {
            if ($pdo instanceof PDO && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $connection->disconnect();
        }
    }

    private function rows(PDO $pdo, string $sql, string $scope): Generator
    {
        $statement = $pdo->prepare('DECLARE start_count_metadata NO SCROLL CURSOR FOR '.$sql);
        $statement->execute([$scope]);
        try {
            do {
                $page = $pdo->query('FETCH FORWARD 100 FROM start_count_metadata')->fetchAll(PDO::FETCH_ASSOC);
                foreach ($page as $row) {
                    foreach (['race_id', 'race_number', 'id', 'batch_run_id', 'http_status', 'response_size'] as $field) {
                        if (isset($row[$field])) {
                            $row[$field] = (int) $row[$field];
                        }
                    }
                    if (isset($row['request_parameters'])) {
                        $row['request_parameters'] = json_decode($row['request_parameters'], true, flags: JSON_THROW_ON_ERROR);
                    }
                    yield $row;
                }
            } while (count($page) === 100);
        } finally {
            $pdo->exec('CLOSE start_count_metadata');
        }
    }

    public static function queries(): array
    {
        $scope = 'WITH scope AS (SELECT race_id, race_date, race_number, fetch_ids FROM
            jsonb_to_recordset(?::jsonb) AS s(race_id bigint, race_date date, race_number integer, fetch_ids jsonb))';
        $raceJoin = "JOIN public.races r ON r.id=s.race_id AND r.race_date=s.race_date
            AND r.race_number=s.race_number AND r.source='keirin_jp'
            AND r.race_date >= DATE '2022-01-01' AND r.race_date < DATE '2026-01-01'";
        $columns = implode(', ', array_map(static fn ($x) => 'f.'.$x, self::LOG_COLUMNS));
        $historical = "FROM scope s CROSS JOIN LATERAL jsonb_array_elements_text(s.fetch_ids) AS old_id(value)
            JOIN public.scraping_fetch_logs f ON f.id=old_id.value::bigint AND f.source='keirin_jp'";

        return [
            'race-requests.jsonl' => $scope." SELECT s.race_id, r.source, r.external_race_id, r.race_date,
                r.race_number, r.encrypted_parameter FROM scope s $raceJoin ORDER BY s.race_id",
            'historical-requests.jsonl' => $scope." SELECT s.race_id, $columns $historical ORDER BY s.race_id, f.id",
            'sources.jsonl' => $scope.", keys AS (
                SELECT s.race_id, r.encrypted_parameter AS encp FROM scope s $raceJoin
                WHERE r.encrypted_parameter IS NOT NULL AND r.encrypted_parameter <> ''
                UNION
                SELECT s.race_id, f.request_parameters->>'encp' AS encp $historical
                WHERE f.request_parameters->>'disp' IN ('PJ0315', 'PJ0326')
                AND jsonb_typeof(f.request_parameters::jsonb->'encp')='string'
                AND f.request_parameters->>'encp' <> '')
                SELECT k.race_id, $columns FROM keys k JOIN public.scraping_fetch_logs f
                ON f.source='keirin_jp' AND f.request_parameters->>'disp'='PJ0315'
                AND f.request_parameters->>'encp'=k.encp ORDER BY k.race_id, f.id",
        ];
    }
}
