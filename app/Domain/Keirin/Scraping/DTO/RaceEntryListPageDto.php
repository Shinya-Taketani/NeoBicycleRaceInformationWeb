<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\DTO;

readonly class RaceEntryListPageDto
{
    /** @param list<RaceListRaceDto> $races */
    public function __construct(
        public string $trackCode,
        public string $raceDate,
        public ?string $lastUpdatedAt,
        public array $races,
    ) {}
}
