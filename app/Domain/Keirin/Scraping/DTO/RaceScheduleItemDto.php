<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\DTO;

use DateTimeImmutable;

readonly class RaceScheduleItemDto
{
    public function __construct(
        public string $trackCode,
        public string $trackName,
        public DateTimeImmutable $startsOn,
        public int $durationDays,
        public ?string $grade,
        public ?string $raceListUrl,
        public ?string $encryptedParameter,
        public ?string $dayKind,
    ) {}
}
