<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\Enums;

enum AgariStatus: string
{
    case Valid = 'VALID';
    case Missing = 'MISSING';
    case InvalidFormat = 'INVALID_FORMAT';
    case ObservedAbnormalResult = 'OBSERVED_ABNORMAL_RESULT';
}
