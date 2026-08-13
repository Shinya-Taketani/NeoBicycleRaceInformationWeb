<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Calculators;

use App\Domain\Keirin\Backtest\Contracts\EffectBinBoundaryProvider;
use App\Domain\Keirin\Backtest\DTO\EffectBinDto;
use InvalidArgumentException;

class EffectBinBuilder
{
    public function __construct(private readonly EffectBinBoundaryProvider $boundaryProvider) {}

    /** @param iterable<int|float|string> $trainingValues @return list<EffectBinDto> */
    public function build(iterable $trainingValues): array
    {
        return $this->boundaryProvider->build($trainingValues);
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
