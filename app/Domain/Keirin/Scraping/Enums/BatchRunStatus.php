<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\Enums;

enum BatchRunStatus: string
{
    case Running = 'RUNNING';
    case Succeeded = 'SUCCEEDED';
    case PartiallyFailed = 'PARTIALLY_FAILED';
    case Failed = 'FAILED';
}
