<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\TacticalGradeAnalysis;

use RuntimeException;

final class Contract
{
    public const YEARS = [2024, 2025];

    public const GRADES = ['SS', 'S1', 'S2', 'A1', 'A2', 'A3', 'UNKNOWN'];

    public const METRICS = [1 => 'POSITION_1_ACCURACY', 2 => 'POSITION_2_ACCURACY', 3 => 'POSITION_3_ACCURACY'];

    public const COUNTS = [2024 => 25212, 2025 => 24866];

    public const DENOMINATORS = [2024 => [1 => 25158, 2 => 25106, 3 => 25094], 2025 => [1 => 24789, 2 => 24727, 3 => 24739]];

    public static function plan(): array
    {
        return ['version' => 'TACTICAL-GRADE-ANALYSIS-01-v1', 'mode' => 'SAVED_OUTER_C1_DEVELOPMENT_BREAKDOWN',
            'years' => self::YEARS, 'races' => self::COUNTS, 'denominators' => self::DENOMINATORS,
            'grades' => self::GRADES, 'normalization' => Grade::MAP, 'metrics' => self::METRICS,
            'aggregation' => ['year_grade_position', 'year_entrants_grade_position', 'count_weighted_pooled', 'year_stage_grade_position'],
            'interval' => 'WILSON_95_NO_CONTINUITY_CORRECTION', 'z' => 1.959963984540054,
            'publication_time_verified' => 'UNKNOWN', 'database' => 'READ_ONLY_TARGET_IDS_AND_2024_2025_METADATA_ONLY',
            'prediction_regeneration' => false, 'gate' => 'NOT_APPLICABLE', 'holdout_2026' => 'FORBIDDEN'];
    }

    public static function race(array $row): void
    {
        if (! in_array($row['year'] ?? null, self::YEARS, true) || ! is_int($row['race_id'] ?? null) || $row['race_id'] < 1) {
            throw new RuntimeException('Grade analysis requires a valid 2024/2025 race identity.');
        }
    }
}
