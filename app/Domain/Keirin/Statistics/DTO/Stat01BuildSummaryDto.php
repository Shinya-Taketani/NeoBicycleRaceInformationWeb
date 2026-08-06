<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\DTO;

readonly class Stat01BuildSummaryDto
{
    public function __construct(
        public ?int $runId,
        public ?string $runUuid,
        public bool $dryRun,
        public int $targetRaceCount,
        public int $processedRaceCount,
        public int $targetEntryCount,
        public int $successCount,
        public int $partialCount,
        public int $missingCount,
        public int $invalidCount,
        public int $errorCount,
    ) {}
}
