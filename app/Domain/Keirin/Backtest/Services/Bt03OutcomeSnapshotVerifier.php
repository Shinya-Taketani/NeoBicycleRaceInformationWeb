<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Services;

use App\Domain\Keirin\Backtest\Support\Bt02OutcomeContextSnapshotArtifact;
use RuntimeException;

class Bt03OutcomeSnapshotVerifier
{
    public function verify(string $auditPath): string
    {
        if (! str_ends_with($auditPath, '/'.Bt03SourceManifest::OUTCOME_SNAPSHOT_MANIFEST_HASH)) {
            throw new RuntimeException('BT-03 outcome snapshot audit path did not match the fixed manifest.');
        }
        $snapshot = Bt02OutcomeContextSnapshotArtifact::open(storage_path('app/'.$auditPath), $auditPath);
        if (! hash_equals(Bt03SourceManifest::OUTCOME_SNAPSHOT_MANIFEST_HASH, $snapshot->manifestHash())) {
            throw new RuntimeException('BT-03 outcome snapshot manifest hash mismatched.');
        }
        $snapshot->verify();

        return $snapshot->manifestHash();
    }
}
