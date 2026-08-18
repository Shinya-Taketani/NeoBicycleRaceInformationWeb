<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\DTO;

readonly class Bt03ExpectedPredictionManifestsDto
{
    public function __construct(
        public string $baselinePredictionManifestHash,
        public string $incrementalPredictionManifestHash,
        public string $outcomeManifestHash,
    ) {}
}
