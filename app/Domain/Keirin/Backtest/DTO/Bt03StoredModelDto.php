<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\DTO;

readonly class Bt03StoredModelDto
{
    /**
     * @param  list<string>  $featureNames
     * @param  array<string, float>  $scalerMean
     * @param  array<string, float>  $scalerSd
     * @param  list<float>  $lambdaCandidates
     * @param  list<float>  $coefficients
     */
    public function __construct(
        public int $modelId,
        public int $sourceRunId,
        public int $sourceFoldId,
        public int $sourceSignalSpecId,
        public string $foldCode,
        public string $statCode,
        public string $primaryFeatureCode,
        public string $cohortCode,
        public string $labelCode,
        public string $modelRole,
        public array $featureNames,
        public array $scalerMean,
        public array $scalerSd,
        public array $lambdaCandidates,
        public float $selectedLambda,
        public float $intercept,
        public array $coefficients,
        public string $objectiveVersion,
        public string $optimizerVersion,
        public string $probabilitySemantics,
        public string $convergenceStatus,
        public string $modelHash,
    ) {}
}
