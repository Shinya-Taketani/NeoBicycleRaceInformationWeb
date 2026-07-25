<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\DTO;

use App\Domain\Keirin\Statistics\Enums\StatisticAcquisitionMode;
use App\Domain\Keirin\Statistics\Enums\StatisticQualityStatus;
use DateTimeImmutable;

final readonly class Stat01EntryResultDto
{
    public function __construct(
        public int $raceEntryId,
        public ?int $playerId,
        public int $bikeNumber,
        public ?float $raceScore,
        public int $validScoreCount,
        public int $missingScoreCount,
        public int $invalidScoreCount,
        public int $entrantCount,
        public ?int $scoreRank,
        public ?int $denseRank,
        public ?float $strengthPercentile,
        public ?float $raceAverageScore,
        public ?float $raceMaxScore,
        public ?float $differenceFromAverage,
        public ?float $differenceFromMax,
        public ?float $raceStandardDeviation,
        public ?float $zScore,
        public StatisticQualityStatus $qualityStatus,
        public StatisticAcquisitionMode $acquisitionMode,
        public ?DateTimeImmutable $sourceFetchedAt,
    ) {}
}
