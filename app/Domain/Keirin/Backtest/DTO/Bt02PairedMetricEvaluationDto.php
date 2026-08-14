<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\DTO;

readonly class Bt02PairedMetricEvaluationDto
{
    /**
     * @param  array<string, array{baseline: ?float, incremental: ?float, delta: ?float, ci_lower: ?float, ci_upper: ?float}>  $metrics
     * @param  list<int>  $raceIds
     */
    public function __construct(
        public array $metrics,
        public array $raceIds,
        public int $rowCount,
        public int $bootstrapReplicateCount,
        public int $temporaryByteCount,
    ) {}
}
