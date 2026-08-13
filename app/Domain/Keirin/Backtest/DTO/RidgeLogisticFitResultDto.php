<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\DTO;

use App\Domain\Keirin\Backtest\Enums\Bt02ConvergenceStatus;

readonly class RidgeLogisticFitResultDto
{
    /** @param list<float> $coefficients */
    public function __construct(
        public Bt02ConvergenceStatus $status,
        public float $intercept,
        public array $coefficients,
        public int $iterations,
        public ?float $finalObjective,
    ) {}

    public function converged(): bool
    {
        return $this->status->converged();
    }
}
