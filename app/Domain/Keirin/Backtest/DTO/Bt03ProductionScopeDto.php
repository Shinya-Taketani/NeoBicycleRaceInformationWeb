<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\DTO;

readonly class Bt03ProductionScopeDto
{
    public function __construct(
        public int $ordinal,
        public string $foldCode,
        public string $statCode,
        public string $cohortCode,
    ) {}
}
