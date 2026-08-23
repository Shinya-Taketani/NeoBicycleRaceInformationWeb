<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Calculators;

use App\Domain\Keirin\Backtest\DTO\Bt03e02FitResultDto;
use App\Domain\Keirin\Backtest\Enums\Bt03e02CandidateStatus;
use App\Domain\Keirin\Backtest\Exceptions\Bt03e02OptimizerNonConvergenceException;
use App\Domain\Keirin\Backtest\Services\Bt03e02Contract;
use RuntimeException;

final class Bt03e02FistaOptimizer
{
    /** @var list<float> */
    public const FIT_EXECUTION_ORDER = [1.0, 1e-1, 1e-2, 1e-3, 1e-4, 1e-5, 1e-6, 0.0];

    public function __construct(private readonly Bt03e02PairwiseObjective $objective) {}

    /**
     * @param  callable(): iterable<array<string, mixed>>  $raceSource
     * @return array{
     *   fits:array<string,Bt03e02FitResultDto>,
     *   candidate_statuses:array<string,array<string,mixed>>,
     *   fit_order:list<float>
     * }
     */
    public function fitPath(callable $raceSource, Bt03e02ParameterLayout $layout): array
    {
        $path = $this->runPath($raceSource, $layout);
        if (count($path['candidate_statuses']) !== count(Bt03e02Contract::LAMBDA_GRID)) {
            throw new RuntimeException('BT-03E-02 full lambda path did not audit every fixed candidate.');
        }
        if ($path['fits'] === []) {
            throw new RuntimeException('BT-03E-02 all fixed lambda candidates were numerically non-converged.');
        }

        return [
            'fits' => $path['fits'],
            'candidate_statuses' => $path['candidate_statuses'],
            'fit_order' => $path['fit_order'],
        ];
    }

    /**
     * @param  callable(): iterable<array<string, mixed>>  $raceSource
     * @return array{
     *   fit:Bt03e02FitResultDto,
     *   selected_lambda:float,
     *   candidate_statuses:array<string,array<string,mixed>>,
     *   fit_order:list<float>
     * }
     */
    public function fitSelectedViaPath(
        callable $raceSource,
        Bt03e02ParameterLayout $layout,
        float $selectedLambda,
    ): array {
        if (! in_array($selectedLambda, Bt03e02Contract::LAMBDA_GRID, true)) {
            throw new RuntimeException('BT-03E-02 selected lambda was outside the frozen grid.');
        }
        $path = $this->runPath($raceSource, $layout, $selectedLambda);
        $selectedKey = $this->lambdaKey($selectedLambda);
        if (! isset($path['fits'][$selectedKey])) {
            throw new RuntimeException(
                sprintf('BT-03E-02 selected lambda %.17g did not converge during path refit; fallback was forbidden.', $selectedLambda),
                previous: $path['failures'][$selectedKey] ?? null,
            );
        }

        return [
            'fit' => $path['fits'][$selectedKey],
            'selected_lambda' => $selectedLambda,
            'candidate_statuses' => $path['candidate_statuses'],
            'fit_order' => $path['fit_order'],
        ];
    }

    /**
     * @param  callable(): iterable<array<string, mixed>>  $raceSource
     * @return array{
     *   fits:array<string,Bt03e02FitResultDto>,
     *   candidate_statuses:array<string,array<string,mixed>>,
     *   fit_order:list<float>,
     *   failures:array<string,Bt03e02OptimizerNonConvergenceException>
     * }
     */
    private function runPath(
        callable $raceSource,
        Bt03e02ParameterLayout $layout,
        ?float $stopAtLambda = null,
    ): array {
        $fitsByExecution = [];
        $statusesByExecution = [];
        $failuresByExecution = [];
        $fitOrder = [];
        $warmStart = [];
        $warmStartLambda = null;

        foreach (self::FIT_EXECUTION_ORDER as $lambda) {
            $fitOrder[] = $lambda;
            $key = $this->lambdaKey($lambda);
            try {
                $fit = $this->fit($raceSource, $layout, $lambda, $warmStart);
                $fitsByExecution[$key] = $fit;
                $statusesByExecution[$key] = [
                    'lambda' => $lambda,
                    'status' => Bt03e02CandidateStatus::Converged->value,
                    'warm_start_from_lambda' => $warmStartLambda,
                    'channels' => $fit->diagnostics,
                ];
                $warmStart = $fit->coefficients;
                $warmStartLambda = $lambda;
            } catch (Bt03e02OptimizerNonConvergenceException $exception) {
                $statusesByExecution[$key] = [
                    'lambda' => $lambda,
                    'status' => Bt03e02CandidateStatus::NumericallyNonConverged->value,
                    'warm_start_from_lambda' => $warmStartLambda,
                    'failed_channel' => $exception->diagnostics['channel'],
                    'channels' => [$exception->diagnostics['channel'] => $exception->diagnostics],
                ];
                $failuresByExecution[$key] = $exception;
            }
            if ($stopAtLambda !== null && $lambda === $stopAtLambda) {
                break;
            }
        }

        $fits = $candidateStatuses = [];
        foreach (Bt03e02Contract::LAMBDA_GRID as $lambda) {
            $key = $this->lambdaKey($lambda);
            if (! isset($statusesByExecution[$key])) {
                continue;
            }
            $candidateStatuses[$key] = $statusesByExecution[$key];
            if (isset($fitsByExecution[$key])) {
                $fits[$key] = $fitsByExecution[$key];
            }
        }

        return [
            'fits' => $fits,
            'candidate_statuses' => $candidateStatuses,
            'fit_order' => $fitOrder,
            'failures' => $failuresByExecution,
        ];
    }

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
        $coefficients = $objectives = $iterations = $eligible = $excluded = $diagnostics = [];
        foreach (Bt03e02Contract::CHANNELS as $channel) {
            $fit = $this->fitChannel($raceSource, $layout, $lambda, $channel, $warmStart[$channel] ?? null);
            $coefficients[$channel] = $fit['coefficients'];
            $objectives[$channel] = $fit['objective'];
            $iterations[$channel] = $fit['iterations'];
            $eligible[$channel] = $fit['eligible_races'];
            $excluded[$channel] = $fit['excluded_races'];
            $diagnostics[$channel] = $fit['diagnostics'];
        }

        return new Bt03e02FitResultDto($lambda, $coefficients, $objectives, $iterations, $eligible, $excluded, $diagnostics);
    }

    /**
     * @param  callable(): iterable<array<string, mixed>>  $raceSource
     * @param  list<float>|null  $initial
     * @return array{coefficients:list<float>,objective:float,iterations:int,eligible_races:int,excluded_races:int,diagnostics:array<string,int|float|string>}
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
        if (! is_finite($previousObjective)) {
            throw new RuntimeException("BT-03E-02 {$channel} optimizer initial objective was non-finite.");
        }
        $lastDiagnostics = $this->diagnostics(
            Bt03e02CandidateStatus::NumericallyNonConverged,
            $lambda,
            $channel,
            0,
            $previousObjective,
            $previousObjective,
            0.0,
            0.0,
            $step,
            $current,
            $initialEvaluation['eligible_races'],
            $initialEvaluation['excluded_races'],
            0,
        );

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
                $lastDiagnostics = $this->diagnostics(
                    Bt03e02CandidateStatus::NumericallyNonConverged,
                    $lambda,
                    $channel,
                    $iteration,
                    $previousObjective,
                    $previousObjective,
                    0.0,
                    0.0,
                    $trialStep,
                    $current,
                    $evaluation['eligible_races'],
                    $evaluation['excluded_races'],
                    $lineSearch + 1,
                );
                $accelerated = $current;
                $momentum = 1.0;

                continue;
            }

            $maximumChange = 0.0;
            foreach ($accepted as $index => $value) {
                $maximumChange = max($maximumChange, abs($value - $current[$index]));
            }
            $relativeObjective = abs($previousObjective - $acceptedObjective) / max(1.0, abs($previousObjective));
            $objectiveBeforeIteration = $previousObjective;
            $nextMomentum = (1.0 + sqrt(1.0 + 4.0 * $momentum * $momentum)) / 2.0;
            $nextAccelerated = [];
            foreach ($accepted as $index => $value) {
                $nextAccelerated[$index] = $value + (($momentum - 1.0) / $nextMomentum) * ($value - $current[$index]);
            }
            $current = $accepted;
            $accelerated = $nextAccelerated;
            $momentum = $nextMomentum;
            $previousObjective = $acceptedObjective;
            $lastDiagnostics = $this->diagnostics(
                Bt03e02CandidateStatus::NumericallyNonConverged,
                $lambda,
                $channel,
                $iteration,
                $acceptedObjective,
                $objectiveBeforeIteration,
                $relativeObjective,
                $maximumChange,
                $trialStep,
                $current,
                $evaluation['eligible_races'],
                $evaluation['excluded_races'],
                $lineSearch + 1,
            );
            if ($maximumChange <= Bt03e02Contract::CONVERGENCE_TOLERANCE
                && $relativeObjective <= Bt03e02Contract::OBJECTIVE_TOLERANCE) {
                $lastDiagnostics['status'] = Bt03e02CandidateStatus::Converged->value;

                return [
                    'coefficients' => $current,
                    'objective' => $previousObjective,
                    'iterations' => $iteration,
                    'eligible_races' => $evaluation['eligible_races'],
                    'excluded_races' => $evaluation['excluded_races'],
                    'diagnostics' => $lastDiagnostics,
                ];
            }
        }

        throw new Bt03e02OptimizerNonConvergenceException($lastDiagnostics);
    }

    /**
     * @param  list<float>  $coefficients
     * @return array<string,int|float|string>
     */
    private function diagnostics(
        Bt03e02CandidateStatus $status,
        float $lambda,
        string $channel,
        int $iteration,
        float $finalObjective,
        float $previousObjective,
        float $relativeObjectiveChange,
        float $maximumCoefficientChange,
        float $currentStep,
        array $coefficients,
        int $eligibleRaceCount,
        int $excludedRaceCount,
        int $lineSearchSteps,
    ): array {
        $squares = new Bt03e02CompensatedSum;
        $maximumAbsoluteCoefficient = 0.0;
        foreach ($coefficients as $coefficient) {
            $squares->add($coefficient * $coefficient);
            $maximumAbsoluteCoefficient = max($maximumAbsoluteCoefficient, abs($coefficient));
        }
        $diagnostics = [
            'status' => $status->value,
            'lambda' => $lambda,
            'channel' => $channel,
            'iteration' => $iteration,
            'max_iterations' => Bt03e02Contract::MAX_ITERATIONS,
            'final_objective' => $finalObjective,
            'previous_objective' => $previousObjective,
            'relative_objective_change' => $relativeObjectiveChange,
            'maximum_coefficient_change' => $maximumCoefficientChange,
            'current_step' => $currentStep,
            'coefficient_l2_norm' => sqrt($squares->value()),
            'maximum_absolute_coefficient' => $maximumAbsoluteCoefficient,
            'eligible_race_count' => $eligibleRaceCount,
            'excluded_race_count' => $excludedRaceCount,
            'line_search_steps_last_iteration' => $lineSearchSteps,
        ];
        foreach ($diagnostics as $value) {
            if (is_float($value) && ! is_finite($value)) {
                throw new RuntimeException("BT-03E-02 {$channel} optimizer diagnostics were non-finite.");
            }
        }

        return $diagnostics;
    }

    /** @param list<float> $coefficients */
    private function fullObjective(float $loss, Bt03e02ParameterLayout $layout, array $coefficients, float $lambda): float
    {
        return $loss
            + $this->objective->smoothPenalty($layout, $coefficients, $lambda)
            + $this->objective->groupPenalty($layout, $coefficients, $lambda);
    }

    private function lambdaKey(float $lambda): string
    {
        return sprintf('%.17g', $lambda);
    }
}
