<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\DTO;

use App\Domain\Keirin\Backtest\Enums\BacktestExclusionReason;

readonly class EligibilityDecisionDto
{
    /** @param list<BacktestExclusionReason> $reasons */
    public function __construct(public bool $eligible, public array $reasons) {}
}
