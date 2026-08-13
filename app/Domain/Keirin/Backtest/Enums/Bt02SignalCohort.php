<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Enums;

enum Bt02SignalCohort: string
{
    case Strict = 'STRICT';
    case Operational = 'OPERATIONAL';
}
