<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Calculators;

use App\Domain\Keirin\Backtest\Services\Bt03e06Contract;
use App\Domain\Keirin\Backtest\Support\Bt03e06MetricContributionSpool;

final class Bt03e06PairedBootstrap
{
    public function __construct(private readonly Bt03e05PairedBootstrap $inner) {}

    /** @param array<int,Bt03e06MetricContributionSpool> $years @return array<string,array{ci_lower:float,ci_upper:float}> */
    public function evaluate(array $years, int $iterations = Bt03e06Contract::BOOTSTRAP_ITERATIONS): array
    {
        return $this->inner->evaluate(array_map(
            static fn (Bt03e06MetricContributionSpool $spool) => $spool->e05Spool(),
            $years,
        ), $iterations);
    }
}
