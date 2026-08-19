<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\DTO;

readonly class Bt03CenteredResidualEntryDto
{
    public function __construct(
        public int $raceEntryId,
        public int $binIndex,
        public int $label,
        public float $baselineProbability,
    ) {}
}
