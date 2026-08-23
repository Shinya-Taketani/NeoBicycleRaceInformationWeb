<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Calculators;

use App\Domain\Keirin\Backtest\DTO\Bt03e02FitResultDto;
use App\Domain\Keirin\Backtest\Services\Bt03e02Contract;
use RuntimeException;

final class Bt03e02FistaOptimizer
{
    public function __construct(private readonly Bt03e02PairwiseObjective $objective) {}

    /**
     * @param  callable(): iterable<array<string, mixed>>  $raceSource
     * @param  array<string, list<float>>  $warmStart
     */
    public function fit(
        callable $raceSource,
        Bt03e02ParameterLayout $layout,
        float $lambda,
        array $warmStart = [],
    ): Bt03e02FitResultDto {
        if (! in_array($lambda, Bt03e02Contract::LAMBDA_GRID, true)) {
            throw new RuntimeException('BT-03E-02 lambda was outside the frozen grid.');
        }
        $coefficients = $objectives = $iterations = $eligible = $excluded = [];
        foreach (Bt03e02Contract::CHANNELS as $channel) {
            $fit = $this->fitChannel($raceSource, $layout, $lambda, $channel, $warmStart[$channel] ?? null);
            $coefficients[$channel] = $fit['coefficients'];
            $objectives[$channel] = $fit['objective'];
            $iterations[$channel] = $fit['iterations'];
            $eligible[$channel] = $fit['eligible_races'];
            $excluded[$channel] = $fit['excluded_races'];
        }

        return new Bt03e02FitResultDto($lambda, $coefficients, $objectives, $iterations, $eligible, $excluded);
    }

    /**
     * @param  callable(): iterable<array<string, mixed>>  $raceSource
     * @param  list<float>|null  $initial
     * @return array{coefficients:list<float>,objective:float,iterations:int,eligible_races:int,excluded_races:int}
     */
    private function fitChannel(callable $raceSource, Bt03e02ParameterLayout $layout, float $lambda, string $channel, ?array $initial): array
    {
        $current = $initial ?? array_fill(0, $layout->size(), 0.0);
        $current = $layout->project($current);
        $accelerated = $current;
        $momentum = 1.0;
        $step = Bt03e02Contract::INITIAL_STEP;
        $initialEvaluation = $this->objective->lossAndGradient($raceSource, $layout, $current, $channel);
        $previousObjective = $this->fullObjective($initialEvaluation['loss'], $layout, $current, $lambda);

        for ($iteration = 1; $iteration <= Bt03e02Contract::MAX_ITERATIONS; $iteration++) {
            $evaluation = $this->objective->lossAndGradient($raceSource, $layout, $accelerated, $channel);
            $penaltyGradient = $this->objective->smoothPenaltyGradient($layout, $accelerated, $lambda);
            $gradient = array_map(
                static fn (float $loss, float $penalty): float => $loss + $penalty,
                $evaluation['gradient'],
                $penaltyGradient,
            );
            $smoothAtAccelerated = $evaluation['loss'] + $this->objective->smoothPenalty($layout, $accelerated, $lambda);
            $accepted = null;
            $acceptedLoss = null;
            $trialStep = $step;
            for ($lineSearch = 0; $lineSearch <= Bt03e02Contract::MAX_LINE_SEARCH_STEPS; $lineSearch++) {
                $trial = [];
                foreach ($accelerated as $index => $value) {
                    $trial[$index] = $value - $trialStep * $gradient[$index];
                }
                $trial = $this->objective->groupProx($layout, $trial, $trialStep, $lambda);
                $trial = $layout->project($trial);
                $trialLoss = $this->objective->loss($raceSource, $layout, $trial, $channel);
                $trialSmooth = $trialLoss + $this->objective->smoothPenalty($layout, $trial, $lambda);
                $linear = new Bt03e02CompensatedSum;
                $distance = new Bt03e02CompensatedSum;
                foreach ($trial as $index => $value) {
                    $delta = $value - $accelerated[$index];
                    $linear->add($gradient[$index] * $delta);
                    $distance->add($delta * $delta);
                }
                $upper = $smoothAtAccelerated + $linear->value() + $distance->value() / (2.0 * $trialStep);
                if (is_finite($trialSmooth) && $trialSmooth <= $upper + 8.0 * PHP_FLOAT_EPSILON * max(1.0, abs($upper))) {
                    $accepted = $trial;
                    $acceptedLoss = $trialLoss;
                    break;
                }
                $trialStep *= Bt03e02Contract::BACKTRACK_FACTOR;
            }
            if ($accepted === null || $acceptedLoss === null) {
                throw new RuntimeException("BT-03E-02 {$channel} FISTA line search failed.");
            }
            $step = $trialStep;
            $acceptedObjective = $this->fullObjective($acceptedLoss, $layout, $accepted, $lambda);
            if (! is_finite($acceptedObjective)) {
                throw new RuntimeException("BT-03E-02 {$channel} optimizer produced a non-finite objective.");
            }
            if ($momentum > 1.0 && $acceptedObjective > $previousObjective) {
                $accelerated = $current;
                $momentum = 1.0;

                continue;
            }

            $maximumChange = 0.0;
            foreach ($accepted as $index => $value) {
                $maximumChange = max($maximumChange, abs($value - $current[$index]));
            }
            $relativeObjective = abs($previousObjective - $acceptedObjective) / max(1.0, abs($previousObjective));
            $nextMomentum = (1.0 + sqrt(1.0 + 4.0 * $momentum * $momentum)) / 2.0;
            $nextAccelerated = [];
            foreach ($accepted as $index => $value) {
                $nextAccelerated[$index] = $value + (($momentum - 1.0) / $nextMomentum) * ($value - $current[$index]);
            }
            $current = $accepted;
            $accelerated = $nextAccelerated;
            $momentum = $nextMomentum;
            $previousObjective = $acceptedObjective;
            if ($maximumChange <= Bt03e02Contract::CONVERGENCE_TOLERANCE
                && $relativeObjective <= Bt03e02Contract::OBJECTIVE_TOLERANCE) {
                return [
                    'coefficients' => $current,
                    'objective' => $previousObjective,
                    'iterations' => $iteration,
                    'eligible_races' => $evaluation['eligible_races'],
                    'excluded_races' => $evaluation['excluded_races'],
                ];
            }
        }

        throw new RuntimeException("BT-03E-02 {$channel} FISTA did not converge within the frozen iteration limit.");
    }

    /** @param list<float> $coefficients */
    private function fullObjective(float $loss, Bt03e02ParameterLayout $layout, array $coefficients, float $lambda): float
    {
        return $loss
            + $this->objective->smoothPenalty($layout, $coefficients, $lambda)
            + $this->objective->groupPenalty($layout, $coefficients, $lambda);
    }
}
