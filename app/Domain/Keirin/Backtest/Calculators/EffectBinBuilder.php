<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Calculators;

use App\Domain\Keirin\Backtest\DTO\EffectBinDto;
use InvalidArgumentException;

class EffectBinBuilder
{
    public function __construct(private readonly Type7Quantile $quantile) {}

    /** @param list<int|float|string> $trainingValues @return list<EffectBinDto> */
    public function build(array $trainingValues): array
    {
        if ($trainingValues === []) {
            throw new InvalidArgumentException('Effect bins require non-null training values.');
        }
        $unique = array_values(array_unique(array_map(fn (int|float|string $value): string => $this->canonical($value), $trainingValues)));
        if (count($unique) <= 10) {
            sort($unique, SORT_NATURAL);

            return array_map(fn (string $category, int $index): EffectBinDto => new EffectBinDto(
                $index + 1,
                'CATEGORY',
                null,
                null,
                $category,
                count(array_filter($trainingValues, fn (int|float|string $value): bool => $this->canonical($value) === $category)),
            ), $unique, array_keys($unique));
        }
        if (array_filter($trainingValues, fn (mixed $value): bool => ! is_int($value) && ! is_float($value)) !== []) {
            throw new InvalidArgumentException('High-cardinality effect bins must be numeric.');
        }
        $values = array_map('floatval', $trainingValues);
        $boundaries = [];
        for ($decile = 1; $decile <= 9; $decile++) {
            $boundaries[] = $this->quantile->calculate($values, $decile / 10);
        }
        $boundaries = array_values(array_unique($boundaries, SORT_REGULAR));
        sort($boundaries, SORT_NUMERIC);
        $bins = [];
        for ($index = 0; $index <= count($boundaries); $index++) {
            $lower = $index === 0 ? null : $boundaries[$index - 1];
            $upper = $boundaries[$index] ?? null;
            $count = count(array_filter($values, fn (float $value): bool => ($lower === null || $value > $lower) && ($upper === null || $value <= $upper)));
            $bins[] = new EffectBinDto($index + 1, 'NUMERIC_RANGE', $lower, $upper, null, $count);
        }

        return $bins;
    }

    /** @param list<EffectBinDto> $bins */
    public function assign(array $bins, int|float|string|null $value): ?int
    {
        if ($value === null) {
            return null;
        }
        if ($bins === []) {
            throw new InvalidArgumentException('Effect bins were empty.');
        }
        if ($bins[0]->kind === 'CATEGORY') {
            $canonical = $this->canonical($value);
            foreach ($bins as $bin) {
                if ($bin->categoryValue === $canonical) {
                    return $bin->index;
                }
            }

            return 0; // Reserved UNSEEN category.
        }
        if (! is_int($value) && ! is_float($value)) {
            throw new InvalidArgumentException('Numeric effect bin received a non-numeric value.');
        }
        foreach ($bins as $bin) {
            if (($bin->lowerBound === null || $value > $bin->lowerBound) && ($bin->upperBound === null || $value <= $bin->upperBound)) {
                return $bin->index;
            }
        }

        throw new InvalidArgumentException('Value did not match the fixed effect bins.');
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
