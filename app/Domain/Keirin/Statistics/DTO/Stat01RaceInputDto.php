<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\DTO;

use DateTimeImmutable;

readonly class Stat01RaceInputDto
{
    /** @param list<Stat01EntryInputDto> $entries */
    public function __construct(
        public int $id,
        public DateTimeImmutable $raceDate,
        public string $raceType,
        public int $entrantCount,
        public ?DateTimeImmutable $salesCloseAt,
        public ?DateTimeImmutable $scheduledStartAt,
        public array $entries,
    ) {}
}
