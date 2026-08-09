<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\DTO;

use App\Domain\Keirin\Backtest\Enums\BacktestExclusionReason;

readonly class CohortDecisionDto
{
    /** @param list<BacktestExclusionReason> $reasons */
    public function __construct(
        public bool $included,
        public array $reasons,
        public bool $rank1Hit = false,
        public bool $top3Hit = false,
        public int $rank1SetSize = 0,
        public int $top3SetSize = 0,
    ) {}
}
