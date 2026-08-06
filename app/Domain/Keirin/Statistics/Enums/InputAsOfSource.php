<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\Enums;

enum InputAsOfSource: string
{
    case SalesCloseAt = 'SALES_CLOSE_AT';
    case ScheduledStartAtFallback = 'SCHEDULED_START_AT_FALLBACK';
    case Missing = 'MISSING';
}
