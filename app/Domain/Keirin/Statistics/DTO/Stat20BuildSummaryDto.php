<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\DTO;

readonly class Stat20BuildSummaryDto
{
    public function __construct(
        public ?int $runId,
        public bool $dryRun,
        public int $targetRaces,
        public int $targetEntries,
        public array $counts,
    ) {}
}
