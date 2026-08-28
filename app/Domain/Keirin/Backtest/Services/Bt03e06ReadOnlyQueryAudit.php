<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Services;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class Bt03e06ReadOnlyQueryAudit
{
    private bool $active = false;

    private bool $listenerRegistered = false;

    private int $queryCount = 0;

    private int $writeCount = 0;

    private int $forbidden2026Access = 0;

    /** @var array<int,int> */
    private array $featureAccess = [];

    /** @var array<int,int> */
    private array $snapshotAccess = [];

    /** @var array<int,bool> */
    private array $candidateManifestSealed = [];

    private bool $contractFrozen = false;

    private bool $sourceValidated = false;

    /** @var list<string> */
    private array $accessOrder = [];

    public function start(): void
    {
        if ($this->active) {
            throw new RuntimeException('BT-03E-06 query audit was already active.');
        }
        $this->active = true;
        $this->queryCount = $this->writeCount = $this->forbidden2026Access = 0;
        $this->featureAccess = $this->snapshotAccess = array_fill_keys([2022, 2023, 2024, 2025, 2026], 0);
        $this->candidateManifestSealed = array_fill_keys(Bt03e06Contract::DEVELOPMENT_YEARS, false);
        $this->contractFrozen = $this->sourceValidated = false;
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
                throw new RuntimeException('BT-03E-06 blocked a database write statement.');
            }
            $evidence = $query->sql.' '.json_encode($query->bindings, JSON_THROW_ON_ERROR);
            if (preg_match('/(?<!\d)2026-(?:0[1-9]|1[0-2])-(?:0[1-9]|[12]\d|3[01])(?:[T ][^\s,"\']*)?/', $evidence) === 1) {
                $this->forbidden2026Access++;
                throw new RuntimeException('BT-03E-06 blocked 2026 query access.');
            }
        });
    }

    public function recordContractFrozen(): void
    {
        if (! $this->active || $this->contractFrozen) {
            throw new RuntimeException('BT-03E-06 contract freeze order was invalid.');
        }
        $this->contractFrozen = true;
        $this->accessOrder[] = 'CONTRACT_FROZEN';
    }

    public function recordSourceBundleValidated(): void
    {
        if (! $this->active || ! $this->contractFrozen || $this->sourceValidated) {
            throw new RuntimeException('BT-03E-06 source bundle validation order was invalid.');
        }
        $this->sourceValidated = true;
        $this->accessOrder[] = 'SOURCE_BUNDLE_VALIDATED';
    }

    public function recordFeatureSourceYear(int $year): void
    {
        if (! $this->ready() || ! in_array($year, Bt03e06Contract::DEVELOPMENT_YEARS, true)) {
            throw new RuntimeException('BT-03E-06 feature year access was forbidden or invalid.');
        }
        $this->featureAccess[$year]++;
        $this->accessOrder[] = "FEATURE_{$year}";
    }

    public function recordCandidateManifestSealed(int $year): void
    {
        if (! $this->ready() || ! in_array($year, Bt03e06Contract::DEVELOPMENT_YEARS, true)
            || $this->candidateManifestSealed[$year] || $this->featureAccess[$year] < 1) {
            throw new RuntimeException('BT-03E-06 candidate manifest seal order was invalid.');
        }
        $this->candidateManifestSealed[$year] = true;
        $this->accessOrder[] = "CANDIDATE_MANIFEST_{$year}_SEALED";
    }

    public function recordSnapshotYear(int $year): void
    {
        if (! $this->ready() || ! in_array($year, Bt03e06Contract::DEVELOPMENT_YEARS, true)
            || in_array(false, $this->candidateManifestSealed, true)) {
            throw new RuntimeException('BT-03E-06 outcome access before all candidate manifests were sealed.');
        }
        $this->snapshotAccess[$year]++;
        $this->accessOrder[] = "SNAPSHOT_{$year}";
    }

    public function active(): bool
    {
        return $this->active;
    }

    /** @return array<string,mixed> */
    public function finish(): array
    {
        if (! $this->active) {
            throw new RuntimeException('BT-03E-06 query audit was not active.');
        }
        $this->active = false;
        if (! $this->contractFrozen || ! $this->sourceValidated
            || in_array(false, $this->candidateManifestSealed, true)
            || $this->writeCount !== 0 || $this->forbidden2026Access !== 0
            || $this->featureAccess[2022] !== 0 || $this->featureAccess[2023] !== 0 || $this->featureAccess[2026] !== 0
            || $this->snapshotAccess[2022] !== 0 || $this->snapshotAccess[2023] !== 0 || $this->snapshotAccess[2026] !== 0
            || $this->featureAccess[2024] < 1 || $this->featureAccess[2025] < 1
            || $this->snapshotAccess[2024] < 1 || $this->snapshotAccess[2025] < 1) {
            throw new RuntimeException('BT-03E-06 read-only, temporal, or 2026 audit failed.');
        }

        return [
            'query_count' => $this->queryCount,
            'executed_write_query_count' => $this->writeCount,
            '2026_query_or_binding_count' => $this->forbidden2026Access,
            'feature_partition_access' => $this->featureAccess,
            'snapshot_partition_access' => $this->snapshotAccess,
            'candidate_manifest_sealed' => $this->candidateManifestSealed,
            'contract_frozen' => $this->contractFrozen,
            'source_bundle_validated' => $this->sourceValidated,
            'prediction_before_outcome_verified' => true,
            'temporal_access_order' => $this->accessOrder,
        ];
    }

    private function ready(): bool
    {
        return $this->active && $this->contractFrozen && $this->sourceValidated;
    }
}
