<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\InitialHistoryMetadata;

use App\Domain\Keirin\Statistics\AgariRaceRelative\ConnectionGuard;
use Illuminate\Support\Facades\DB;
use PDO;
use RuntimeException;

final class PostgresSource implements MetadataSource
{
    public function capture(PDO $scope, callable $emit, callable $connectionEvidence): array
    {
        $connection = DB::build(config('database.connections.pgsql'));
        $connection->disableQueryLog();
        $connection->setReconnector(static fn () => throw new RuntimeException('Metadata reconnect forbidden.'));
        $pdo = null;
        $queries = [];
        try {
            $pdo = $connection->getPdo();
            $pdo->exec('SET SESSION default_transaction_read_only = on');
            $pdo->exec("SET SESSION statement_timeout = '15min'");
            $pdo->exec("SET SESSION lock_timeout = '5s'");
            $pre = $pdo->query("SELECT current_database() AS database,current_schema() AS schema,host(inet_server_addr()) AS host,inet_server_port() AS port,
                current_setting('default_transaction_read_only') AS session_read_only,current_setting('transaction_read_only') AS transaction_read_only,
                current_setting('statement_timeout') AS statement_timeout,current_setting('lock_timeout') AS lock_timeout")->fetch(PDO::FETCH_ASSOC);
            $connectionEvidence('connection-precheck.json', $pre);
            ConnectionGuard::endpoint($pre);
            $pdo->exec('BEGIN TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
            $snapshot = $pdo->query("SELECT current_setting('transaction_isolation') AS isolation,current_setting('transaction_read_only') AS snapshot_read_only,
                pg_current_snapshot()::text AS snapshot")->fetch(PDO::FETCH_ASSOC);
            $connectionEvidence('snapshot.json', $snapshot);
            self::guard($pre, $snapshot);
            foreach (Queries::all() as $type => $sql) {
                $queries[$type] = 0;
                foreach (Queries::chunks($scope, $type) as $chunk) {
                    $statement = $pdo->prepare($sql);
                    $statement->execute([json_encode($chunk, JSON_THROW_ON_ERROR)]);
                    $queries[$type]++;
                    while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
                        $emit($type, $row['scope_key'], Queries::current($type, $row));
                    }
                    $statement->closeCursor();
                }
            }

            return ['endpoint' => $pre, 'transaction' => $snapshot, 'query_chunks' => $queries, 'query_log_enabled' => false,
                'automatic_reconnect' => false, 'business_writes' => 0, 'rollback_disconnect_in_finally' => true];
        } finally {
            try {
                if ($pdo instanceof PDO && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            } finally {
                $connection->disconnect();
            }
        }
    }

    public static function guard(array $pre, array $snapshot): void
    {
        ConnectionGuard::endpoint($pre);
        ConnectionGuard::snapshot($snapshot);
        Evidence::require($pre['statement_timeout'] === '15min' && $pre['lock_timeout'] === '5s', 'Finite metadata timeouts not established.');
        Evidence::require(is_string($snapshot['snapshot'] ?? null) && preg_match('/\A[0-9]+:[0-9]+:[0-9,]*\z/', $snapshot['snapshot']) === 1, 'Repeatable read snapshot missing.');
    }
}
