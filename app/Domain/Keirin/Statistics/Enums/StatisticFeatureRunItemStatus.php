<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\Enums;

enum StatisticFeatureRunItemStatus: string
{
    case Pending = 'PENDING';
    case Running = 'RUNNING';
    case Succeeded = 'SUCCEEDED';
    case Partial = 'PARTIAL';
    case Failed = 'FAILED';
    case Skipped = 'SKIPPED';
}
