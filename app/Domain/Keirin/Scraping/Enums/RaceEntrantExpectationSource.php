<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\Enums;

enum RaceEntrantExpectationSource: string
{
    case RaceEntries = 'RACE_ENTRIES';
    case RaceEntrantCount = 'RACE_ENTRANT_COUNT';
}
