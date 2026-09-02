<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\DTO;

final readonly class Bt03e08FitResultDto
{
    /** @param list<float> $coefficients @param array<string,int|float|string> $diagnostics */
    public function __construct(
        public float $lambda,
        public array $coefficients,
        public float $objective,
        public int $iterations,
        public int $eligibleRaceCount,
        public int $excludedRaceCount,
        public array $diagnostics,
    ) {}
}
