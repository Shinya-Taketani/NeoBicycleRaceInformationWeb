<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\DTO;

use DateTimeImmutable;

readonly class Bt03EvaluationSourceDto
{
    /**
     * @param  array<string, Bt03ModelPairDto>  $modelPairs
     * @param  list<Bt03SourceBinDto>  $bins
     */
    public function __construct(
        public int $sourceRunId,
        public int $sourceFoldId,
        public string $foldCode,
        public DateTimeImmutable $evaluationFrom,
        public DateTimeImmutable $evaluationTo,
        public int $sourceSignalSpecId,
        public string $statCode,
        public string $primaryFeatureCode,
        public string $cohortCode,
        public array $modelPairs,
        public array $bins,
    ) {}
}
