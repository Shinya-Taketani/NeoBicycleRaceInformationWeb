<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Calculators;

use App\Domain\Keirin\Backtest\DTO\Bt03e08FitResultDto;
use App\Domain\Keirin\Backtest\Enums\Bt03e03CandidateStatus;
use App\Domain\Keirin\Backtest\Exceptions\Bt03e08OptimizerNonConvergenceException;
use App\Domain\Keirin\Backtest\Services\Bt03e08Contract;
use RuntimeException;

final class Bt03e08FistaOptimizer
{
    public function __construct(private readonly Bt03e08WinnerConditionedP3Objective $objective) {}

    /** @param callable():iterable<array<string,mixed>> $raceSource @return array{fits:array<string,Bt03e08FitResultDto>,candidate_statuses:array<string,array<string,mixed>>,fit_order:list<float>} */
    public function fitPath(callable $raceSource, Bt03e02ParameterLayout $layout): array
    {
        $path = $this->runPath($raceSource, $layout);
        if (count($path['candidate_statuses']) !== count(Bt03e08Contract::LAMBDA_GRID)) {
            throw new RuntimeException('BT-03E-08 full lambda path did not audit every fixed candidate.');
        }
        if ($path['fits'] === []) {
            throw new RuntimeException('BT-03E-08 all fixed lambda candidates were numerically non-converged.');
        }

        return ['fits' => $path['fits'], 'candidate_statuses' => $path['candidate_statuses'], 'fit_order' => $path['fit_order']];
    }

    /** @param callable():iterable<array<string,mixed>> $raceSource @return array{fit:Bt03e08FitResultDto,selected_lambda:float,candidate_statuses:array<string,array<string,mixed>>,fit_order:list<float>} */
    public function fitSelectedViaPath(callable $raceSource, Bt03e02ParameterLayout $layout, float $selectedLambda): array
    {
        if (! in_array($selectedLambda, Bt03e08Contract::LAMBDA_GRID, true)) {
            throw new RuntimeException('BT-03E-08 selected lambda was outside the frozen grid.');
        }
        $path = $this->runPath($raceSource, $layout, $selectedLambda);
        $key = $this->lambdaKey($selectedLambda);
        if (! isset($path['fits'][$key])) {
            throw new RuntimeException(sprintf('BT-03E-08 selected lambda %.17g did not converge during path refit; fallback was forbidden.', $selectedLambda), previous: $path['failures'][$key] ?? null);
        }

        return ['fit' => $path['fits'][$key], 'selected_lambda' => $selectedLambda, 'candidate_statuses' => $path['candidate_statuses'], 'fit_order' => $path['fit_order']];
    }

    /** @param callable():iterable<array<string,mixed>> $raceSource @return array{fits:array<string,Bt03e08FitResultDto>,candidate_statuses:array<string,array<string,mixed>>,fit_order:list<float>,failures:array<string,Bt03e08OptimizerNonConvergenceException>} */
    private function runPath(callable $raceSource, Bt03e02ParameterLayout $layout, ?float $stopAtLambda = null): array
    {
        $fitsByExecution = $statusesByExecution = $failures = [];
        $fitOrder = [];
        $warmStart = null;
        $warmStartLambda = null;
        foreach (Bt03e08Contract::FIT_EXECUTION_ORDER as $lambda) {
            $fitOrder[] = $lambda;
            $key = $this->lambdaKey($lambda);
            try {
                $fit = $this->fit($raceSource, $layout, $lambda, $warmStart);
                $fitsByExecution[$key] = $fit;
                $statusesByExecution[$key] = ['lambda' => $lambda, 'status' => Bt03e03CandidateStatus::Converged->value, 'warm_start_from_lambda' => $warmStartLambda, 'position' => 'POSITION_3', 'diagnostics' => $fit->diagnostics];
                $warmStart = $fit->coefficients;
                $warmStartLambda = $lambda;
            } catch (Bt03e08OptimizerNonConvergenceException $exception) {
                $statusesByExecution[$key] = ['lambda' => $lambda, 'status' => Bt03e03CandidateStatus::NumericallyNonConverged->value, 'warm_start_from_lambda' => $warmStartLambda, 'position' => 'POSITION_3', 'diagnostics' => $exception->diagnostics];
                $failures[$key] = $exception;
            }
            if ($stopAtLambda !== null && $lambda === $stopAtLambda) {
                break;
            }
        }
        $fits = $statuses = [];
        foreach (Bt03e08Contract::LAMBDA_GRID as $lambda) {
            $key = $this->lambdaKey($lambda);
            if (isset($statusesByExecution[$key])) {
                $statuses[$key] = $statusesByExecution[$key];
            }
            if (isset($fitsByExecution[$key])) {
                $fits[$key] = $fitsByExecution[$key];
            }
        }

        return ['fits' => $fits, 'candidate_statuses' => $statuses, 'fit_order' => $fitOrder, 'failures' => $failures];
    }

    /** @param callable():iterable<array<string,mixed>> $raceSource @param list<float>|null $initial */
    public function fit(callable $raceSource, Bt03e02ParameterLayout $layout, float $lambda, ?array $initial = null): Bt03e08FitResultDto
    {
        if (! in_array($lambda, Bt03e08Contract::LAMBDA_GRID, true)) {
            throw new RuntimeException('BT-03E-08 lambda was outside the frozen grid.');
        }
        $current = $layout->project($initial ?? array_fill(0, $layout->size(), 0.0));
        $accelerated = $current;
        $momentum = 1.0;
        $step = Bt03e08Contract::INITIAL_STEP;
        $restarts = $backtrackingIterations = $restartRetentions = $acceptedUpdates = $attempts = 0;
        $lastRestartIteration = $lastPostRestartAccepted = 0;
        $lastRestartStep = $lastPostRestartStartStep = $step;
        $pendingRestartStep = null;
        $initialEvaluation = $this->objective->lossAndGradient($raceSource, $layout, $current);
        $previousObjective = $this->fullObjective($initialEvaluation['loss'], $layout, $current, $lambda);
        if (! is_finite($previousObjective)) {
            throw new RuntimeException('BT-03E-08 optimizer initial objective was non-finite.');
        }
        $lastDiagnostics = $this->diagnostics($lambda, 0, $previousObjective, $previousObjective, 0.0, 0.0, $step, $current, $initialEvaluation, 0, $restarts, $backtrackingIterations, $restartRetentions, $acceptedUpdates, $attempts, $lastRestartIteration, $lastRestartStep, $lastPostRestartStartStep, $lastPostRestartAccepted);
        for ($iteration = 1; $iteration <= Bt03e08Contract::MAX_ITERATIONS; $iteration++) {
            $attemptsWithinUpdate = 0;
            while (true) {
                if (++$attemptsWithinUpdate > 2) {
                    throw new RuntimeException('BT-03E-08 monotone restart retry invariant failed.');
                }
                $attempts++;
                $retryingAfterRestart = false;
                if ($pendingRestartStep !== null) {
                    if ($step !== $pendingRestartStep) {
                        throw new RuntimeException('BT-03E-08 monotone restart step was not retained.');
                    }
                    $restartRetentions++;
                    $lastPostRestartStartStep = $step;
                    $pendingRestartStep = null;
                    $retryingAfterRestart = true;
                }
                $evaluation = $this->objective->lossAndGradient($raceSource, $layout, $accelerated);
                $penaltyGradient = $this->objective->smoothPenaltyGradient($layout, $accelerated, $lambda);
                $gradient = array_map(static fn (float $loss, float $penalty): float => $loss + $penalty, $evaluation['gradient'], $penaltyGradient);
                $smoothAtAccelerated = $evaluation['loss'] + $this->objective->smoothPenalty($layout, $accelerated, $lambda);
                $accepted = $acceptedLoss = null;
                $trialStep = $step;
                for ($lineSearch = 0; $lineSearch <= Bt03e08Contract::MAX_LINE_SEARCH_STEPS; $lineSearch++) {
                    $trial = [];
                    foreach ($accelerated as $index => $value) {
                        $trial[$index] = $value - $trialStep * $gradient[$index];
                    }
                    $trial = $layout->project($this->objective->groupProx($layout, $trial, $trialStep, $lambda));
                    $trialLoss = $this->objective->loss($raceSource, $layout, $trial);
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
                    $trialStep *= Bt03e08Contract::BACKTRACK_FACTOR;
                }
                if ($accepted === null || $acceptedLoss === null) {
                    throw new RuntimeException('BT-03E-08 FISTA line search failed.');
                }
                if ($lineSearch > 0) {
                    $backtrackingIterations++;
                }
                $step = $trialStep;
                $acceptedObjective = $this->fullObjective($acceptedLoss, $layout, $accepted, $lambda);
                if (! is_finite($acceptedObjective)) {
                    throw new RuntimeException('BT-03E-08 optimizer produced a non-finite objective.');
                }
                if ($momentum > 1.0 && $acceptedObjective > $previousObjective) {
                    $restarts++;
                    $lastRestartIteration = $iteration;
                    $lastRestartStep = $trialStep;
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
                $acceptedUpdates++;
                if ($acceptedUpdates !== $iteration) {
                    throw new RuntimeException('BT-03E-08 accepted update accounting drifted.');
                }
                if ($retryingAfterRestart) {
                    $lastPostRestartAccepted = $iteration;
                }
                $lastDiagnostics = $this->diagnostics($lambda, $iteration, $acceptedObjective, $before, $relativeObjective, $maximumChange, $trialStep, $current, $evaluation, $lineSearch + 1, $restarts, $backtrackingIterations, $restartRetentions, $acceptedUpdates, $attempts, $lastRestartIteration, $lastRestartStep, $lastPostRestartStartStep, $lastPostRestartAccepted);
                if ($maximumChange <= Bt03e08Contract::CONVERGENCE_TOLERANCE && $relativeObjective <= Bt03e08Contract::OBJECTIVE_TOLERANCE) {
                    $lastDiagnostics['status'] = Bt03e03CandidateStatus::Converged->value;

                    return new Bt03e08FitResultDto($lambda, $current, $previousObjective, $acceptedUpdates, $evaluation['eligible_races'], $evaluation['excluded_races'], $lastDiagnostics);
                }
                break;
            }
        }
        throw new Bt03e08OptimizerNonConvergenceException($lastDiagnostics);
    }

    /** @param list<float> $coefficients @param array{eligible_races:int,excluded_races:int} $evaluation @return array<string,int|float|string> */
    private function diagnostics(float $lambda, int $iteration, float $objective, float $previous, float $relative, float $maximumChange, float $step, array $coefficients, array $evaluation, int $lineSearch, int $restarts, int $backtracking, int $retentions, int $accepted, int $attempts, int $lastRestartIteration, float $lastRestartStep, float $lastPostRestartStartStep, int $lastPostRestartAccepted): array
    {
        $squares = new Bt03e03CompensatedSum;
        $maximumAbsolute = 0.0;
        foreach ($coefficients as $coefficient) {
            $squares->add($coefficient * $coefficient);
            $maximumAbsolute = max($maximumAbsolute, abs($coefficient));
        }
        $diagnostics = [
            'status' => Bt03e03CandidateStatus::NumericallyNonConverged->value, 'lambda' => $lambda, 'position' => 'POSITION_3',
            'iteration' => $iteration, 'accepted_update_count' => $accepted, 'optimizer_attempt_count' => $attempts,
            'max_iterations' => Bt03e08Contract::MAX_ITERATIONS, 'final_objective' => $objective, 'previous_objective' => $previous,
            'relative_objective_change' => $relative, 'maximum_coefficient_change' => $maximumChange, 'current_step' => $step,
            'coefficient_l2_norm' => sqrt($squares->value()), 'maximum_absolute_coefficient' => $maximumAbsolute,
            'eligible_race_count' => $evaluation['eligible_races'], 'excluded_race_count' => $evaluation['excluded_races'],
            'line_search_steps_last_iteration' => $lineSearch, 'monotone_restart_count' => $restarts,
            'backtracking_iteration_count' => $backtracking, 'restart_step_retention_count' => $retentions,
            'last_monotone_restart_iteration' => $lastRestartIteration, 'last_monotone_restart_step' => $lastRestartStep,
            'last_post_restart_iteration_start_step' => $lastPostRestartStartStep,
            'last_post_restart_accepted_update_iteration' => $lastPostRestartAccepted,
        ];
        foreach ($diagnostics as $value) {
            if (is_float($value) && ! is_finite($value)) {
                throw new RuntimeException('BT-03E-08 optimizer diagnostics were non-finite.');
            }
        }

        return $diagnostics;
    }

    /** @param list<float> $coefficients */
    private function fullObjective(float $loss, Bt03e02ParameterLayout $layout, array $coefficients, float $lambda): float
    {
        return $loss + $this->objective->smoothPenalty($layout, $coefficients, $lambda) + $this->objective->groupPenalty($layout, $coefficients, $lambda);
    }

    private function lambdaKey(float $lambda): string
    {
        return sprintf('%.17g', $lambda);
    }
}
