<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Services;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class Bt03eReadOnlyQueryAudit
{
    private bool $active = false;

    private int $queryCount = 0;

    /** @var array<int, int> */
    private array $blockedYearAccess = [2025 => 0, 2026 => 0];

    /** @var array<int, int> */
    private array $snapshotYearAccess = [2023 => 0, 2024 => 0, 2025 => 0, 2026 => 0];

    public function start(): void
    {
        if ($this->active) {
            throw new RuntimeException('BT-03E query audit was already active.');
        }
        $this->active = true;
        $this->queryCount = 0;
        $this->blockedYearAccess = [2025 => 0, 2026 => 0];
        $this->snapshotYearAccess = [2023 => 0, 2024 => 0, 2025 => 0, 2026 => 0];
        DB::listen(function (QueryExecuted $query): void {
            if (! $this->active) {
                return;
            }
            $this->queryCount++;
            $sql = ltrim($query->sql);
            if (preg_match('/\A(?:insert|update|delete|merge|truncate|alter|create|drop)\b/i', $sql) === 1) {
                throw new RuntimeException('BT-03E blocked a database write statement.');
            }
            $auditText = $sql.' '.json_encode($query->bindings, JSON_THROW_ON_ERROR);
            foreach ([2025, 2026] as $year) {
                if (preg_match('/(?:^|\D)'.$year.'(?:\D|$)/', $auditText) === 1) {
                    $this->blockedYearAccess[$year]++;
                }
            }
        });
    }

    public function recordSnapshotYear(int $year): void
    {
        if (! $this->active || ! array_key_exists($year, $this->snapshotYearAccess)) {
            throw new RuntimeException('BT-03E snapshot year audit was invalid.');
        }
        $this->snapshotYearAccess[$year]++;
    }

    /** @return array{query_count: int, db_write_count: int, blocked_year_query_or_binding_access: array<int, int>, snapshot_partition_access: array<int, int>} */
    public function finish(): array
    {
        if (! $this->active) {
            throw new RuntimeException('BT-03E query audit was not active.');
        }
        $this->active = false;
        if ($this->blockedYearAccess[2025] !== 0 || $this->blockedYearAccess[2026] !== 0
            || $this->snapshotYearAccess[2025] !== 0 || $this->snapshotYearAccess[2026] !== 0) {
            throw new RuntimeException('BT-03E detected forbidden 2025/2026 data access.');
        }

        return [
            'query_count' => $this->queryCount,
            'db_write_count' => 0,
            'blocked_year_query_or_binding_access' => $this->blockedYearAccess,
            'snapshot_partition_access' => $this->snapshotYearAccess,
        ];
    }
}
