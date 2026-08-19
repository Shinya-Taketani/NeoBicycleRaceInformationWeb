<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\DTO;

readonly class Bt03ProductionProgressDto
{
    public function __construct(
        public int $ordinal,
        public int $scopeCount,
        public string $foldCode,
        public string $statCode,
        public string $cohortCode,
        public string $status,
        public int $effectCount,
        public int $evaluationRowCount,
        public int $evaluationRaceCount,
        public int $unseenRowCount,
        public float $elapsedSeconds,
    ) {}
}
