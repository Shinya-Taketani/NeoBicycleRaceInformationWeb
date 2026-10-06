<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\Stat39C1FieldBikeComparison;

use App\Domain\Keirin\Backtest\DTO\EffectBinDto;
use InvalidArgumentException;

final class CategoryBins
{
    public static function value(mixed $value): string
    {
        if (! is_string($value) || ! preg_match('/\AN[5-9]_B[1-9]\z/', $value)) {
            throw new InvalidArgumentException('Invalid canonical field/bike category.');
        }

        return $value;
    }

    public static function build(iterable $values): array
    {
        $counts = [];
        $entries = 0;
        foreach ($values as $value) {
            $key = self::value($value);
            $counts[$key] = ($counts[$key] ?? 0) + 1;
            $entries++;
        }
        ksort($counts, SORT_STRING);
        $bins = [];
        foreach ($counts as $value => $support) {
            $bins[] = new EffectBinDto(count($bins) + 1, 'CATEGORY', null, null, $value, $support);
        }
        self::validate($bins, $entries);

        return $bins;
    }

    public static function validate(array $bins, int $entries): void
    {
        if (! array_is_list($bins) || $bins === [] || count($bins) > 45 || $entries < 1) {
            throw new InvalidArgumentException('Field/bike bins require observed training support.');
        }
        $previous = null;
        $sum = 0;
        foreach ($bins as $index => $bin) {
            if (! $bin instanceof EffectBinDto || $bin->index !== $index + 1 || $bin->kind !== 'CATEGORY'
                || $bin->lowerBound !== null || $bin->upperBound !== null || $bin->trainingSampleCount < 1) {
                throw new InvalidArgumentException('Invalid field/bike bin type/support.');
            }
            $value = self::value($bin->categoryValue);
            if ($previous !== null && strcmp($previous, $value) >= 0) {
                throw new InvalidArgumentException('Field/bike bins must be unique and ordered.');
            }
            $sum += $bin->trainingSampleCount;
            $previous = $value;
        }
        if ($sum !== $entries) {
            throw new InvalidArgumentException('Field/bike support did not match training entries.');
        }
    }
}
