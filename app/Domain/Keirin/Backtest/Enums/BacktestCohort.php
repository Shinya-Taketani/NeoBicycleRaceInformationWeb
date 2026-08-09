<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Enums;

enum BacktestCohort: string
{
    case Operational = 'OPERATIONAL';
    case NormalFinish = 'NORMAL_FINISH';
}
