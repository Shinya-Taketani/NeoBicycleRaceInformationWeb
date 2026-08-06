<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\Enums;

enum StatisticAcquisitionMode: string
{
    case Backfill = 'BACKFILL';
}
