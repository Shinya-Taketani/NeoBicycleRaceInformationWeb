<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Enums;

enum Bt03e02CandidateStatus: string
{
    case Converged = 'CONVERGED';
    case NumericallyNonConverged = 'NUMERICALLY_NON_CONVERGED';
}
