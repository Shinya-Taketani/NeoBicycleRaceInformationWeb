<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Exceptions;

use RuntimeException;

final class Bt03e07OptimizerNonConvergenceException extends RuntimeException
{
    /** @param array<string,int|float|string> $diagnostics */
    public function __construct(public readonly array $diagnostics)
    {
        parent::__construct(sprintf(
            'BT-03E-07 %s lambda %.17g was numerically non-converged after %d accepted updates.',
            $diagnostics['position'],
            $diagnostics['lambda'],
            $diagnostics['accepted_update_count'],
        ));
    }
}
