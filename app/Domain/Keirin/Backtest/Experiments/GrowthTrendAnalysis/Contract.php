<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\GrowthTrendAnalysis;

final class Contract
{
    public static function grid(): array
    {
        $grid = [];
        foreach (['MEETING_DELTA' => range(1, 6), 'MEETING_OLS' => range(2, 12), 'MEETING_THEIL_SEN' => range(3, 12),
            'DAY_OLS' => [30, 60, 90, 120, 180, 240, 365], 'DAY_THEIL_SEN' => [30, 60, 90, 120, 180, 240, 365]] as $family => $grains) {
            foreach ($grains as $grain) {
                $grid[] = ['id' => $family.($family === 'MEETING_DELTA' ? '_LAG_' : '_SLOPE_').$grain, 'family' => $family, 'grain' => $grain];
            }
        }

        return $grid;
    }

    public static function plan(): array
    {
        return ['version' => 'GROWTH-TREND-ANALYSIS-01-SCORE-OBSERVATION-v1', 'grid' => self::grid(),
            'mode' => 'DEVELOPMENT_BACKFILLED_SCORE_OBSERVATION', 'years' => [2024, 2025], 'history_from' => '2022-01-01',
            'selection' => 'MULTI_YEAR_DEVELOPMENT_STABILITY_SELECTION', 'coverage_fraction' => 0.8, 'minimum_normal' => 10000,
            'minimum_conditional_bin' => 100, 'quantiles' => 'TYPE7_NATURAL_TIES', 'rho' => 'SPEARMAN_AVERAGE_TIES',
            'fp' => '(N-rank)/(N-1); FINISHED/TIED_ONLY', 'robust_score' => 'MIN_OF_8_YEAR_CONDITION_RHOS',
            'tie_order' => 'ROBUST_DESC_MIN_COVERAGE_DESC_ID_ASC', 'database' => 'NONE', 'holdout_2026' => 'FORBIDDEN',
            'weight_training_bootstrap_gate' => 'NONE', 'publication_timing' => 'UNKNOWN'];
    }
}
