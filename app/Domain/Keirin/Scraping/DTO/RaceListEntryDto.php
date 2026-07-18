<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\DTO;

readonly class RaceListEntryDto
{
    public function __construct(
        public int $bikeNumber,
        public string $externalPlayerId,
        public ?string $playerName,
        public ?string $prefecture,
        public ?string $ridingStyle,
    ) {}
}
