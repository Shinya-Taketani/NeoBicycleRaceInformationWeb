<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Calculators;

use InvalidArgumentException;

class Type7Quantile
{
    /** @param list<int|float> $values */
    public function calculate(array $values, float $probability): float
    {
        if ($values === [] || $probability < 0 || $probability > 1 || ! is_finite($probability)) {
            throw new InvalidArgumentException('Type-7 quantile input was invalid.');
        }
        $sorted = array_map('floatval', $values);
        foreach ($sorted as $value) {
            if (! is_finite($value)) {
                throw new InvalidArgumentException('Type-7 quantile value was not finite.');
            }
        }
        sort($sorted, SORT_NUMERIC);
        $position = (count($sorted) - 1) * $probability;
        $lower = (int) floor($position);
        $upper = (int) ceil($position);
        if ($lower === $upper) {
            return $sorted[$lower];
        }

        return $sorted[$lower] + ($position - $lower) * ($sorted[$upper] - $sorted[$lower]);
    }
}
