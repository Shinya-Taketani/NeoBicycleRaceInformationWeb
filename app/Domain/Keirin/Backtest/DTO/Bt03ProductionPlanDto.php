<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\DTO;

readonly class Bt03ProductionPlanDto
{
    /** @param array<string, bool> $schemaReadiness */
    public function __construct(
        public int $sourceRunId,
        public int $foldCount,
        public int $statCount,
        public int $cohortCount,
        public int $scopeCount,
        public int $sourceBinCount,
        public int $baseEffectCount,
        public int $bootstrapIterations,
        public int $bootstrapSeed,
        public array $schemaReadiness,
    ) {}
}
