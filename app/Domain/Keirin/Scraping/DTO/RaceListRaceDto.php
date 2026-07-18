<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\DTO;

use App\Domain\Keirin\Scraping\Enums\RaceCategory;

readonly class RaceListRaceDto
{
    /** @param list<RaceListEntryDto> $entries */
    public function __construct(
        public int $raceNumber,
        public ?string $raceType,
        public ?string $salesCloseTime,
        public ?string $startTime,
        public bool $resultAvailable,
        public RaceCategory $category,
        public array $entries,
    ) {}
}
