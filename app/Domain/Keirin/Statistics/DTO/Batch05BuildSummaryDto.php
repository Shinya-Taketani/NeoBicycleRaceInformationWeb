<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\DTO;

readonly class Batch05BuildSummaryDto
{
    public function __construct(
        public string $batchExecutionUuid,
        public bool $dryRun,
        public ?int $runId,
        public int $targetRaces,
        public int $targetEntries,
        public int $processedRaces,
        public int $resultCount,
        public int $validCount,
        public int $partialCount,
        public int $missingCount,
        public int $invalidCount,
        public int $errorCount,
    ) {}
}
