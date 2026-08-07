<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\Support;

class StatisticalMath
{
    /** @param list<float|int> $values */
    public function mean(array $values): ?float
    {
        return $values === [] ? null : array_sum($values) / count($values);
    }

    /** @param list<float|int> $values */
    public function populationStandardDeviation(array $values): ?float
    {
        $mean = $this->mean($values);
        if ($mean === null) {
            return null;
        }

        return sqrt(array_sum(array_map(
            fn (float|int $value): float => ((float) $value - $mean) ** 2,
            $values,
        )) / count($values));
    }

    /** @param list<float|int> $values */
    public function quantile(array $values, float $probability): ?float
    {
        if ($values === []) {
            return null;
        }
        $sorted = array_map('floatval', $values);
        sort($sorted, SORT_NUMERIC);
        $position = (count($sorted) - 1) * $probability;
        $lower = (int) floor($position);
        $upper = (int) ceil($position);
        if ($lower === $upper) {
            return $sorted[$lower];
        }

        return $sorted[$lower] + ($sorted[$upper] - $sorted[$lower]) * ($position - $lower);
    }

    /** @param list<float|int> $values */
    public function median(array $values): ?float
    {
        return $this->quantile($values, 0.5);
    }

    /** @param list<float|int> $values */
    public function medianAbsoluteDeviation(array $values): ?float
    {
        $median = $this->median($values);
        if ($median === null) {
            return null;
        }

        return $this->median(array_map(
            fn (float|int $value): float => abs((float) $value - $median),
            $values,
        ));
    }

    /** @param list<float|int> $values */
    public function interquartileRange(array $values): ?float
    {
        $q25 = $this->quantile($values, 0.25);
        $q75 = $this->quantile($values, 0.75);

        return $q25 !== null && $q75 !== null ? $q75 - $q25 : null;
    }
}
