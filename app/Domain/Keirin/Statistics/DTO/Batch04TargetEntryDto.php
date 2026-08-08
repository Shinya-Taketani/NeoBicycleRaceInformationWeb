<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\DTO;

use DateTimeImmutable;

readonly class Batch04TargetEntryDto
{
    public function __construct(
        public int $raceId,
        public int $raceEntryId,
        public ?int $playerId,
        public ?int $bikeNumber,
        public ?int $frameNumber,
        public DateTimeImmutable $inputAsOf,
        public ?DateTimeImmutable $scheduledStartAt,
        public string $stat01InputHash,
        public ?float $stat01RaceScore,
        public ?int $stat01Rank,
        public ?float $stat01StrengthPercentile,
        public ?int $declaredEntrantCount,
        public int $actualEntryCount,
        public ?int $racetrackId,
        /** @var list<int> */
        public array $participatingBikeNumbers,
    ) {}
}
