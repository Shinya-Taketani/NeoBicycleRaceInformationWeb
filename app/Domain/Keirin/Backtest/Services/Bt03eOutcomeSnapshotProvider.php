<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Services;

use App\Domain\Keirin\Backtest\Support\Bt02OutcomeContextSnapshotArtifact;

class Bt03eOutcomeSnapshotProvider
{
    public function open(string $storagePath, string $auditPath): Bt02OutcomeContextSnapshotArtifact
    {
        return Bt02OutcomeContextSnapshotArtifact::open($storagePath, $auditPath);
    }
}
