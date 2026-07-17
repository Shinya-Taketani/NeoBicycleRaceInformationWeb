<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\DTO;

readonly class PlayerStatsDto
{
    public function __construct(
        public ?float $raceScore,
        public ?float $winRate,
        public ?float $quinellaRate,
        public ?float $trioRate,
        public ?int $homeCount,
        public ?int $backCount,
        public ?int $startCount,
    ) {}
}
