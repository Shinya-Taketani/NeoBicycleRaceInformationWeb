<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\DTO;

use DateTimeImmutable;

readonly class FeatureInputDto
{
    public function __construct(
        public int $id,
        public int $featureRunId,
        public int $raceId,
        public int $raceEntryId,
        public ?int $playerId,
        public int $bikeNumber,
        public string $status,
        public string $qualityStatus,
        public ?DateTimeImmutable $inputAsOf,
        public string $inputHash,
        public ?string $raceScoreRaw,
        public bool $raceScoreAvailable,
        public ?int $raceScoreRank,
    ) {}
}
