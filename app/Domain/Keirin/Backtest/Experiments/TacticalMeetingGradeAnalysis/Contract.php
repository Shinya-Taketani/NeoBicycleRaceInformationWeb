<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\TacticalMeetingGradeAnalysis;

use App\Domain\Keirin\Backtest\Experiments\TacticalGradeAnalysis\Contract as RiderContract;

final class Contract
{
    public const GRADES = ['GP', 'G1', 'G2', 'G3', 'F1', 'F2', 'UNKNOWN'];

    public const METRICS = ['P1' => 'POSITION_1_ACCURACY', 'P2' => 'POSITION_2_ACCURACY',
        'P3' => 'POSITION_3_ACCURACY', 'H3' => 'POSITION_HIT_RATE_AT_3'];

    public static function plan(): array
    {
        return ['version' => 'TACTICAL-MEETING-GRADE-ANALYSIS-01-v1', 'years' => [2024, 2025],
            'counts' => RiderContract::COUNTS, 'grades' => self::GRADES, 'metrics' => self::METRICS,
            'normalization' => Classification::MAP,
            'source' => 'REVIEWED_RUN_01_OUTER_C1_V2_VIA_LOCKED_RIDER_ANALYSIS',
            'primary' => 'ONE_MEETING_GRADE_PER_RACE_AND_MEETING',
            'fallback' => 'MISSING_MEETING_GRADE_ONLY; ALL_TARGET_RACES_SAME_JSJ001_HEADER_GRADE',
            'conflict_or_unknown' => 'KEEP_AS_UNKNOWN_NO_MAJORITY',
            'hit_at_3' => 'UNIQUE_OFFICIAL_1_2_3_ONLY; MATCHED_POSITIONS/(3*ELIGIBLE_RACES)',
            'pooling' => 'COUNT_WEIGHTED_SUM_NUMERATOR_DIVIDED_BY_SUM_DENOMINATOR',
            'ci' => 'P1_P2_P3_WILSON_REFERENCE_UNCORRECTED_PLAYER_MEETING_CORRELATION; H3_NOT_COMPUTED',
            'inference' => 'NONE', 'training' => 'NONE', 'gate_bootstrap' => 'NONE', 'holdout_2026' => 'FORBIDDEN'];
    }
}
