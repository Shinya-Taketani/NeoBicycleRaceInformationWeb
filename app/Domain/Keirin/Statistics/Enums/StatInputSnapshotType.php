<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\Enums;

enum StatInputSnapshotType: string
{
    case LivePreRaceCard = 'LIVE_PRE_RACE_CARD';
    case HistoricalRaceCardBackfill = 'HISTORICAL_RACE_CARD_BACKFILL';
    case UnknownSourceTiming = 'UNKNOWN_SOURCE_TIMING';
    case CurrentPlayerProfile = 'CURRENT_PLAYER_PROFILE';
}
