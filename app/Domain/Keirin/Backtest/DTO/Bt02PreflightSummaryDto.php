<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\DTO;

readonly class Bt02PreflightSummaryDto
{
    public function __construct(
        public int $verifiedRuns,
        public int $sourceFingerprintMatches,
        public int $contentFingerprintMatches,
        public string $manifestHash,
    ) {}
}
