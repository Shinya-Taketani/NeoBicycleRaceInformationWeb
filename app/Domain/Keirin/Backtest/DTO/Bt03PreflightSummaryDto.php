<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\DTO;

readonly class Bt03PreflightSummaryDto
{
    public function __construct(
        public Bt03SourceVerificationDto $source,
        public string $outcomeSnapshotManifestHash,
        public int $baselineFingerprintMatches,
        public Bt02PreflightSummaryDto $bt02,
        public string $sourceManifestHash,
    ) {}
}
