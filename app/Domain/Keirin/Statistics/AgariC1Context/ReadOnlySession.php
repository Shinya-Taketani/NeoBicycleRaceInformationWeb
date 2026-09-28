<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\AgariC1Context;

use App\Domain\Keirin\Statistics\AgariRaceRelative\ConnectionGuard;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PDOException;
use RuntimeException;

final class ReadOnlySession
{
    public function run(callable $work): array
    {
        $db = null;
        try {
            // A private connection, never the application's default/shared connection.
            $db = DB::build(config('database.connections.pgsql'));
            $db->disableQueryLog();
            $db->setReconnector(static fn () => throw new RuntimeException('Snapshot connection lost; retry forbidden.'));

            return $this->transaction($db, $work);
        } catch (QueryException|PDOException $e) {
            // Driver messages can contain credentials/DSNs. Keep only the error class and SQLSTATE.
            throw new RuntimeException('Read-only extraction database failure: '.$e::class.' SQLSTATE '.$e->getCode());
        } finally {
            $db?->disconnect();
        }
    }

    public function transaction(Connection $db, callable $work): array
    {
        if ($db->transactionLevel() !== 0) {
            throw new RuntimeException('A fresh extraction transaction is required.');
        }
        $sqlite = app()->runningUnitTests() && $db->getDriverName() === 'sqlite' && $db->getDatabaseName() === ':memory:';
        if ($sqlite) {
            $db->statement('PRAGMA query_only=ON');
            if ((int) $db->selectOne('PRAGMA query_only')->query_only !== 1) {
                throw new RuntimeException('Test connection not read only.');
            }
            $settings = ['test_database' => 'sqlite::memory:', 'read_only' => true];
        } else {
            if ($db->getDriverName() !== 'pgsql') {
                throw new RuntimeException('Only PostgreSQL extraction is supported.');
            }
            $db->statement('SET SESSION CHARACTERISTICS AS TRANSACTION READ ONLY');
            $db->statement("SET lock_timeout = '5s'");
            $db->statement("SET statement_timeout = '5min'");
            $settings = ConnectionGuard::endpoint((array) $db->selectOne("SELECT current_database() AS database, current_schema() AS schema,
                host(inet_server_addr()) AS host, inet_server_port() AS port,
                current_setting('default_transaction_read_only') AS session_read_only,
                current_setting('transaction_read_only') AS transaction_read_only"));
        }
        try {
            $db->beginTransaction();
            if (! $sqlite) {
                $db->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
                $settings += ConnectionGuard::snapshot((array) $db->selectOne("SELECT current_setting('transaction_isolation') AS isolation,
                    current_setting('transaction_read_only') AS snapshot_read_only, pg_current_snapshot()::text AS snapshot"));
                if (! is_string($settings['snapshot']) || $settings['snapshot'] === '') {
                    throw new RuntimeException('Missing database snapshot identity.');
                }
            }
            $work($db, $settings);

            return $settings;
        } finally {
            if ($db->transactionLevel() > 0) {
                $db->rollBack();
            }
        }
    }
}
