<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\DTO;

readonly class Bt03e02FitResultDto
{
    /**
     * @param  array<string, list<float>>  $coefficients
     * @param  array<string, float>  $objectives
     * @param  array<string, int>  $iterations
     * @param  array<string, int>  $eligibleRaceCounts
     * @param  array<string, int>  $excludedRaceCounts
     * @param  array<string, array<string, int|float|string>>  $diagnostics
     */
    public function __construct(
        public float $lambda,
        public array $coefficients,
        public array $objectives,
        public array $iterations,
        public array $eligibleRaceCounts,
        public array $excludedRaceCounts,
        public array $diagnostics = [],
    ) {}
}
