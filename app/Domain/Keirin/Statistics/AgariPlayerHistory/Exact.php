<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\AgariPlayerHistory;

use Brick\Math\BigRational;
use Brick\Math\RoundingMode;

final class Exact
{
    public static function value(?BigRational $value): ?array
    {
        return $value === null ? null : ['numerator' => (string) $value->getNumerator(),
            'denominator' => (string) $value->getDenominator(), 'decimal' => (string) $value->toScale(12, RoundingMode::HalfEven)];
    }

    public static function rational(array $value): BigRational
    {
        return BigRational::ofFraction($value['numerator'], $value['denominator']);
    }

    public static function statistics(array $values): array
    {
        if ($values === []) {
            return ['mean' => null, 'median' => null, 'population_variance' => null];
        }
        $values = array_map(self::rational(...), $values);
        usort($values, fn (BigRational $a, BigRational $b) => $a->compareTo($b));
        $n = count($values);
        $sum = BigRational::zero();
        foreach ($values as $value) {
            $sum = $sum->plus($value);
        }
        $mean = $sum->dividedBy($n);
        $middle = intdiv($n, 2);
        $median = $n % 2 ? $values[$middle] : $values[$middle - 1]->plus($values[$middle])->dividedBy(2);
        $variance = null;
        if ($n >= 2) {
            $variance = BigRational::zero();
            foreach ($values as $value) {
                $variance = $variance->plus($value->minus($mean)->power(2));
            }
            $variance = $variance->dividedBy($n);
        }

        return ['mean' => self::value($mean), 'median' => self::value($median), 'population_variance' => self::value($variance)];
    }
}
