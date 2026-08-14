<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\DTO;

readonly class Bt02OutcomeContextSourceRowDto
{
    public function __construct(
        public int $raceId,
        public string $raceDate,
        public ?string $scheduledStartAt,
        public ?string $salesCloseAt,
        public int $entrantCount,
        public string $raceStatus,
        public string $raceType,
        public ?int $bikeNumber,
        public ?int $rank,
        public ?string $resultStatus,
    ) {}
}
