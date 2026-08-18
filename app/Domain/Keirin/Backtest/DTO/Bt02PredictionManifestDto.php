<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\DTO;

readonly class Bt02PredictionManifestDto
{
    public function __construct(
        public int $rowCount,
        public int $raceCount,
        public string $baselinePredictionManifestSha256,
        public string $incrementalPredictionManifestSha256,
        public string $outcomeManifestSha256,
    ) {}
}
