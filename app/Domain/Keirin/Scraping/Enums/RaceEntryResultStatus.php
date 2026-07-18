<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\Enums;

enum RaceEntryResultStatus: string
{
    case Finished = 'FINISHED';
    case Tied = 'TIED';
    case Disqualified = 'DISQUALIFIED';
    case DidNotStart = 'DID_NOT_START';
    case DidNotFinish = 'DID_NOT_FINISH';
    case Withdrawn = 'WITHDRAWN';
    case Crashed = 'CRASHED';
}
