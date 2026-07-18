<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\Enums;

enum ParsedRaceResultPageStatus: string
{
    case ResultsAvailable = 'RESULTS_AVAILABLE';
    case Unavailable = 'UNAVAILABLE';
    case UnderReview = 'UNDER_REVIEW';
    case Cancelled = 'CANCELLED';
}
