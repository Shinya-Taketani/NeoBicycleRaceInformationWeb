<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\Enums;

enum StatisticQualityStatus: string
{
    case Full = 'FULL';
    case Partial = 'PARTIAL';
    case Degraded = 'DEGRADED';
}
