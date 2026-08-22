<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\DTO;

readonly class Bt03eMetricSummaryDto
{
    /**
     * @param  array<string, float>  $metrics
     * @param  array<string, int>  $orderedExclusionReasons
     * @param  array<string, int>  $positionExclusionReasons
     */
    public function __construct(
        public array $metrics,
        public int $raceCount,
        public int $entryCount,
        public int $uniquePosition1RaceCount,
        public int $uniquePosition2RaceCount,
        public int $uniquePosition3RaceCount,
        public int $orderedEligibleRaceCount,
        public int $orderedExcludedRaceCount,
        public array $orderedExclusionReasons,
        public array $positionExclusionReasons,
        public int $scoreTiedRaceCount,
        public int $scoreTiedEntryCount,
        public int $stat01TieBreakUsageCount,
    ) {}
}
