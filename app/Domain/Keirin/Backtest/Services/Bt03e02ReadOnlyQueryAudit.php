<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Services;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class Bt03e02ReadOnlyQueryAudit
{
    private bool $active = false;

    private bool $listenerRegistered = false;

    private int $queryCount = 0;

    private int $writeCount = 0;

    private int $forbidden2026Access = 0;

    /** @var array<int, int> */
    private array $snapshotAccess = [];

    /** @var array<int, int> */
    private array $featureAccess = [];

    /** @var array<int, bool> */
    private array $candidateFrozen = [];

    /** @var list<string> */
    private array $accessOrder = [];

    public function start(): void
    {
        if ($this->active) {
            throw new RuntimeException('BT-03E-02 query audit was already active.');
        }
        $this->active = true;
        $this->queryCount = $this->writeCount = $this->forbidden2026Access = 0;
        $this->snapshotAccess = $this->featureAccess = array_fill_keys(Bt03e02Contract::DEVELOPMENT_YEARS, 0);
        $this->snapshotAccess[2026] = $this->featureAccess[2026] = 0;
        $this->candidateFrozen = array_fill_keys(Bt03e02Contract::OUTER_YEARS, false);
        $this->accessOrder = [];
        if ($this->listenerRegistered) {
            return;
        }
        $this->listenerRegistered = true;
        DB::listen(function (QueryExecuted $query): void {
            if (! $this->active) {
                return;
            }
            $this->queryCount++;
            if (preg_match('/\A(?:insert|update|delete|merge|truncate|alter|create|drop)\b/i', ltrim($query->sql)) === 1) {
                $this->writeCount++;
                throw new RuntimeException('BT-03E-02 blocked a database write statement.');
            }
            $audit = $query->sql.' '.json_encode($query->bindings, JSON_THROW_ON_ERROR);
            if (preg_match('/(?<!\d)2026-(?:0[1-9]|1[0-2])-(?:0[1-9]|[12]\d|3[01])(?:[T ][^\s,"\']*)?/', $audit) === 1) {
                $this->forbidden2026Access++;
                throw new RuntimeException('BT-03E-02 blocked 2026 query access.');
            }
        });
    }

    public function recordCandidateFrozen(int $outerYear): void
    {
        if (! $this->active || ! array_key_exists($outerYear, $this->candidateFrozen)) {
            throw new RuntimeException('BT-03E-02 candidate freeze year was invalid.');
        }
        if ($this->candidateFrozen[$outerYear]) {
            throw new RuntimeException("BT-03E-02 Outer {$outerYear} candidate was already frozen.");
        }
        if ($outerYear === 2024
            && (($this->snapshotAccess[2022] ?? 0) < 1 || ($this->snapshotAccess[2023] ?? 0) < 1 || ($this->snapshotAccess[2024] ?? 0) !== 0)) {
            throw new RuntimeException('BT-03E-02 Outer 2024 candidate freeze order was invalid.');
        }
        if ($outerYear === 2025
            && (! $this->candidateFrozen[2024] || ($this->snapshotAccess[2024] ?? 0) < 1 || ($this->snapshotAccess[2025] ?? 0) !== 0)) {
            throw new RuntimeException('BT-03E-02 Outer 2025 candidate freeze order was invalid.');
        }
        $this->candidateFrozen[$outerYear] = true;
        $this->accessOrder[] = "FREEZE_OUTER_{$outerYear}";
    }

    public function recordSnapshotYear(int $year): void
    {
        $this->record($this->snapshotAccess, $year, 'snapshot');
    }

    public function recordFeatureSourceYear(int $year): void
    {
        $this->record($this->featureAccess, $year, 'feature');
    }

    public function active(): bool
    {
        return $this->active;
    }

    /** @return array<string, mixed> */
    public function finish(): array
    {
        if (! $this->active) {
            throw new RuntimeException('BT-03E-02 query audit was not active.');
        }
        $this->active = false;
        if ($this->writeCount !== 0 || $this->forbidden2026Access !== 0
            || ($this->snapshotAccess[2026] ?? 0) !== 0 || ($this->featureAccess[2026] ?? 0) !== 0) {
            throw new RuntimeException('BT-03E-02 read-only or 2026 guard failed.');
        }

        return [
            'query_count' => $this->queryCount,
            'executed_write_query_count' => $this->writeCount,
            '2026_query_or_binding_count' => $this->forbidden2026Access,
            'snapshot_partition_access' => $this->snapshotAccess,
            'feature_source_access' => $this->featureAccess,
            'candidate_frozen' => $this->candidateFrozen,
            'temporal_access_order' => $this->accessOrder,
        ];
    }

    /** @param array<int, int> $target */
    private function record(array &$target, int $year, string $role): void
    {
        if (! $this->active || ! array_key_exists($year, $target) || $year === 2026) {
            throw new RuntimeException("BT-03E-02 {$role} year access was forbidden or invalid.");
        }
        if ($role === 'snapshot' && in_array($year, Bt03e02Contract::OUTER_YEARS, true) && ! $this->candidateFrozen[$year]) {
            throw new RuntimeException("BT-03E-02 {$role} Outer {$year} access preceded candidate freeze.");
        }
        $target[$year]++;
        $this->accessOrder[] = strtoupper($role)."_{$year}";
    }
}
