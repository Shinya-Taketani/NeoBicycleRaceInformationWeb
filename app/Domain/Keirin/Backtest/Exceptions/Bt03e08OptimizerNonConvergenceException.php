<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Exceptions;

use RuntimeException;

final class Bt03e08OptimizerNonConvergenceException extends RuntimeException
{
    /** @param array<string,int|float|string> $diagnostics */
    public function __construct(public readonly array $diagnostics)
    {
        parent::__construct('BT-03E-08 P3 optimizer did not converge within the frozen accepted-update budget.');
    }
}
