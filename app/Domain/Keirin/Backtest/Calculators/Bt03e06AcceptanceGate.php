<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Calculators;

final class Bt03e06AcceptanceGate
{
    public function __construct(private readonly Bt03e05AcceptanceGate $inner) {}

    /** @param array<int,array<string,mixed>> $outer @param array<string,array{ci_lower:float,ci_upper:float}> $intervals @return array<string,mixed> */
    public function evaluate(array $outer, array $intervals, bool $integrity): array
    {
        return $this->inner->evaluate($outer, $intervals, $integrity);
    }
}
