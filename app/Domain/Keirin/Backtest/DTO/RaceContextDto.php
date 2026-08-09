<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\DTO;

use DateTimeImmutable;

readonly class RaceContextDto
{
    public function __construct(
        public int $raceId,
        public DateTimeImmutable $raceDate,
        public ?DateTimeImmutable $scheduledStartAt,
        public ?DateTimeImmutable $salesCloseAt,
        public int $entrantCount,
        public string $resultStatus,
    ) {}
}
