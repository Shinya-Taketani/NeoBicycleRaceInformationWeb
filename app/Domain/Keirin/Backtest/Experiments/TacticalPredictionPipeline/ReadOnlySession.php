<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\TacticalPredictionPipeline;

use Illuminate\Support\Facades\DB;
use RuntimeException;

class ReadOnlySession
{
    public function run(callable $work): mixed
    {
        $db = DB::connection();
        if ($db->transactionLevel() !== 0) {
            throw new RuntimeException('An idle connection is required for a fresh source snapshot.');
        }
        $sqlite = $db->getDriverName() === 'sqlite' && app()->runningUnitTests() && $db->getDatabaseName() === ':memory:';
        if ($sqlite) {
            $prior = (int) $db->selectOne('PRAGMA query_only')->query_only;
            $db->statement('PRAGMA query_only=ON');
            $settings = ['test_database' => 'sqlite::memory:', 'query_only' => true];
        } else {
            if ($db->getDriverName() !== 'pgsql') {
                throw new RuntimeException('Read-only PostgreSQL is required.');
            }
            $settings = (array) $db->selectOne("SELECT current_setting('default_transaction_read_only') AS session_read_only, current_setting('transaction_read_only') AS transaction_read_only");
            if ($settings !== ['session_read_only' => 'on', 'transaction_read_only' => 'on']) {
                throw new RuntimeException('Configure READ ONLY before connecting.');
            }
        }
        try {
            $db->beginTransaction();
            if (! $sqlite) {
                $db->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            }

            return $work($settings);
        } finally {
            if ($db->transactionLevel() > 0) {
                $db->rollBack();
            }
            if ($sqlite) {
                $db->statement('PRAGMA query_only='.$prior);
            }
        }
    }
}
