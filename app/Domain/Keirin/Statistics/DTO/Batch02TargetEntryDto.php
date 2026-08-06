<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\DTO;

use DateTimeImmutable;

readonly class Batch02TargetEntryDto
{
    public function __construct(
        public int $raceId,
        public int $raceEntryId,
        public ?int $playerId,
        public int $bikeNumber,
        public DateTimeImmutable $inputAsOf,
        public string $stat01InputHash,
        public ?int $targetMeetingId,
    ) {}
}
