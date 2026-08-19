<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\DTO;

readonly class Bt03ProductionSummaryDto
{
    public function __construct(
        public int $runId,
        public string $runUuid,
        public int $scopeCount,
        public int $completedScopeCount,
        public int $skippedScopeCount,
        public int $effectCount,
        public int $unseenScopeCount,
        public string $effectManifestHash,
    ) {}
}
