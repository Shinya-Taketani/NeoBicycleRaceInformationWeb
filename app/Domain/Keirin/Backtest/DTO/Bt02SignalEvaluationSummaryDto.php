<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\DTO;

readonly class Bt02SignalEvaluationSummaryDto
{
    public function __construct(
        public int $runId,
        public string $runUuid,
        public int $foldCount,
        public int $signalCount,
        public int $modelCount,
        public int $metricCount,
    ) {}
}
