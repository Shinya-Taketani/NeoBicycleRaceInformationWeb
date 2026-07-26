<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\Enums;

enum StatisticRunStatus: string
{
    case Running = 'RUNNING';
    case Succeeded = 'SUCCEEDED';
    case PartiallyFailed = 'PARTIALLY_FAILED';
    case Failed = 'FAILED';
    case NoTargets = 'NO_TARGETS';
}
