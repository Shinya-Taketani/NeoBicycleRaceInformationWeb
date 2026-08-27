<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Services;

use App\Domain\Keirin\Backtest\Support\Bt02OutcomeContextSnapshotArtifact;

final class Bt03e04OutcomeSnapshotEndVerifier
{
    /** @return array<string,mixed> */
    public function verify(
        Bt02OutcomeContextSnapshotArtifact $snapshot,
        Bt03e04ReadOnlyQueryAudit $queryAudit,
    ): array {
        foreach (Bt03e04Contract::DEVELOPMENT_YEARS as $year) {
            $queryAudit->recordSnapshotYear($year);
            $snapshot->verifyPartition($year);
        }

        return $snapshot->auditParameters();
    }
}
