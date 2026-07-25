<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\Enums;

enum StatFeatureStatus: string
{
    case Valid = 'VALID';
    case NotApplicable = 'NOT_APPLICABLE';
    case NoHistory = 'NO_HISTORY';
    case InsufficientSample = 'INSUFFICIENT_SAMPLE';
    case PartialHistory = 'PARTIAL_HISTORY';
    case MissingInput = 'MISSING_INPUT';
    case Degraded = 'DEGRADED';
    case ConflictedInput = 'CONFLICTED_INPUT';
    case InvalidInput = 'INVALID_INPUT';
    case LeakageRisk = 'LEAKAGE_RISK';
    case Blocked = 'BLOCKED';
    case Error = 'ERROR';
}
