<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\DTO;

readonly class Bt03BinEffectEntryDto
{
    public function __construct(
        public int $raceEntryId,
        public int $label,
        public float $baselineProbability,
        public float $incrementalProbability,
    ) {}
}
