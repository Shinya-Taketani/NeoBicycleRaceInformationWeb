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

    private int $writeQueryCount = 0;

    private bool $listenerRegistered = false;

    /** @var array<int, int> */
    private array $blockedYearAccess = [2025 => 0, 2026 => 0];

    /** @var array<int, int> */
    private array $snapshotYearAccess = [2023 => 0, 2024 => 0, 2025 => 0, 2026 => 0];

    /** @var array<int, int> */
    private array $featureSourceYearAccess = [2023 => 0, 2024 => 0, 2025 => 0, 2026 => 0];

    public function start(): void
    {
        if ($this->active) {
            throw new RuntimeException('BT-03E query audit was already active.');
        }
        $this->active = true;
        $this->queryCount = 0;
        $this->writeQueryCount = 0;
        $this->blockedYearAccess = [2025 => 0, 2026 => 0];
        $this->snapshotYearAccess = [2023 => 0, 2024 => 0, 2025 => 0, 2026 => 0];
        $this->featureSourceYearAccess = [2023 => 0, 2024 => 0, 2025 => 0, 2026 => 0];
        if ($this->listenerRegistered) {
            return;
        }
        $this->listenerRegistered = true;
        DB::listen(function (QueryExecuted $query): void {
            if (! $this->active) {
                return;
            }
            $this->queryCount++;
            $sql = ltrim($query->sql);
            if (preg_match('/\A(?:insert|update|delete|merge|truncate|alter|create|drop)\b/i', $sql) === 1) {
                $this->writeQueryCount++;
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

    public function recordFeatureSourceYear(int $year): void
    {
        if (! $this->active || ! array_key_exists($year, $this->featureSourceYearAccess)) {
            throw new RuntimeException('BT-03E feature source year audit was invalid.');
        }
        $this->featureSourceYearAccess[$year]++;
    }

    public function active(): bool
    {
        return $this->active;
    }

    public function executedWriteQueryCount(): int
    {
        return $this->writeQueryCount;
    }

    /** @return array<string, mixed> */
    public function finish(): array
    {
        if (! $this->active) {
            throw new RuntimeException('BT-03E query audit was not active.');
        }
        $this->active = false;
        if ($this->writeQueryCount !== 0) {
            throw new RuntimeException('BT-03E detected an executed database write statement.');
        }
        if ($this->blockedYearAccess[2025] !== 0 || $this->blockedYearAccess[2026] !== 0
            || $this->snapshotYearAccess[2025] !== 0 || $this->snapshotYearAccess[2026] !== 0
            || $this->featureSourceYearAccess[2025] !== 0 || $this->featureSourceYearAccess[2026] !== 0) {
            throw new RuntimeException('BT-03E detected forbidden 2025/2026 data access.');
        }

        return [
            'query_count' => $this->queryCount,
            'db_write_count' => $this->writeQueryCount,
            'executed_write_query_count' => $this->writeQueryCount,
            'forbidden_year_query_or_binding_count' => $this->blockedYearAccess,
            'blocked_year_query_or_binding_access' => $this->blockedYearAccess,
            'snapshot_partition_access' => $this->snapshotYearAccess,
            'feature_source_access' => $this->featureSourceYearAccess,
        ];
    }
}
