<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\Enums;

enum StatisticAcquisitionMode: string
{
    case LivePreRace = 'LIVE_PRE_RACE';
    case HistoricalRaceCard = 'HISTORICAL_RACE_CARD';
    case Unknown = 'UNKNOWN_ACQUISITION_MODE';
}
