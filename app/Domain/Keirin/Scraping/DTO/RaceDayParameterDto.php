<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\DTO;

readonly class RaceDayParameterDto
{
    public function __construct(
        public string $raceDate,
        public ?string $dayLabel,
        public string $encryptedParameter,
    ) {}
}
