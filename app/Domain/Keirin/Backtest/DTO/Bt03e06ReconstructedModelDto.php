<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\DTO;

use App\Domain\Keirin\Backtest\Calculators\Bt03e02ParameterLayout;

readonly class Bt03e06ReconstructedModelDto
{
    public function __construct(
        public int $year,
        public Bt03e02ParameterLayout $layout,
        public Bt03e03FitResultDto $fit,
        public string $canonicalHash,
    ) {}
}
