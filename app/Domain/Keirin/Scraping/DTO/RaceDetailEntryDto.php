<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\DTO;

readonly class RaceDetailEntryDto
{
    public function __construct(
        public int $bikeNumber,
        public ?int $frameNumber,
        public string $externalPlayerId,
        public ?string $playerName,
        public ?string $prefecture,
        public ?string $previousGrade,
        public ?string $grade,
        public ?string $ridingStyle,
        public ?string $graduationPeriod,
        public ?int $age,
        public ?string $raceScore,
        public ?int $escapeCount,
        public ?int $sprintCount,
        public ?int $overtakeCount,
        public ?int $markCount,
        public ?int $backCount,
        public ?int $homeCount,
        public ?int $startCount,
        public ?string $winRate,
        public ?string $quinellaRate,
        public ?string $trioRate,
    ) {}
}
