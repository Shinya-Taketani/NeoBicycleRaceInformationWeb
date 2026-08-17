<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\DTO;

readonly class Bt03SourceVerificationDto
{
    public function __construct(
        public int $sourceRunId,
        public int $foldCount,
        public int $signalSpecCount,
        public int $modelCount,
        public int $metricCount,
        public int $effectBinCount,
        public int $objectiveVersionMatches,
        public int $optimizerVersionMatches,
        public Bt03SourceArtifactFingerprintsDto $fingerprints,
        public string $outcomeSnapshotPath,
    ) {}
}
