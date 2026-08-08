<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\DTO;

use App\Domain\Keirin\Statistics\Enums\RaceStage;
use DateTimeImmutable;

readonly class Batch03TargetEntryDto
{
    public function __construct(
        public int $raceId,
        public int $raceEntryId,
        public ?int $playerId,
        public int $bikeNumber,
        public DateTimeImmutable $inputAsOf,
        public ?DateTimeImmutable $scheduledStartAt,
        public string $stat01InputHash,
        public ?int $racetrackId,
        public ?int $raceDayId,
        public ?int $raceMeetingId,
        public ?int $dayNumber,
        public ?int $meetingDurationDays,
        public ?string $meetingGrade,
        public ?string $meetingDayKind,
        public ?string $rawRaceType,
        public ?string $rawRaceName,
        public int $entrantCount,
        public RaceStage $normalizedStage,
    ) {}
}
