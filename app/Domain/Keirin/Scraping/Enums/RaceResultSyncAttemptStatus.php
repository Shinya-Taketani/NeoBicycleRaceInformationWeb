<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\Enums;

enum RaceResultSyncAttemptStatus: string
{
    case Succeeded = 'SUCCEEDED';
    case Skipped = 'SKIPPED';
    case FailedPermanent = 'FAILED_PERMANENT';
    case FailedTransient = 'FAILED_TRANSIENT';
}
