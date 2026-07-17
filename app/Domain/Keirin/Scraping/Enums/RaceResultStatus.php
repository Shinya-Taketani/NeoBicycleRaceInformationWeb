<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\Enums;

enum RaceResultStatus: string
{
    case Unavailable = 'UNAVAILABLE';
    case Provisional = 'PROVISIONAL';
    case UnderReview = 'UNDER_REVIEW';
    case Confirmed = 'CONFIRMED';
    case Corrected = 'CORRECTED';
    case Cancelled = 'CANCELLED';
}
