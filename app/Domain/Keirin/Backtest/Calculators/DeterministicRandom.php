<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Calculators;

use InvalidArgumentException;

class DeterministicRandom
{
    private int $state;

    public function __construct(int $seed)
    {
        $this->state = $seed % 2147483647;
        if ($this->state <= 0) {
            $this->state += 2147483646;
        }
    }

    public function integer(int $upperExclusive): int
    {
        if ($upperExclusive < 1) {
            throw new InvalidArgumentException('Random upper bound must be positive.');
        }
        $this->state = (int) (($this->state * 16807) % 2147483647);

        return $this->state % $upperExclusive;
    }
}
