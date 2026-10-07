<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\C1PositionLambda;

final readonly class Fit
{
    public function __construct(public array $lambdaByPosition, public array $coefficients, public array $objectives,
        public array $iterations, public array $eligibleRaceCounts, public array $excludedRaceCounts, public array $diagnostics) {}
}
