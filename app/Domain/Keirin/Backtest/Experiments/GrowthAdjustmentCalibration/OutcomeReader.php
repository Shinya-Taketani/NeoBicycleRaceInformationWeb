<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\GrowthAdjustmentCalibration;

use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\JsonlArtifact;
use Generator;

class OutcomeReader
{
    public function read(int $year, string $kind, string $path, TemporalAccess $access): Generator
    {
        // Authorize before even opening the sidecar or creating the underlying reader.
        $access->opening($year, $kind);
        yield from JsonlArtifact::read($path);
    }
}
