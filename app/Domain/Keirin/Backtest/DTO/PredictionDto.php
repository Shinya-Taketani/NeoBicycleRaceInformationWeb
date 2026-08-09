<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\DTO;

readonly class PredictionDto
{
    public function __construct(
        public int $raceId,
        public int $raceEntryId,
        public ?int $playerId,
        public int $bikeNumber,
        public int $featureRunId,
        public int $featureResultId,
        public string $sourceInputHash,
        public string $predictionScore,
        public int $predictedRank,
        public bool $isRank1Set,
        public bool $isTop3Set,
        public string $predictionHash,
    ) {}
}
