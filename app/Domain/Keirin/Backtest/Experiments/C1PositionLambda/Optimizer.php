<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\C1PositionLambda;

use App\Domain\Keirin\Backtest\Calculators\Bt03e03CompensatedSum;
use App\Domain\Keirin\Backtest\Enums\Bt03e03CandidateStatus;
use App\Domain\Keirin\Backtest\Exceptions\Bt03e03OptimizerNonConvergenceException;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\Layout;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\Objective;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\SolverContract;
use App\Domain\Keirin\Backtest\Services\Bt03e03Contract;
use RuntimeException;

class Optimizer
{
    public function __construct(private readonly Objective $objective) {}

    /**
     * @param  callable():iterable<array<string,mixed>>  $raceSource
     * @param  list<float>|null  $initial
     * @return array{coefficients:list<float>,objective:float,iterations:int,eligible_races:int,excluded_races:int,diagnostics:array<string,int|float|string>}
     */
    public function fitPosition(
        callable $raceSource,
        Layout $layout,
        float $lambda,
        string $position,
        ?array $initial,
    ): array {
        if (! in_array($lambda, Bt03e03Contract::LAMBDA_GRID, true) || ! in_array($position, Bt03e03Contract::POSITIONS, true)
            || ($initial !== null && (! array_is_list($initial) || count($initial) !== $layout->size()
                || count(array_filter($initial, static fn ($v) => (is_float($v) || is_int($v)) && is_finite($v))) !== count($initial)))) {
            throw new RuntimeException('Invalid single-position fit input.');
        }
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
        $maximumAcceptedObjectiveIncrease = 0.0;
        $nonstationarySmallUpdates = 0;
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
                    $trial = $this->objective->groupProx($layout, $trial, $trialStep, $lambda);
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
                    $trialObjective = $trialSmooth + $this->objective->groupPenalty($layout, $trial, $lambda);
                    $monotoneTolerance = 8.0 * PHP_FLOAT_EPSILON * max(1.0, abs($previousObjective));
                    if (is_finite($trialSmooth) && $trialSmooth <= $upper + 8.0 * PHP_FLOAT_EPSILON * max(1.0, abs($upper))
                        && ($momentum > 1.0 || $trialObjective <= $previousObjective + $monotoneTolerance)) {
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
                if ($momentum > 1.0 && $acceptedObjective > $previousObjective + $monotoneTolerance) {
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
                $maximumAcceptedObjectiveIncrease = max($maximumAcceptedObjectiveIncrease, $acceptedObjective - $before);
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
                $smallChange = $maximumChange <= Bt03e03Contract::CONVERGENCE_TOLERANCE
                    && $relativeObjective <= Bt03e03Contract::OBJECTIVE_TOLERANCE;
                $lastDiagnostics['maximum_accepted_objective_increase'] = $maximumAcceptedObjectiveIncrease;
                if ($smallChange || $iteration === Bt03e03Contract::MAX_ITERATIONS) {
                    $lastDiagnostics['prox_gradient_mapping_max'] = $this->stationarity($raceSource, $layout, $current, $lambda, $position);
                    $lastDiagnostics['stationarity_reference_step'] = Bt03e03Contract::INITIAL_STEP;
                    $lastDiagnostics['centering_residual_max'] = max(array_map('abs', $layout->weightedMeans($current)));
                    if ($smallChange && $lastDiagnostics['prox_gradient_mapping_max'] > Bt03e03Contract::CONVERGENCE_TOLERANCE) {
                        $nonstationarySmallUpdates++;
                    }
                }
                $lastDiagnostics['nonstationary_small_updates'] = $nonstationarySmallUpdates;
                if ($smallChange && $lastDiagnostics['prox_gradient_mapping_max'] <= Bt03e03Contract::CONVERGENCE_TOLERANCE
                    && $lastDiagnostics['centering_residual_max'] <= Bt03e03Contract::CONVERGENCE_TOLERANCE) {
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

    private function stationarity(callable $source, Layout $layout, array $coefficients, float $lambda, string $position): float
    {
        // A fixed reference step avoids a tiny backtracking step hiding nonstationarity.
        $step = Bt03e03Contract::INITIAL_STEP;
        $evaluation = $this->objective->lossAndGradient($source, $layout, $coefficients, $position);
        $penalty = $this->objective->smoothPenaltyGradient($layout, $coefficients, $lambda);
        $trial = [];
        foreach ($coefficients as $index => $value) {
            $trial[$index] = $value - $step * ($evaluation['gradient'][$index] + $penalty[$index]);
        }
        $prox = $this->objective->groupProx($layout, $trial, $step, $lambda);
        $residual = 0.0;
        foreach ($coefficients as $index => $value) {
            $residual = max($residual, abs($value - $prox[$index]) / $step);
        }

        return $residual;
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
            'optimizer_version' => SolverContract::OPTIMIZER_VERSION,
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
    private function fullObjective(float $loss, Layout $layout, array $coefficients, float $lambda): float
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
