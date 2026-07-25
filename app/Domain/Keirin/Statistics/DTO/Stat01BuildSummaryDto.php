<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\DTO;

use App\Models\StatisticCalculationRun;

final readonly class Stat01BuildSummaryDto
{
    /**
     * @param  list<string>  $errors
     */
    public function __construct(
        public ?StatisticCalculationRun $run,
        public int $targetRaceCount,
        public int $processedRaceCount,
        public int $targetCount,
        public int $successCount,
        public int $partialCount,
        public int $missingCount,
        public int $invalidCount,
        public int $errorCount,
        public array $errors,
        public bool $dryRun,
    ) {}

    public function hasTargets(): bool
    {
        return $this->targetRaceCount > 0;
    }
}
