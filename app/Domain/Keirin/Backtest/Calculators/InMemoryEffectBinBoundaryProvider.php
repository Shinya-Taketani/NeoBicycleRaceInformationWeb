<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Calculators;

use App\Domain\Keirin\Backtest\Contracts\EffectBinBoundaryProvider;
use App\Domain\Keirin\Backtest\DTO\EffectBinDto;
use InvalidArgumentException;

class InMemoryEffectBinBoundaryProvider implements EffectBinBoundaryProvider
{
    public function __construct(private readonly Type7Quantile $quantile) {}

    public function build(iterable $trainingValues): array
    {
        $values = [];
        $categories = [];
        $numeric = true;
        foreach ($trainingValues as $value) {
            if (! is_int($value) && ! is_float($value) && ! is_string($value)) {
                throw new InvalidArgumentException('Effect bin value type was invalid.');
            }
            $values[] = $value;
            $category = $this->canonical($value);
            $categories[$category] = ($categories[$category] ?? 0) + 1;
            $numeric = $numeric && (is_int($value) || is_float($value));
        }
        if ($values === []) {
            throw new InvalidArgumentException('Effect bins require non-null training values.');
        }
        if (count($categories) <= 10) {
            uksort($categories, 'strnatcmp');
            $bins = [];
            $index = 1;
            foreach ($categories as $category => $count) {
                $bins[] = new EffectBinDto($index++, 'CATEGORY', null, null, $category, $count);
            }

            return $bins;
        }
        if (! $numeric) {
            throw new InvalidArgumentException('High-cardinality effect bins must be numeric.');
        }
        $sorted = array_map('floatval', $values);
        sort($sorted, SORT_NUMERIC);
        $boundaries = [];
        for ($decile = 1; $decile <= 9; $decile++) {
            $boundaries[] = $this->quantile->calculateSorted($sorted, $decile / 10);
        }
        $boundaries = array_values(array_unique($boundaries, SORT_REGULAR));
        sort($boundaries, SORT_NUMERIC);
        $counts = array_fill(0, count($boundaries) + 1, 0);
        foreach ($sorted as $value) {
            foreach ($boundaries as $index => $upper) {
                if ($value <= $upper) {
                    $counts[$index]++;

                    continue 2;
                }
            }
            $counts[count($boundaries)]++;
        }
        $bins = [];
        foreach ($counts as $index => $count) {
            $bins[] = new EffectBinDto(
                $index + 1,
                'NUMERIC_RANGE',
                $index === 0 ? null : $boundaries[$index - 1],
                $boundaries[$index] ?? null,
                null,
                $count,
            );
        }

        return $bins;
    }

    private function canonical(int|float|string $value): string
    {
        if (is_float($value)) {
            if (! is_finite($value)) {
                throw new InvalidArgumentException('Effect bin value was not finite.');
            }

            return sprintf('%.17g', $value);
        }

        return (string) $value;
    }
}
