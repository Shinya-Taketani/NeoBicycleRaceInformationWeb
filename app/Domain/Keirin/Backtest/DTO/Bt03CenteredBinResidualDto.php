<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\DTO;

readonly class Bt03CenteredBinResidualDto
{
    public function __construct(
        public float $overallBaselineResidualMean,
        public ?float $centeredBaselineResidualMean,
        public ?float $centeredBaselineResidualCiLower,
        public ?float $centeredBaselineResidualCiUpper,
        public string $centeredCiStatus,
        public int $centeredBootstrapValidIterations,
    ) {}
}
