<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\DTO;

readonly class Bt01BuildSummaryDto
{
    public function __construct(
        public ?int $runId,
        public ?string $runUuid,
        public bool $dryRun,
        public int $targetRaces,
        public int $predictedRaces,
        public int $excludedRaces,
        public int $errors,
    ) {}
}
