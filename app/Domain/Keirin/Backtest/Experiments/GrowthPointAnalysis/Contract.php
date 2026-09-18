<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\GrowthPointAnalysis;

use RuntimeException;

final class Contract
{
    public const YEARS = [2024, 2025];

    public const SIGNALS = ['SCORE', 'PERFORMANCE', 'COMPOSITE'];

    public const QUANTILES = [0.10, 0.25, 0.40, 0.60, 0.75, 0.90];

    public static function plan(): array
    {
        return ['version' => 'GROWTH-POINT-ANALYSIS-01-v1', 'cohort' => [2024 => 25212, 2025 => 24866],
            'history_from' => '2022-01-01', 'history_to' => '2025-12-31', 'threshold_years' => [2024 => [2022, 2023], 2025 => [2022, 2023, 2024]],
            'score' => 'TARGET_HISTORICAL_ENTRY_SCORE_MINUS_PREV1_SCORE',
            'performance' => 'BATCH02_FORMAL_RESIDUAL_PREV1_MINUS_PREV2', 'residual' => '(N-rank)/(N-1) - (N-competition_score_rank)/(N-1)',
            'quantiles' => self::QUANTILES, 'quantile_type' => 7, 'point_boundary' => 'FIRST_MATCH_IN_SPECIFIED_ORDER; <=P10,<=P25,<=P40,<P60,<P75,<P90,ELSE',
            'composite' => 'SCORE_POINT_PLUS_PERFORMANCE_POINT_ONLY_WHEN_BOTH_AVAILABLE',
            'started' => ['FINISHED', 'TIED', 'DISQUALIFIED', 'CRASHED', 'DID_NOT_FINISH'],
            'normal' => ['FINISHED', 'TIED'], 'publication_time_verified' => 'UNKNOWN',
            'mode' => 'BACKFILLED_FINAL_RESULT / DEVELOPMENT_ONLY / OBSERVED_DB_HISTORY',
            'diagnostic' => ['minimum_normal' => 1000, 'minimum_players' => 30, 'minimum_races' => 100,
                'minimum_point_normal' => 30, 'minimum_occupied_points' => 3, 'rho_floor' => 0.03,
                'no_clear_win_span' => 0.02, 'no_clear_top3_span' => 0.03, 'monotonic_violations_allowed' => 0],
            'inference_training_gate' => 'NONE', 'holdout_2026' => 'FORBIDDEN'];
    }

    public static function year(int $year): void
    {
        if ($year < 2022 || $year > 2025) {
            throw new RuntimeException('Only development years 2022-2025 are allowed, before any query.');
        }
    }

    public static function point(?float $raw, ?array $q): ?int
    {
        if ($raw === null || $q === null) {
            return null;
        }
        if (! is_finite($raw) || count($q) !== 6) {
            throw new RuntimeException('Invalid quantile input.');
        }
        foreach ($q as $i => $value) {
            if ((! is_float($value) && ! is_int($value)) || ! is_finite((float) $value) || ($i > 0 && $q[$i - 1] > $value)) {
                throw new RuntimeException('Quantiles must be finite and nondecreasing.');
            }
        }

        return match (true) {
            $raw <= $q[0] => -3, $raw <= $q[1] => -2, $raw <= $q[2] => -1,
            $raw < $q[3] => 0, $raw < $q[4] => 1, $raw < $q[5] => 2, default => 3,
        };
    }
}
