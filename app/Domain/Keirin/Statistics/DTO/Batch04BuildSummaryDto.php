<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\DTO;

readonly class Batch04BuildSummaryDto
{
    /** @param list<Batch04StatSummaryDto> $stats */
    public function __construct(
        public string $batchExecutionUuid,
        public bool $dryRun,
        public int $targetRaces,
        public int $targetEntries,
        public array $stats,
    ) {}
}
