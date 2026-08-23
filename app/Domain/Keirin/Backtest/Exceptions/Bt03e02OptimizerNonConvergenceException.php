<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Exceptions;

use RuntimeException;

final class Bt03e02OptimizerNonConvergenceException extends RuntimeException
{
    /**
     * @param array{
     *   status:string,
     *   lambda:float,
     *   channel:string,
     *   iteration:int,
     *   max_iterations:int,
     *   final_objective:float,
     *   previous_objective:float,
     *   relative_objective_change:float,
     *   maximum_coefficient_change:float,
     *   current_step:float,
     *   coefficient_l2_norm:float,
     *   maximum_absolute_coefficient:float,
     *   eligible_race_count:int,
     *   excluded_race_count:int,
     *   line_search_steps_last_iteration:int
     * } $diagnostics
     */
    public function __construct(public readonly array $diagnostics)
    {
        parent::__construct(sprintf(
            'BT-03E-02 %s FISTA did not converge for lambda %.17g within the frozen iteration limit.',
            $diagnostics['channel'],
            $diagnostics['lambda'],
        ));
    }
}
