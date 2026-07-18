<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\DTO;

readonly class RaceDayMetadataPageDto
{
    /**
     * @param  list<RaceDayParameterDto>  $days
     * @param  list<RaceParameterDto>  $races
     */
    public function __construct(
        public string $selectedDate,
        public string $trackCode,
        public int $selectedRaceNumber,
        public ?string $meetingName,
        public ?string $trackName,
        public ?string $grade,
        public array $days,
        public array $races,
    ) {}
}
