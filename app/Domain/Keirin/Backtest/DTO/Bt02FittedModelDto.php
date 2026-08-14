<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\DTO;

readonly class Bt02FittedModelDto
{
    /** @param list<string> $featureNames */
    public function __construct(
        public array $featureNames,
        public StandardizationModelDto $standardization,
        public float $selectedLambda,
        public RidgeLogisticFitResultDto $fit,
        public string $modelHash,
    ) {}
}
