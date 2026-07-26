<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\Enums;

enum StatInputAsOfPolicy: string
{
    case SalesClose = 'SALES_CLOSE';
    case StartTime = 'START_TIME';
    case Unavailable = 'INPUT_AS_OF_UNAVAILABLE';
}
