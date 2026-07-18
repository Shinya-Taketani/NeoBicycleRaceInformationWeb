<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\DTO;

readonly class RaceParameterDto
{
    public function __construct(
        public string $encryptedParameter,
        public bool $raceEnded,
        public bool $resultAvailable,
    ) {}
}
