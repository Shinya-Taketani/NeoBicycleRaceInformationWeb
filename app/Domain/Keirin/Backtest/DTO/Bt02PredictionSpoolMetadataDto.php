<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\DTO;

readonly class Bt02PredictionSpoolMetadataDto
{
    public function __construct(
        public string $formatVersion,
        public int $rowCount,
        public int $raceCount,
        public int $byteCount,
        public string $fileSha256,
        public string $baselinePredictionManifestSha256,
        public string $incrementalPredictionManifestSha256,
        public string $outcomeManifestSha256,
    ) {}
}
