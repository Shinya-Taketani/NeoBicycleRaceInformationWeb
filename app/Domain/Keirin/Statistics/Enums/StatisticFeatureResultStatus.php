<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\Enums;

enum StatisticFeatureResultStatus: string
{
    case Valid = 'VALID';
    case Partial = 'PARTIAL';
    case MissingInput = 'MISSING_INPUT';
    case InvalidInput = 'INVALID_INPUT';
    case NoHistory = 'NO_HISTORY';
    case PartialHistory = 'PARTIAL_HISTORY';
}
