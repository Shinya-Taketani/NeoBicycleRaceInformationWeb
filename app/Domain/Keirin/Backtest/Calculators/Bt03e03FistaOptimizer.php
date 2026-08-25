<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Calculators;

use App\Domain\Keirin\Backtest\DTO\Bt03e03FitResultDto;
use App\Domain\Keirin\Backtest\Enums\Bt03e03CandidateStatus;
use App\Domain\Keirin\Backtest\Exceptions\Bt03e03OptimizerNonConvergenceException;
use App\Domain\Keirin\Backtest\Services\Bt03e03Contract;
use RuntimeException;

final class Bt03e03FistaOptimizer
{
    public function __construct(private readonly Bt03e03ConditionalSoftmaxObjective $objective) {}

    /**
     * @param  callable():iterable<array<string,mixed>>  $raceSource
     * @return array{fits:array<string,Bt03e03FitResultDto>,candidate_statuses:array<string,array<string,mixed>>,fit_order:list<float>}
     */
    public function fitPath(callable $raceSource, Bt03e02ParameterLayout $layout): array
    {
        $path = $this->runPath($raceSource, $layout);
        if (count($path['candidate_statuses']) !== count(Bt03e03Contract::LAMBDA_GRID)) {
            throw new RuntimeException('BT-03E-03 full lambda path did not audit every fixed candidate.');
        }
        if ($path['fits'] === []) {
            throw new RuntimeException('BT-03E-03 all fixed lambda candidates were numerically non-converged.');
        }

        return [
            'fits' => $path['fits'],
            'candidate_statuses' => $path['candidate_statuses'],
            'fit_order' => $path['fit_order'],
        ];
    }

    /**
     * @param  callable():iterable<array<string,mixed>>  $raceSource
     * @return array{fit:Bt03e03FitResultDto,selected_lambda:float,candidate_statuses:array<string,array<string,mixed>>,fit_order:list<float>}
     */
    public function fitSelectedViaPath(
        callable $raceSource,
        Bt03e02ParameterLayout $layout,
        float $selectedLambda,
    ): array {
        if (! in_array($selectedLambda, Bt03e03Contract::LAMBDA_GRID, true)) {
            throw new RuntimeException('BT-03E-03 selected lambda was outside the frozen grid.');
        }
        $path = $this->runPath($raceSource, $layout, $selectedLambda);
        $key = $this->lambdaKey($selectedLambda);
        if (! isset($path['fits'][$key])) {
            throw new RuntimeException(
                sprintf('BT-03E-03 selected lambda %.17g did not converge during path refit; fallback was forbidden.', $selectedLambda),
                previous: $path['failures'][$key] ?? null,
            );
        }

        return [
            'fit' => $path['fits'][$key],
            'selected_lambda' => $selectedLambda,
            'candidate_statuses' => $path['candidate_statuses'],
            'fit_order' => $path['fit_order'],
        ];
    }

    /**
     * @param  callable():iterable<array<string,mixed>>  $raceSource
     * @return array{fits:array<string,Bt03e03FitResultDto>,candidate_statuses:array<string,array<string,mixed>>,fit_order:list<float>,failures:array<string,Bt03e03OptimizerNonConvergenceException>}
     */
    private function runPath(
        callable $raceSource,
        Bt03e02ParameterLayout $layout,
        ?float $stopAtLambda = null,
    ): array {
        $fitsByExecution = $statusesByExecution = $failures = [];
        $fitOrder = [];
        $warmStart = [];
        $warmStartLambda = null;
        foreach (Bt03e03Contract::FIT_EXECUTION_ORDER as $lambda) {
            $fitOrder[] = $lambda;
            $key = $this->lambdaKey($lambda);
            try {
                $fit = $this->fit($raceSource, $layout, $lambda, $warmStart);
                $fitsByExecution[$key] = $fit;
                $statusesByExecution[$key] = [
                    'lambda' => $lambda,
                    'status' => Bt03e03CandidateStatus::Converged->value,
                    'warm_start_from_lambda' => $warmStartLambda,
                    'positions' => $fit->diagnostics,
                ];
                $warmStart = $fit->coefficients;
                $warmStartLambda = $lambda;
            } catch (Bt03e03OptimizerNonConvergenceException $exception) {
                $statusesByExecution[$key] = [
                    'lambda' => $lambda,
                    'status' => Bt03e03CandidateStatus::NumericallyNonConverged->value,
                    'warm_start_from_lambda' => $warmStartLambda,
                    'failed_position' => $exception->diagnostics['position'],
                    'positions' => [$exception->diagnostics['position'] => $exception->diagnostics],
                ];
                $failures[$key] = $exception;
            }
            if ($stopAtLambda !== null && $lambda === $stopAtLambda) {
                break;
            }
        }
        $fits = $statuses = [];
        foreach (Bt03e03Contract::LAMBDA_GRID as $lambda) {
            $key = $this->lambdaKey($lambda);
            if (! isset($statusesByExecution[$key])) {
                continue;
            }
            $statuses[$key] = $statusesByExecution[$key];
            if (isset($fitsByExecution[$key])) {
                $fits[$key] = $fitsByExecution[$key];
            }
        }

        return ['fits' => $fits, 'candidate_statuses' => $statuses, 'fit_order' => $fitOrder, 'failures' => $failures];
    }

    /**
     * @param  callable():iterable<array<string,mixed>>  $raceSource
     * @param  array<string,list<float>>  $warmStart
     */
    public function fit(
        callable $raceSource,
        Bt03e02ParameterLayout $layout,
        float $lambda,
        array $warmStart = [],
    ): Bt03e03FitResultDto {
        if (! in_array($lambda, Bt03e03Contract::LAMBDA_GRID, true)) {
            throw new RuntimeException('BT-03E-03 lambda was outside the frozen grid.');
        }
        $coefficients = $objectives = $iterations = $eligible = $excluded = $diagnostics = [];
        foreach (Bt03e03Contract::POSITIONS as $position) {
            $fit = $this->fitPosition($raceSource, $layout, $lambda, $position, $warmStart[$position] ?? null);
            $coefficients[$position] = $fit['coefficients'];
            $objectives[$position] = $fit['objective'];
            $iterations[$position] = $fit['iterations'];
            $eligible[$position] = $fit['eligible_races'];
            $excluded[$position] = $fit['excluded_races'];
            $diagnostics[$position] = $fit['diagnostics'];
        }

        return new Bt03e03FitResultDto($lambda, $coefficients, $objectives, $iterations, $eligible, $excluded, $diagnostics);
    }

    /**
     * @param  callable():iterable<array<string,mixed>>  $raceSource
     * @param  list<float>|null  $initial
     * @return array{coefficients:list<float>,objective:float,iterations:int,eligible_races:int,excluded_races:int,diagnostics:array<string,int|float|string>}
     */
    private function fitPosition(
        callable $raceSource,
        Bt03e02ParameterLayout $layout,
        float $lambda,
        string $position,
        ?array $initial,
    ): array {
        $current = $layout->project($initial ?? array_fill(0, $layout->size(), 0.0));
        $accelerated = $current;
        $momentum = 1.0;
        $step = Bt03e03Contract::INITIAL_STEP;
        $monotoneRestartCount = 0;
        $backtrackingIterationCount = 0;
        $restartStepRetentionCount = 0;
        $acceptedUpdateCount = 0;
        $optimizerAttemptCount = 0;
        $lastMonotoneRestartIteration = 0;
        $lastMonotoneRestartStep = $step;
        $lastPostRestartIterationStartStep = $step;
        $lastPostRestartAcceptedUpdateIteration = 0;
        $pendingRestartStep = null;
        $initialEvaluation = $this->objective->lossAndGradient($raceSource, $layout, $current, $position);
        $previousObjective = $this->fullObjective($initialEvaluation['loss'], $layout, $current, $lambda);
        if (! is_finite($previousObjective)) {
            throw new RuntimeException("BT-03E-03 {$position} optimizer initial objective was non-finite.");
        }
        $lastDiagnostics = $this->diagnostics(
            Bt03e03CandidateStatus::NumericallyNonConverged,
            $lambda,
            $position,
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
            $monotoneRestartCount,
            $backtrackingIterationCount,
            $restartStepRetentionCount,
            $acceptedUpdateCount,
            $optimizerAttemptCount,
            $lastMonotoneRestartIteration,
            $lastMonotoneRestartStep,
            $lastPostRestartIterationStartStep,
            $lastPostRestartAcceptedUpdateIteration,
        );

        for ($iteration = 1; $iteration <= Bt03e03Contract::MAX_ITERATIONS; $iteration++) {
            $attemptsWithinUpdate = 0;
            while (true) {
                $attemptsWithinUpdate++;
                if ($attemptsWithinUpdate > 2) {
                    throw new RuntimeException("BT-03E-03 {$position} monotone restart retry invariant failed.");
                }
                $optimizerAttemptCount++;
                $retryingAfterRestart = false;
                if ($pendingRestartStep !== null) {
                    if ($step !== $pendingRestartStep) {
                        throw new RuntimeException("BT-03E-03 {$position} monotone restart step was not retained.");
                    }
                    $restartStepRetentionCount++;
                    $lastPostRestartIterationStartStep = $step;
                    $pendingRestartStep = null;
                    $retryingAfterRestart = true;
                }
                $evaluation = $this->objective->lossAndGradient($raceSource, $layout, $accelerated, $position);
                $penaltyGradient = $this->objective->smoothPenaltyGradient($layout, $accelerated, $lambda);
                $gradient = array_map(
                    static fn (float $loss, float $penalty): float => $loss + $penalty,
                    $evaluation['gradient'],
                    $penaltyGradient,
                );
                $smoothAtAccelerated = $evaluation['loss'] + $this->objective->smoothPenalty($layout, $accelerated, $lambda);
                $accepted = $acceptedLoss = null;
                $trialStep = $step;
                for ($lineSearch = 0; $lineSearch <= Bt03e03Contract::MAX_LINE_SEARCH_STEPS; $lineSearch++) {
                    $trial = [];
                    foreach ($accelerated as $index => $value) {
                        $trial[$index] = $value - $trialStep * $gradient[$index];
                    }
                    $trial = $layout->project($this->objective->groupProx($layout, $trial, $trialStep, $lambda));
                    $trialLoss = $this->objective->loss($raceSource, $layout, $trial, $position);
                    $trialSmooth = $trialLoss + $this->objective->smoothPenalty($layout, $trial, $lambda);
                    $linear = new Bt03e03CompensatedSum;
                    $distance = new Bt03e03CompensatedSum;
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
                    $trialStep *= Bt03e03Contract::BACKTRACK_FACTOR;
                }
                if ($accepted === null || $acceptedLoss === null) {
                    throw new RuntimeException("BT-03E-03 {$position} FISTA line search failed.");
                }
                if ($lineSearch > 0) {
                    $backtrackingIterationCount++;
                }
                $step = $trialStep;
                $acceptedObjective = $this->fullObjective($acceptedLoss, $layout, $accepted, $lambda);
                if (! is_finite($acceptedObjective)) {
                    throw new RuntimeException("BT-03E-03 {$position} optimizer produced a non-finite objective.");
                }
                if ($momentum > 1.0 && $acceptedObjective > $previousObjective) {
                    $monotoneRestartCount++;
                    $lastMonotoneRestartIteration = $iteration;
                    $lastMonotoneRestartStep = $trialStep;
                    $pendingRestartStep = $trialStep;
                    $accelerated = $current;
                    $momentum = 1.0;

                    continue;
                }
                $maximumChange = 0.0;
                foreach ($accepted as $index => $value) {
                    $maximumChange = max($maximumChange, abs($value - $current[$index]));
                }
                $relativeObjective = abs($previousObjective - $acceptedObjective) / max(1.0, abs($previousObjective));
                $before = $previousObjective;
                $nextMomentum = (1.0 + sqrt(1.0 + 4.0 * $momentum * $momentum)) / 2.0;
                $nextAccelerated = [];
                foreach ($accepted as $index => $value) {
                    $nextAccelerated[$index] = $value + (($momentum - 1.0) / $nextMomentum) * ($value - $current[$index]);
                }
                $current = $accepted;
                $accelerated = $nextAccelerated;
                $momentum = $nextMomentum;
                $previousObjective = $acceptedObjective;
                $acceptedUpdateCount++;
                if ($acceptedUpdateCount !== $iteration) {
                    throw new RuntimeException("BT-03E-03 {$position} accepted update accounting drifted.");
                }
                if ($retryingAfterRestart) {
                    $lastPostRestartAcceptedUpdateIteration = $iteration;
                }
                $lastDiagnostics = $this->diagnostics(
                    Bt03e03CandidateStatus::NumericallyNonConverged,
                    $lambda,
                    $position,
                    $iteration,
                    $acceptedObjective,
                    $before,
                    $relativeObjective,
                    $maximumChange,
                    $trialStep,
                    $current,
                    $evaluation['eligible_races'],
                    $evaluation['excluded_races'],
                    $lineSearch + 1,
                    $monotoneRestartCount,
                    $backtrackingIterationCount,
                    $restartStepRetentionCount,
                    $acceptedUpdateCount,
                    $optimizerAttemptCount,
                    $lastMonotoneRestartIteration,
                    $lastMonotoneRestartStep,
                    $lastPostRestartIterationStartStep,
                    $lastPostRestartAcceptedUpdateIteration,
                );
                if ($maximumChange <= Bt03e03Contract::CONVERGENCE_TOLERANCE
                    && $relativeObjective <= Bt03e03Contract::OBJECTIVE_TOLERANCE) {
                    $lastDiagnostics['status'] = Bt03e03CandidateStatus::Converged->value;

                    return [
                        'coefficients' => $current,
                        'objective' => $previousObjective,
                        'iterations' => $acceptedUpdateCount,
                        'eligible_races' => $evaluation['eligible_races'],
                        'excluded_races' => $evaluation['excluded_races'],
                        'diagnostics' => $lastDiagnostics,
                    ];
                }

                break;
            }
        }

        throw new Bt03e03OptimizerNonConvergenceException($lastDiagnostics);
    }

    /** @param list<float> $coefficients @return array<string,int|float|string> */
    private function diagnostics(
        Bt03e03CandidateStatus $status,
        float $lambda,
        string $position,
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
        int $monotoneRestartCount,
        int $backtrackingIterationCount,
        int $restartStepRetentionCount,
        int $acceptedUpdateCount,
        int $optimizerAttemptCount,
        int $lastMonotoneRestartIteration,
        float $lastMonotoneRestartStep,
        float $lastPostRestartIterationStartStep,
        int $lastPostRestartAcceptedUpdateIteration,
    ): array {
        $squares = new Bt03e03CompensatedSum;
        $maximumAbsolute = 0.0;
        foreach ($coefficients as $coefficient) {
            $squares->add($coefficient * $coefficient);
            $maximumAbsolute = max($maximumAbsolute, abs($coefficient));
        }
        $diagnostics = [
            'status' => $status->value,
            'lambda' => $lambda,
            'position' => $position,
            'iteration' => $iteration,
            'accepted_update_count' => $acceptedUpdateCount,
            'optimizer_attempt_count' => $optimizerAttemptCount,
            'max_iterations' => Bt03e03Contract::MAX_ITERATIONS,
            'final_objective' => $finalObjective,
            'previous_objective' => $previousObjective,
            'relative_objective_change' => $relativeObjectiveChange,
            'maximum_coefficient_change' => $maximumCoefficientChange,
            'current_step' => $currentStep,
            'coefficient_l2_norm' => sqrt($squares->value()),
            'maximum_absolute_coefficient' => $maximumAbsolute,
            'eligible_race_count' => $eligibleRaceCount,
            'excluded_race_count' => $excludedRaceCount,
            'line_search_steps_last_iteration' => $lineSearchSteps,
            'monotone_restart_count' => $monotoneRestartCount,
            'backtracking_iteration_count' => $backtrackingIterationCount,
            'restart_step_retention_count' => $restartStepRetentionCount,
            'last_monotone_restart_iteration' => $lastMonotoneRestartIteration,
            'last_monotone_restart_step' => $lastMonotoneRestartStep,
            'last_post_restart_iteration_start_step' => $lastPostRestartIterationStartStep,
            'last_post_restart_accepted_update_iteration' => $lastPostRestartAcceptedUpdateIteration,
        ];
        foreach ($diagnostics as $value) {
            if (is_float($value) && ! is_finite($value)) {
                throw new RuntimeException("BT-03E-03 {$position} optimizer diagnostics were non-finite.");
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
