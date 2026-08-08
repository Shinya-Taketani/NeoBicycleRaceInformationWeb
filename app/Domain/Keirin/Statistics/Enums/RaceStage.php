<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\Enums;

enum RaceStage: string
{
    case Qualifying = 'QUALIFYING';
    case FirstQualifying = 'FIRST_QUALIFYING';
    case SecondQualifying = 'SECOND_QUALIFYING';
    case Preliminary = 'PRELIMINARY';
    case Selection = 'SELECTION';
    case SpecialSelection = 'SPECIAL_SELECTION';
    case Semifinal = 'SEMIFINAL';
    case Final = 'FINAL';
    case General = 'GENERAL';
    case Consolation = 'CONSOLATION';
    case LoserStage = 'LOSER_STAGE';
    case PointsStage = 'POINTS_STAGE';
    case PlacementRace = 'PLACEMENT_RACE';
    case Other = 'OTHER';
    case Unknown = 'UNKNOWN';
}
