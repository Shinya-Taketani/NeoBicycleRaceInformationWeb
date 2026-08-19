<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Services;

use Illuminate\Support\Facades\DB;
use RuntimeException;

class Bt03ProductionAdvisoryLock
{
    private bool $held = false;

    public function __construct(private readonly ?string $connectionName = null) {}

    public function acquire(): void
    {
        if ($this->held) {
            throw new RuntimeException('BT-03 Production advisory lock was already held by this executor.');
        }
        $connection = DB::connection($this->connectionName);
        if ($connection->getDriverName() === 'pgsql') {
            $result = $connection->selectOne('SELECT pg_try_advisory_lock(?, ?) AS locked', [
                Bt03ProductionContract::LOCK_CLASS_ID,
                Bt03ProductionContract::LOCK_OBJECT_ID,
            ]);
            if (! $this->databaseBoolean($result->locked ?? false)) {
                throw new RuntimeException('Another BT-03 Production execution is already running.');
            }
        }

        $this->held = true;
    }

    public function release(): void
    {
        if (! $this->held) {
            return;
        }
        $connection = DB::connection($this->connectionName);
        if ($connection->getDriverName() === 'pgsql') {
            $result = $connection->selectOne('SELECT pg_advisory_unlock(?, ?) AS unlocked', [
                Bt03ProductionContract::LOCK_CLASS_ID,
                Bt03ProductionContract::LOCK_OBJECT_ID,
            ]);
            if (! $this->databaseBoolean($result->unlocked ?? false)) {
                throw new RuntimeException('BT-03 Production advisory lock release failed.');
            }
        }

        $this->held = false;
    }

    private function databaseBoolean(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 't', 'true'], true);
    }
}
