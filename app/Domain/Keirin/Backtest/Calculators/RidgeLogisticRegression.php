<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Calculators;

use App\Domain\Keirin\Backtest\Contracts\LogisticTrainingRowSource;
use App\Domain\Keirin\Backtest\DTO\LogisticTrainingRowDto;
use App\Domain\Keirin\Backtest\DTO\RidgeLogisticFitResultDto;
use App\Domain\Keirin\Backtest\Enums\Bt02ConvergenceStatus;
use InvalidArgumentException;
use RuntimeException;

class RidgeLogisticRegression
{
    public const OBJECTIVE_VERSION = 'RIDGE-LOGISTIC-MEAN-LOSS-NEUMAIER-v2';

    public const OPTIMIZER_VERSION = 'DAMPED-NEWTON-CHOLESKY-v3';

    public const MAX_ITERATIONS = 100;

    public const ARMIJO = 1e-4;

    public const GRADIENT_TOLERANCE = 1e-8;

    public const STEP_TOLERANCE = 1e-8;

    public const OBJECTIVE_TOLERANCE = 1e-10;

    private const OBJECTIVE_ROUNDOFF_ULPS = 4.0;

    /** @var list<float> */
    public const LAMBDA_CANDIDATES = [1e-4, 1e-3, 1e-2, 1e-1, 1.0, 10.0, 100.0];

    public function fit(LogisticTrainingRowSource $source, float $lambda): RidgeLogisticFitResultDto
    {
        $this->validateLambda($lambda);
        $summary = $this->inspect($source);
        if ($summary['positive'] === 0 || $summary['positive'] === $summary['count']) {
            return $this->failed(Bt02ConvergenceStatus::FailedSingleClassTraining, $summary['dimension']);
        }
        $parameters = array_fill(0, $summary['dimension'] + 1, 0.0);
        $previousObjective = $this->objectiveFor($parameters, $source, $lambda, $summary);
        for ($iteration = 1; $iteration <= self::MAX_ITERATIONS; $iteration++) {
            [$gradient, $hessian] = $this->derivatives($parameters, $source, $lambda, $summary);
            if (! $this->finite($gradient) || ! $this->finiteMatrix($hessian) || ! is_finite($previousObjective)) {
                return $this->failed(Bt02ConvergenceStatus::FailedNonFinite, $summary['dimension'], $iteration, $previousObjective);
            }
            if (max(array_map('abs', $gradient)) <= self::GRADIENT_TOLERANCE) {
                return $this->success(Bt02ConvergenceStatus::ConvergedGradient, $parameters, $iteration - 1, $previousObjective);
            }
            $direction = $this->choleskySolve($hessian, array_map(fn (float $value): float => -$value, $gradient));
            if ($direction === null) {
                return $this->failed(Bt02ConvergenceStatus::FailedCholesky, $summary['dimension'], $iteration, $previousObjective);
            }
            $directionalDerivative = $this->dot($gradient, $direction);
            $predictedImprovement = -0.5 * $directionalDerivative;
            $objectiveRoundoff = self::OBJECTIVE_ROUNDOFF_ULPS
                * PHP_FLOAT_EPSILON
                * max(1.0, abs($previousObjective));
            if ($directionalDerivative < 0.0 && $predictedImprovement <= $objectiveRoundoff) {
                return $this->success(Bt02ConvergenceStatus::ConvergedStepObjective, $parameters, $iteration - 1, $previousObjective);
            }
            $accepted = null;
            $objective = null;
            $step = 1.0;
            for ($lineSearch = 0; $lineSearch <= 20; $lineSearch++, $step /= 2) {
                $candidate = array_map(fn (float $parameter, float $delta): float => $parameter + $step * $delta, $parameters, $direction);
                $candidateObjective = $this->objectiveFor($candidate, $source, $lambda, $summary);
                if (is_finite($candidateObjective) && $candidateObjective <= $previousObjective + self::ARMIJO * $step * $directionalDerivative) {
                    $accepted = $candidate;
                    $objective = $candidateObjective;
                    break;
                }
            }
            if ($accepted === null || $objective === null) {
                return $this->failed(Bt02ConvergenceStatus::FailedLineSearch, $summary['dimension'], $iteration, $previousObjective);
            }
            $stepMaximum = max(array_map(fn (float $before, float $after): float => abs($after - $before), $parameters, $accepted));
            $relativeObjective = abs($previousObjective - $objective) / max(1.0, abs($previousObjective));
            $parameters = $accepted;
            $previousObjective = $objective;
            if ($stepMaximum <= self::STEP_TOLERANCE && $relativeObjective <= self::OBJECTIVE_TOLERANCE) {
                return $this->success(Bt02ConvergenceStatus::ConvergedStepObjective, $parameters, $iteration, $objective);
            }
        }

        return $this->failed(Bt02ConvergenceStatus::FailedMaxIterations, $summary['dimension'], self::MAX_ITERATIONS, $previousObjective);
    }

    /** @param list<float> $coefficients @param list<float> $features */
    public function probability(float $intercept, array $coefficients, array $features): float
    {
        if (count($coefficients) !== count($features)) {
            throw new InvalidArgumentException('Logistic prediction dimension mismatch.');
        }

        return $this->sigmoid($intercept + $this->dot($coefficients, $features));
    }

    /** @param list<float> $parameters */
    public function objective(array $parameters, LogisticTrainingRowSource $source, float $lambda): float
    {
        $this->validateLambda($lambda);
        $summary = $this->inspect($source);
        if (count($parameters) !== $summary['dimension'] + 1 || ! $this->finite($parameters)) {
            throw new InvalidArgumentException('Logistic objective parameters were invalid.');
        }

        return $this->objectiveFor($parameters, $source, $lambda, $summary);
    }

    /** @return array{dimension: int, count: int, positive: int} */
    private function inspect(LogisticTrainingRowSource $source): array
    {
        $dimension = null;
        $count = 0;
        $positive = 0;
        foreach ($source->rows() as $row) {
            $dimension = $this->validateRow($row, $dimension);
            $count++;
            $positive += $row->label;
        }
        if ($dimension === null || $count === 0) {
            throw new InvalidArgumentException('Logistic training rows must not be empty.');
        }

        return ['dimension' => $dimension, 'count' => $count, 'positive' => $positive];
    }

    /** @param array{dimension: int, count: int, positive: int} $summary @param list<float> $parameters */
    private function objectiveFor(array $parameters, LogisticTrainingRowSource $source, float $lambda, array $summary): float
    {
        $sum = 0.0;
        $compensation = 0.0;
        $count = 0;
        foreach ($source->rows() as $row) {
            $this->validateRow($row, $summary['dimension']);
            $z = $parameters[0] + $this->dot(array_slice($parameters, 1), $row->features);
            $value = $this->softplus($z) - $row->label * $z;
            $next = $sum + $value;
            $compensation += abs($sum) >= abs($value)
                ? ($sum - $next) + $value
                : ($value - $next) + $sum;
            $sum = $next;
            $count++;
        }
        $this->assertReplayCount($summary['count'], $count);
        $penalty = 0.0;
        foreach (array_slice($parameters, 1) as $coefficient) {
            $penalty += $coefficient ** 2;
        }

        return ($sum + $compensation) / $count + ($lambda / 2) * $penalty;
    }

    /**
     * @param  list<float>  $parameters
     * @param  array{dimension: int, count: int, positive: int}  $summary
     * @return array{list<float>, list<list<float>>}
     */
    private function derivatives(array $parameters, LogisticTrainingRowSource $source, float $lambda, array $summary): array
    {
        $size = count($parameters);
        $gradient = array_fill(0, $size, 0.0);
        $hessian = array_fill(0, $size, array_fill(0, $size, 0.0));
        $count = 0;
        foreach ($source->rows() as $row) {
            $this->validateRow($row, $summary['dimension']);
            $augmented = [1.0, ...$row->features];
            $probability = $this->sigmoid($this->dot($parameters, $augmented));
            $error = $probability - $row->label;
            $weight = $probability * (1.0 - $probability);
            for ($left = 0; $left < $size; $left++) {
                $gradient[$left] += $error * $augmented[$left];
                for ($right = 0; $right < $size; $right++) {
                    $hessian[$left][$right] += $weight * $augmented[$left] * $augmented[$right];
                }
            }
            $count++;
        }
        $this->assertReplayCount($summary['count'], $count);
        for ($left = 0; $left < $size; $left++) {
            $gradient[$left] /= $count;
            for ($right = 0; $right < $size; $right++) {
                $hessian[$left][$right] /= $count;
            }
            if ($left > 0) {
                $gradient[$left] += $lambda * $parameters[$left];
                $hessian[$left][$left] += $lambda;
            }
        }

        return [$gradient, $hessian];
    }

    private function validateRow(mixed $row, ?int $expectedDimension): int
    {
        if (! $row instanceof LogisticTrainingRowDto) {
            throw new InvalidArgumentException('Logistic source yielded an invalid row type.');
        }
        $dimension = count($row->features);
        if ($dimension === 0 || ($expectedDimension !== null && $dimension !== $expectedDimension) || ! $this->finite($row->features)) {
            throw new InvalidArgumentException('Logistic training feature dimensions or values were invalid.');
        }
        if (! in_array($row->label, [0, 1], true)) {
            throw new InvalidArgumentException('Logistic training label was not binary.');
        }

        return $dimension;
    }

    private function validateLambda(float $lambda): void
    {
        if (! is_finite($lambda) || $lambda < 0) {
            throw new InvalidArgumentException('Logistic lambda was invalid.');
        }
    }

    private function assertReplayCount(int $expected, int $actual): void
    {
        if ($actual !== $expected) {
            throw new RuntimeException('Logistic training source changed between replay passes.');
        }
    }

    /** @param list<list<float>> $matrix @param list<float> $rightHandSide @return list<float>|null */
    private function choleskySolve(array $matrix, array $rightHandSide): ?array
    {
        $size = count($matrix);
        $lower = array_fill(0, $size, array_fill(0, $size, 0.0));
        for ($row = 0; $row < $size; $row++) {
            for ($column = 0; $column <= $row; $column++) {
                $sum = $matrix[$row][$column];
                for ($index = 0; $index < $column; $index++) {
                    $sum -= $lower[$row][$index] * $lower[$column][$index];
                }
                if ($row === $column) {
                    if (! is_finite($sum) || $sum <= 1e-14) {
                        return null;
                    }
                    $lower[$row][$column] = sqrt($sum);
                } else {
                    $lower[$row][$column] = $sum / $lower[$column][$column];
                }
            }
        }
        $forward = array_fill(0, $size, 0.0);
        for ($row = 0; $row < $size; $row++) {
            $sum = $rightHandSide[$row];
            for ($column = 0; $column < $row; $column++) {
                $sum -= $lower[$row][$column] * $forward[$column];
            }
            $forward[$row] = $sum / $lower[$row][$row];
        }
        $result = array_fill(0, $size, 0.0);
        for ($row = $size - 1; $row >= 0; $row--) {
            $sum = $forward[$row];
            for ($column = $row + 1; $column < $size; $column++) {
                $sum -= $lower[$column][$row] * $result[$column];
            }
            $result[$row] = $sum / $lower[$row][$row];
        }

        return $this->finite($result) ? $result : null;
    }

    private function sigmoid(float $value): float
    {
        if ($value >= 0) {
            $exp = exp(-$value);

            return 1.0 / (1.0 + $exp);
        }
        $exp = exp($value);

        return $exp / (1.0 + $exp);
    }

    private function softplus(float $value): float
    {
        return max($value, 0.0) + log1p(exp(-abs($value)));
    }

    /** @param list<float> $left @param list<float> $right */
    private function dot(array $left, array $right): float
    {
        $sum = 0.0;
        foreach ($left as $index => $value) {
            $sum += $value * $right[$index];
        }

        return $sum;
    }

    /** @param list<float> $values */
    private function finite(array $values): bool
    {
        return array_filter($values, fn (float $value): bool => ! is_finite($value)) === [];
    }

    /** @param list<list<float>> $matrix */
    private function finiteMatrix(array $matrix): bool
    {
        return array_filter($matrix, fn (array $row): bool => ! $this->finite($row)) === [];
    }

    private function failed(Bt02ConvergenceStatus $status, int $dimension, int $iterations = 0, ?float $objective = null): RidgeLogisticFitResultDto
    {
        return new RidgeLogisticFitResultDto($status, 0.0, array_fill(0, $dimension, 0.0), $iterations, $objective);
    }

    /** @param list<float> $parameters */
    private function success(Bt02ConvergenceStatus $status, array $parameters, int $iterations, float $objective): RidgeLogisticFitResultDto
    {
        return new RidgeLogisticFitResultDto($status, $parameters[0], array_slice($parameters, 1), $iterations, $objective);
    }
}
