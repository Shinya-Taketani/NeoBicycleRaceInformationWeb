<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Calculators;

use InvalidArgumentException;

class BinaryMetricCalculator
{
    public const LOG_LOSS_EPSILON = 1e-15;

    /** @param list<float> $probabilities @param list<int> $labels */
    public function logLoss(array $probabilities, array $labels): float
    {
        $this->assertRows($probabilities, $labels);
        $sum = 0.0;
        foreach ($probabilities as $index => $probability) {
            $p = min(max($probability, self::LOG_LOSS_EPSILON), 1.0 - self::LOG_LOSS_EPSILON);
            $sum -= $labels[$index] * log($p) + (1 - $labels[$index]) * log(1.0 - $p);
        }

        return $sum / count($labels);
    }

    /** @param list<float> $probabilities @param list<int> $labels */
    public function brier(array $probabilities, array $labels): float
    {
        $this->assertRows($probabilities, $labels);
        $sum = 0.0;
        foreach ($probabilities as $index => $probability) {
            $sum += ($probability - $labels[$index]) ** 2;
        }

        return $sum / count($labels);
    }

    /** @param list<float> $scores @param list<int> $labels */
    public function auc(array $scores, array $labels): ?float
    {
        $this->assertRows($scores, $labels);
        $positives = array_sum($labels);
        $negatives = count($labels) - $positives;
        if ($positives === 0 || $negatives === 0) {
            return null;
        }
        $pairs = [];
        foreach ($scores as $index => $score) {
            $pairs[] = [$score, $labels[$index]];
        }
        usort($pairs, fn (array $left, array $right): int => $left[0] <=> $right[0]);
        $rank = 1;
        $positiveRankSum = 0.0;
        for ($index = 0; $index < count($pairs);) {
            $end = $index + 1;
            while ($end < count($pairs) && $pairs[$end][0] === $pairs[$index][0]) {
                $end++;
            }
            $averageRank = ($rank + ($rank + $end - $index - 1)) / 2;
            for ($cursor = $index; $cursor < $end; $cursor++) {
                if ($pairs[$cursor][1] === 1) {
                    $positiveRankSum += $averageRank;
                }
            }
            $rank += $end - $index;
            $index = $end;
        }

        return ($positiveRankSum - $positives * ($positives + 1) / 2) / ($positives * $negatives);
    }

    /** @param list<float> $scores @param list<int> $labels */
    private function assertRows(array $scores, array $labels): void
    {
        if ($scores === [] || count($scores) !== count($labels)) {
            throw new InvalidArgumentException('Binary metric rows were empty or mismatched.');
        }
        foreach ($scores as $score) {
            if (! is_finite($score)) {
                throw new InvalidArgumentException('Binary metric score was not finite.');
            }
        }
        foreach ($labels as $label) {
            if (! in_array($label, [0, 1], true)) {
                throw new InvalidArgumentException('Binary metric label was not binary.');
            }
        }
    }
}
