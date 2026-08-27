<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Services;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class Bt03e04ReadOnlyQueryAudit
{
    private bool $active = false;

    private bool $listenerRegistered = false;

    private int $queryCount = 0;

    private int $writeCount = 0;

    private int $forbidden2026Access = 0;

    /** @var array<int,int> */
    private array $snapshotAccess = [];

    /** @var array<int,int> */
    private array $baselineAccess = [];

    private bool $decoderFrozen = false;

    private bool $sourceValidated = false;

    /** @var list<string> */
    private array $accessOrder = [];

    public function start(): void
    {
        if ($this->active) {
            throw new RuntimeException('BT-03E-04 query audit was already active.');
        }
        $this->active = true;
        $this->queryCount = $this->writeCount = $this->forbidden2026Access = 0;
        $this->snapshotAccess = $this->baselineAccess = array_fill_keys([2022, 2023, 2024, 2025, 2026], 0);
        $this->decoderFrozen = $this->sourceValidated = false;
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
                throw new RuntimeException('BT-03E-04 blocked a database write statement.');
            }
            $audit = $query->sql.' '.json_encode($query->bindings, JSON_THROW_ON_ERROR);
            if (preg_match('/(?<!\d)2026-(?:0[1-9]|1[0-2])-(?:0[1-9]|[12]\d|3[01])(?:[T ][^\s,"\']*)?/', $audit) === 1) {
                $this->forbidden2026Access++;
                throw new RuntimeException('BT-03E-04 blocked 2026 query access.');
            }
        });
    }

    public function recordDecoderContractFrozen(): void
    {
        if (! $this->active || $this->decoderFrozen) {
            throw new RuntimeException('BT-03E-04 decoder contract freeze order was invalid.');
        }
        $this->decoderFrozen = true;
        $this->accessOrder[] = 'DECODER_CONTRACT_FROZEN';
    }

    public function recordSourceBundleValidated(): void
    {
        if (! $this->active || ! $this->decoderFrozen || $this->sourceValidated) {
            throw new RuntimeException('BT-03E-04 source bundle validation order was invalid.');
        }
        $this->sourceValidated = true;
        $this->accessOrder[] = 'SOURCE_BUNDLE_VALIDATED';
    }

    public function recordSnapshotYear(int $year): void
    {
        $this->record($this->snapshotAccess, $year, 'SNAPSHOT');
    }

    public function recordBaselineYear(int $year): void
    {
        $this->record($this->baselineAccess, $year, 'BASELINE');
    }

    public function active(): bool
    {
        return $this->active;
    }

    /** @return array<string,mixed> */
    public function finish(): array
    {
        if (! $this->active) {
            throw new RuntimeException('BT-03E-04 query audit was not active.');
        }
        $this->active = false;
        if (! $this->decoderFrozen || ! $this->sourceValidated || $this->writeCount !== 0 || $this->forbidden2026Access !== 0
            || $this->snapshotAccess[2022] !== 0 || $this->snapshotAccess[2023] !== 0 || $this->snapshotAccess[2026] !== 0
            || $this->baselineAccess[2022] !== 0 || $this->baselineAccess[2023] !== 0 || $this->baselineAccess[2026] !== 0) {
            throw new RuntimeException('BT-03E-04 read-only, temporal, or 2026 audit failed.');
        }

        return [
            'query_count' => $this->queryCount,
            'executed_write_query_count' => $this->writeCount,
            '2026_query_or_binding_count' => $this->forbidden2026Access,
            'snapshot_partition_access' => $this->snapshotAccess,
            'baseline_feature_access' => $this->baselineAccess,
            'decoder_contract_frozen' => $this->decoderFrozen,
            'source_bundle_validated' => $this->sourceValidated,
            'temporal_access_order' => $this->accessOrder,
        ];
    }

    /** @param array<int,int> $target */
    private function record(array &$target, int $year, string $role): void
    {
        if (! $this->active || ! $this->decoderFrozen || ! $this->sourceValidated
            || ! in_array($year, Bt03e04Contract::DEVELOPMENT_YEARS, true)) {
            throw new RuntimeException("BT-03E-04 {$role} year access was forbidden or invalid.");
        }
        $target[$year]++;
        $this->accessOrder[] = "{$role}_{$year}";
    }
}
