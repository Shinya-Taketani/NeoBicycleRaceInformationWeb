<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Calculators;

use InvalidArgumentException;

class TemporalLambdaSelector
{
    public const TIE_TOLERANCE = 1e-12;

    /** @param array<string, float> $validationLogLossByLambda */
    public function select(array $validationLogLossByLambda): float
    {
        if ($validationLogLossByLambda === []) {
            throw new InvalidArgumentException('Lambda selection requires validation losses.');
        }
        $candidates = [];
        foreach ($validationLogLossByLambda as $lambda => $loss) {
            $numericLambda = (float) $lambda;
            if (! in_array($numericLambda, RidgeLogisticRegression::LAMBDA_CANDIDATES, true) || ! is_finite($loss)) {
                throw new InvalidArgumentException('Lambda selection input was outside the fixed contract.');
            }
            $candidates[] = ['lambda' => $numericLambda, 'loss' => $loss];
        }
        usort($candidates, function (array $left, array $right): int {
            if (abs($left['loss'] - $right['loss']) <= self::TIE_TOLERANCE) {
                return $right['lambda'] <=> $left['lambda'];
            }

            return $left['loss'] <=> $right['loss'];
        });

        return $candidates[0]['lambda'];
    }
}
