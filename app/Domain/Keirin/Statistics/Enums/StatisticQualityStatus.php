<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\Enums;

enum StatisticQualityStatus: string
{
    case Valid = 'VALID';
    case Partial = 'PARTIAL';
    case MissingInput = 'MISSING_INPUT';
    case InvalidInput = 'INVALID_INPUT';
    case HistoricalSnapshot = 'HISTORICAL_SNAPSHOT';
    case LeakageRisk = 'LEAKAGE_RISK';
    case Blocked = 'BLOCKED';
    case Error = 'ERROR';
}
