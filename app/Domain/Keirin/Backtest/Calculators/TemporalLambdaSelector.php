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
        if (count($validationLogLossByLambda) !== count(RidgeLogisticRegression::LAMBDA_CANDIDATES)) {
            throw new InvalidArgumentException('Lambda selection requires every fixed candidate exactly once.');
        }
        $losses = [];
        foreach ($validationLogLossByLambda as $lambda => $loss) {
            $numericLambda = (float) $lambda;
            if (! in_array($numericLambda, RidgeLogisticRegression::LAMBDA_CANDIDATES, true)
                || isset($losses[$this->key($numericLambda)])
                || ! is_finite($loss)) {
                throw new InvalidArgumentException('Lambda selection input was outside the fixed contract.');
            }
            $losses[$this->key($numericLambda)] = $loss;
        }
        foreach (RidgeLogisticRegression::LAMBDA_CANDIDATES as $candidate) {
            if (! array_key_exists($this->key($candidate), $losses)) {
                throw new InvalidArgumentException('Lambda selection was missing a fixed candidate.');
            }
        }
        $minimum = min($losses);
        $selected = null;
        foreach (RidgeLogisticRegression::LAMBDA_CANDIDATES as $candidate) {
            if ($losses[$this->key($candidate)] <= $minimum + self::TIE_TOLERANCE) {
                $selected = $candidate;
            }
        }

        return $selected ?? throw new InvalidArgumentException('Lambda selection had no eligible candidate.');
    }

    private function key(float $lambda): string
    {
        return sprintf('%.4g', $lambda);
    }
}
