<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Services;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class Bt03eReadOnlyDatabaseGuard
{
    private ?Connection $connection = null;

    private bool $active = false;

    private bool $databaseEnforced = false;

    public function begin(): void
    {
        if ($this->active) {
            throw new RuntimeException('BT-03E read-only database guard was already active.');
        }
        $this->databaseEnforced = false;
        $connection = DB::connection();
        if ($connection->transactionLevel() !== 0) {
            throw new RuntimeException('BT-03E requires ownership of the database transaction.');
        }
        if ($connection->getDriverName() !== 'pgsql' && ! app()->environment('testing')) {
            throw new RuntimeException('BT-03E production execution requires PostgreSQL READ ONLY enforcement.');
        }

        $connection->beginTransaction();
        try {
            if ($connection->getDriverName() === 'pgsql') {
                $connection->statement('SET TRANSACTION READ ONLY');
                $setting = $connection->selectOne('SHOW transaction_read_only');
                if (($setting->transaction_read_only ?? null) !== 'on') {
                    throw new RuntimeException('BT-03E could not verify the PostgreSQL READ ONLY transaction.');
                }
                $this->databaseEnforced = true;
            }
            $this->connection = $connection;
            $this->active = true;
        } catch (Throwable $throwable) {
            $connection->rollBack();
            throw $throwable;
        }
    }

    /** @return array{db_read_only_transaction: bool, db_transaction_rolled_back: bool} */
    public function rollback(): array
    {
        if (! $this->active || $this->connection === null) {
            throw new RuntimeException('BT-03E read-only database guard was not active.');
        }
        $this->connection->rollBack();
        $this->active = false;
        $this->connection = null;

        return [
            'db_read_only_transaction' => $this->databaseEnforced,
            'db_transaction_rolled_back' => true,
        ];
    }

    public function active(): bool
    {
        return $this->active;
    }
}
