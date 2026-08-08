<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\Support;

use App\Domain\Keirin\Statistics\Enums\RaceStage;

class RaceStageNormalizer
{
    public const VERSION = 'RACE-STAGE-existing-db-v1';

    public function normalize(?string $raceType): RaceStage
    {
        $value = $raceType !== null ? trim($raceType) : '';
        if ($value === '') {
            return RaceStage::Unknown;
        }

        return match (true) {
            str_contains($value, '準決') => RaceStage::Semifinal,
            str_contains($value, '決勝') => RaceStage::Final,
            str_contains($value, '一予') => RaceStage::FirstQualifying,
            str_contains($value, '二予') => RaceStage::SecondQualifying,
            str_contains($value, '特一般'), str_contains($value, '一般') => RaceStage::General,
            str_contains($value, '初特選'), str_contains($value, '特選'), str_contains($value, '特秀') => RaceStage::SpecialSelection,
            str_contains($value, '選抜'), str_contains($value, '優秀') => RaceStage::Selection,
            str_contains($value, '予選'), preg_match('/予[１２12]$/u', $value) === 1 => RaceStage::Qualifying,
            str_contains($value, '慰安') => RaceStage::Consolation,
            str_contains($value, '敗者') => RaceStage::LoserStage,
            str_contains($value, 'ポイント') => RaceStage::PointsStage,
            str_contains($value, '順位決') => RaceStage::PlacementRace,
            str_contains($value, '予備') => RaceStage::Preliminary,
            default => RaceStage::Other,
        };
    }
}
