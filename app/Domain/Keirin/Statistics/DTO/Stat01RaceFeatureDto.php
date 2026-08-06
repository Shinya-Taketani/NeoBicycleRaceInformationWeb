<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\DTO;

readonly class Stat01RaceFeatureDto
{
    /** @param list<Stat01EntryFeatureDto> $entries */
    public function __construct(
        public int $raceId,
        public array $entries,
        public bool $partial,
    ) {}
}
