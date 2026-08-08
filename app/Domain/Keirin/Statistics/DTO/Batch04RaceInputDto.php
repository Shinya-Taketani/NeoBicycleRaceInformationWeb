<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\DTO;

use DateTimeImmutable;

readonly class Batch04RaceInputDto
{
    /** @param list<Batch04TargetEntryDto> $entries */
    public function __construct(
        public int $raceId,
        public DateTimeImmutable $inputAsOf,
        public array $entries,
    ) {}
}
