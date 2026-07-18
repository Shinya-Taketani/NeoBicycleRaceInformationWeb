<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\DTO;

readonly class RaceDetailPageDto
{
    /** @param list<RaceDetailEntryDto> $entries */
    public function __construct(
        public string $raceDate,
        public string $trackCode,
        public int $raceNumber,
        public ?string $raceType,
        public ?int $distance,
        public ?int $laps,
        public ?string $raceName,
        public ?string $startTime,
        public ?string $salesCloseTime,
        public array $entries,
    ) {}
}
