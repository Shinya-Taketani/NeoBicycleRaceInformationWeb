<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Exceptions;

use RuntimeException;

final class Bt03e03OptimizerNonConvergenceException extends RuntimeException
{
    /** @param array<string,int|float|string> $diagnostics */
    public function __construct(public readonly array $diagnostics)
    {
        parent::__construct(sprintf(
            'BT-03E-03 %s FISTA did not converge for lambda %.17g within the frozen iteration limit.',
            $diagnostics['position'],
            $diagnostics['lambda'],
        ));
    }
}
