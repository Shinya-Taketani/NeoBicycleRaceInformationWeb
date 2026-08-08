<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\DTO;

readonly class Batch03StatSummaryDto
{
    public function __construct(
        public string $statCode,
        public ?int $runId,
        public int $processedRaces,
        public int $resultCount,
        public int $validCount,
        public int $noHistoryCount,
        public int $notApplicableCount,
        public int $partialHistoryCount,
        public int $partialCount,
        public int $missingCount,
        public int $invalidCount,
        public int $errorCount,
    ) {}
}
