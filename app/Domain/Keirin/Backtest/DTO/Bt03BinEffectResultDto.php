<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\DTO;

readonly class Bt03BinEffectResultDto
{
    public function __construct(
        public string $evaluationStatus,
        public int $evaluationSampleCount,
        public int $evaluationRaceCount,
        public int $positiveCount,
        public ?float $observedRate,
        public ?float $observedRateCiLower,
        public ?float $observedRateCiUpper,
        public ?float $baselineMeanProbability,
        public ?float $incrementalMeanProbability,
        public ?float $baselineResidualMean,
        public ?float $baselineResidualCiLower,
        public ?float $baselineResidualCiUpper,
        public ?float $incrementalResidualMean,
        public ?float $incrementalResidualCiLower,
        public ?float $incrementalResidualCiUpper,
        public ?float $probabilityShiftMean,
        public ?float $probabilityShiftCiLower,
        public ?float $probabilityShiftCiUpper,
        public ?float $logLossDelta,
        public ?float $logLossDeltaCiLower,
        public ?float $logLossDeltaCiUpper,
        public ?float $brierDelta,
        public ?float $brierDeltaCiLower,
        public ?float $brierDeltaCiUpper,
        public int $bootstrapIterations,
        public int $bootstrapSeed,
    ) {}
}
