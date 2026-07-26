<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\Enums;

enum StatDataQualityStatus: string
{
    case Valid = 'VALID';
    case Partial = 'PARTIAL';
    case Degraded = 'DEGRADED';
    case Blocked = 'BLOCKED';
    case LeakageRisk = 'LEAKAGE_RISK';
    case Error = 'ERROR';
}
