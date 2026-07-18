<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\DTO;

use App\Domain\Keirin\Scraping\Enums\RaceResultStatus;

readonly class AutomatedRaceResultPageDto
{
    public function __construct(
        public string $raceDate,
        public string $trackCode,
        public int $raceNumber,
        public ?string $lastUpdatedAt,
        public ?string $weather,
        public ?string $windSpeed,
        public ?RaceResultStatus $detectedStatus,
        public string $statusEvidence,
        public ParsedRaceResultPageDto $resultPage,
    ) {}
}
