<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\Enums;

enum BatchRunItemStatus: string
{
    case Pending = 'PENDING';
    case Running = 'RUNNING';
    case Succeeded = 'SUCCEEDED';
    case Failed = 'FAILED';
    case Skipped = 'SKIPPED';
    case SkippedUnsupportedCategory = 'SKIPPED_UNSUPPORTED_CATEGORY';
}
