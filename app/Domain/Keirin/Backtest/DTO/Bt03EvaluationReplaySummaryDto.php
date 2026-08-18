<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\DTO;

readonly class Bt03EvaluationReplaySummaryDto
{
    /** @param list<Bt03ComputedBinEffectDto> $effects */
    public function __construct(
        public string $foldCode,
        public string $statCode,
        public string $cohortCode,
        public int $evaluationRowCount,
        public int $evaluationRaceCount,
        public int $trainingBinCount,
        public int $unseenRowCount,
        public int $spoolFileCount,
        public int $spoolByteCount,
        public int $maximumBinSampleCount,
        public int $maximumBinRaceCount,
        public array $effects,
    ) {}
}
