<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Enums;

enum Bt02ConvergenceStatus: string
{
    case ConvergedGradient = 'CONVERGED_GRADIENT';
    case ConvergedStepObjective = 'CONVERGED_STEP_OBJECTIVE';
    case FailedSingleClassTraining = 'FAILED_SINGLE_CLASS_TRAINING';
    case FailedNonFinite = 'FAILED_NON_FINITE';
    case FailedCholesky = 'FAILED_CHOLESKY';
    case FailedLineSearch = 'FAILED_LINE_SEARCH';
    case FailedMaxIterations = 'FAILED_MAX_ITERATIONS';

    public function converged(): bool
    {
        return str_starts_with($this->value, 'CONVERGED_');
    }
}
