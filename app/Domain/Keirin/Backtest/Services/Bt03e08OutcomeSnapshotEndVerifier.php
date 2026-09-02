<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Services;

use App\Domain\Keirin\Backtest\Support\Bt02OutcomeContextSnapshotArtifact;

final class Bt03e08OutcomeSnapshotEndVerifier
{
    /** @return array<string,mixed> */
    public function verify(Bt02OutcomeContextSnapshotArtifact $snapshot, Bt03e08ReadOnlyQueryAudit $audit): array
    {
        foreach (Bt03e08Contract::DEVELOPMENT_YEARS as $year) {
            $audit->recordSnapshotYear($year);
            $snapshot->verifyPartition($year);
        }

        return $snapshot->auditParameters();
    }
}
