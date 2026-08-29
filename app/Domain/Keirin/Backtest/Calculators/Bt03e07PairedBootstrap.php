<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Calculators;

use App\Domain\Keirin\Backtest\Services\Bt03e07Contract;
use App\Domain\Keirin\Backtest\Support\Bt03e06MetricContributionSpool;

final class Bt03e07PairedBootstrap
{
    public function __construct(private readonly Bt03e05PairedBootstrap $inner) {}

    /** @param array<int,Bt03e06MetricContributionSpool> $years @return array<string,mixed> */
    public function evaluate(array $years, int $iterations = Bt03e07Contract::BOOTSTRAP_ITERATIONS): array
    {
        return $this->inner->evaluate($years, $iterations);
    }
}
