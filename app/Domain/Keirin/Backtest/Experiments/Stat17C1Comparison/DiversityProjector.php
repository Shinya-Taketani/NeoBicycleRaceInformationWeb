<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\Stat17C1Comparison;

use App\Domain\Keirin\Backtest\Calculators\Bt03e02CompensatedSum;
use RuntimeException;

final class DiversityProjector
{
    public static function project(array $history, string $status): array
    {
        if (! array_is_list($history) || count($history) !== 4) {
            throw new RuntimeException('Expected four ordered history counts.');
        }
        if ($status !== 'AVAILABLE') {
            if (! in_array($status, ['MISSING_TARGET_METADATA', 'LEFT_TRUNCATED', 'INVALID_HISTORY', 'PARTIAL_HISTORY', 'MISSING_METHOD_HISTORY', 'NO_HISTORY'], true)
                || $history !== [null, null, null, null]) {
                throw new RuntimeException('History status/value contradiction.');
            }

            return ['value' => null, 'state' => $status, 'N' => null, 'clamped' => false];
        }
        $total = 0;
        foreach ($history as $count) {
            if (! is_int($count) || $count < 0) {
                throw new RuntimeException('Diversity requires nonnegative integer counts.');
            }
            if ($count > PHP_INT_MAX - $total) {
                throw new RuntimeException('History total integer overflow.');
            }
            $total += $count;
        }
        if ($total === 0) {
            return ['value' => null, 'state' => 'NO_TOP2_METHOD_OBSERVATIONS', 'N' => 0, 'clamped' => false];
        }
        $p = array_map(static fn (int $count): float => $count / $total, $history);
        $sum = new Bt03e02CompensatedSum;
        foreach ([[0, 1], [0, 2], [0, 3], [1, 2], [1, 3], [2, 3]] as [$i, $j]) {
            $sum->add($p[$i] * $p[$j]);
        }
        $bounded = self::bounded((8.0 / 3.0) * $sum->value());

        return ['value' => $bounded['value'], 'state' => 'AVAILABLE', 'N' => $total, 'clamped' => $bounded['clamped']];
    }

    public static function bounded(float $value): array
    {
        if (! is_finite($value) || $value < -Contract::BOUNDARY_TOLERANCE || $value > 1.0 + Contract::BOUNDARY_TOLERANCE) {
            throw new RuntimeException('Diversity calculation was non-finite or out of range.');
        }

        return ['value' => $value <= 0.0 ? 0.0 : min(1.0, $value), 'clamped' => $value < 0.0 || $value > 1.0];
    }
}
