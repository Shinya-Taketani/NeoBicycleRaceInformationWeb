<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\DTO;

use DateTimeImmutable;

readonly class Batch05TargetEntryDto
{
    public function __construct(
        public int $raceEntryId,
        public ?int $playerId,
        public ?int $bikeNumber,
        public DateTimeImmutable $inputAsOf,
        public string $stat01InputHash,
        public string $stat01Status,
        public string $stat01QualityStatus,
        public mixed $raceScoreRaw,
        public mixed $raceScoreAvailable,
        public mixed $expectedEntrantCount,
        public ?DateTimeImmutable $sourceFetchedAt,
        public ?string $stat01RaceInputHash = null,
        public mixed $stat01Rank = null,
        public mixed $stat01DenseRank = null,
        public mixed $stat01StrengthPercentile = null,
    ) {}
}
