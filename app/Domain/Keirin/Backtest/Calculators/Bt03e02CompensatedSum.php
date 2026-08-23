<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Calculators;

use RuntimeException;

final class Bt03e02CompensatedSum
{
    private float $sum = 0.0;

    private float $compensation = 0.0;

    public function add(float $value): void
    {
        if (! is_finite($value)) {
            throw new RuntimeException('BT-03E-02 refused a non-finite summand.');
        }
        $next = $this->sum + $value;
        $this->compensation += abs($this->sum) >= abs($value)
            ? ($this->sum - $next) + $value
            : ($value - $next) + $this->sum;
        $this->sum = $next;
    }

    public function value(): float
    {
        $value = $this->sum + $this->compensation;
        if (! is_finite($value)) {
            throw new RuntimeException('BT-03E-02 compensated sum was non-finite.');
        }

        return $value === 0.0 ? 0.0 : $value;
    }
}
