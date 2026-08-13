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

        return $this->calculateSorted($sorted, $probability);
    }

    /** @param list<float> $sortedValues */
    public function calculateSorted(array $sortedValues, float $probability): float
    {
        if ($sortedValues === [] || $probability < 0 || $probability > 1 || ! is_finite($probability)) {
            throw new InvalidArgumentException('Type-7 quantile input was invalid.');
        }
        $previous = null;
        foreach ($sortedValues as $value) {
            if (! is_finite($value) || ($previous !== null && $value < $previous)) {
                throw new InvalidArgumentException('Type-7 quantile values were not finite and sorted.');
            }
            $previous = $value;
        }
        $position = (count($sortedValues) - 1) * $probability;
        $lower = (int) floor($position);
        $upper = (int) ceil($position);
        if ($lower === $upper) {
            return $sortedValues[$lower];
        }

        return $sortedValues[$lower] + ($position - $lower) * ($sortedValues[$upper] - $sortedValues[$lower]);
    }
}
