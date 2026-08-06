<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\Enums;

enum StatisticFeatureRunStatus: string
{
    case Running = 'RUNNING';
    case Succeeded = 'SUCCEEDED';
    case PartiallySucceeded = 'PARTIALLY_SUCCEEDED';
    case Failed = 'FAILED';
}
