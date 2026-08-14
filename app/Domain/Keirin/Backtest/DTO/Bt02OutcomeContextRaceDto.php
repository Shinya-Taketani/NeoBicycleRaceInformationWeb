<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\DTO;

readonly class Bt02OutcomeContextRaceDto
{
    /** @param list<LabelResultDto> $results */
    public function __construct(
        public RaceContextDto $context,
        public string $raceType,
        public array $results,
    ) {}
}
