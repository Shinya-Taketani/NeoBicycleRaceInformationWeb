<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\DTO;

readonly class Bt02PreflightProgressDto
{
    public function __construct(
        public int $index,
        public int $total,
        public int $year,
        public string $statCode,
        public int $runId,
        public string $stage,
    ) {}
}
