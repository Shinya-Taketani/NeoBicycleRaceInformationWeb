<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\DTO;

readonly class StandardizationModelDto
{
    /** @param array<string, float> $means @param array<string, float> $populationStandardDeviations @param list<string> $zeroVarianceFeatures */
    public function __construct(
        public array $means,
        public array $populationStandardDeviations,
        public array $zeroVarianceFeatures,
    ) {}
}
