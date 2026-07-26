<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\Enums;

enum RaceScoreValidationStatus: string
{
    case Valid = 'VALID';
    case Missing = 'MISSING';
    case InvalidFormat = 'INVALID_FORMAT';
    case NonPositive = 'NON_POSITIVE';
    case OutOfStorageRange = 'OUT_OF_STORAGE_RANGE';
    case SourceConflict = 'SOURCE_CONFLICT';
}
