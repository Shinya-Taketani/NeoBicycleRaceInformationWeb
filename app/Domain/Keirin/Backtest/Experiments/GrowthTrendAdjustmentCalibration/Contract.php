<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\GrowthTrendAdjustmentCalibration;

use App\Domain\Keirin\Backtest\Experiments\GrowthAdjustmentCalibration\Contract as Previous;
use RuntimeException;

final class Contract
{
    public const VERSION = 'GROWTH-TREND-ADJUSTMENT-CALIBRATION-01-v1';

    public const SIGNAL = 'MEETING_DELTA_LAG_1';

    public const YEARS = Previous::YEARS;

    public const METRICS = Previous::METRICS;

    public static function grid(): array
    {
        return array_map(fn (int $k) => ['k' => $k, 'w' => $k / 100, 'plus1_vs_zero_odds' => exp($k / 100),
            'minus1_vs_zero_odds' => exp(-$k / 100), 'plus1_vs_minus1_odds' => exp(2 * $k / 100)], range(-50, 50));
    }

    public static function plan(): array
    {
        return ['version' => self::VERSION, 'signal' => self::SIGNAL, 'years' => self::YEARS,
            'scaling' => '2024 VALID abs(raw) TYPE7 P99; finite and >0', 'normalization' => 'clamp(raw / SCALE_P99, -1, 1); invalid=NULL',
            'adjustment' => 'anchor + (k/100)*normalized; NULL/zero/k=0 preserves anchor exactly; all positions',
            'grid' => self::grid(), 'eligibility' => ['position_delta_gte' => -0.003, 'hit3_delta_gte' => 0.0],
            'selection_order' => ['MAX_HIT3', 'MIN_ABS_K', 'MIN_K'], 'selection_year' => 2024,
            'year_roles' => [2024 => 'PARAMETER_SELECTION_DEVELOPMENT', 2025 => 'POST_SELECTION_DEVELOPMENT_TRANSFER_DIAGNOSTIC'],
            'transfer' => 'nonzero: H3 delta>0 and P1/P2/P3 delta>=-0.003', 'pooled' => 'COUNT_WEIGHTED_DIAGNOSTIC_ONLY',
            'state_strata' => 'RACE_CONTAINS_GROUP; DISTINCT_WITHIN_GROUP; OVERLAPPING_NOT_ADDITIVE',
            'first_strata' => ['ALL', 'ALL_ENTRIES_TRUE', 'ANY_ENTRY_TRUE'], 'database' => 'NONE',
            '2026_access' => 'FORBIDDEN', 'training' => 'FORBIDDEN', 'bootstrap_gate_adoption' => 'NOT_AUTHORIZED'];
    }

    public static function year(int $year): void
    {
        if (! in_array($year, self::YEARS, true)) {
            throw new RuntimeException('Only 2024/2025 permitted; 2026 forbidden.');
        }
    }

    public static function select(array $curve): array
    {
        return Previous::select($curve);
    }

    public static function path(string $path): void
    {
        if (preg_match('/(?:^|[\/_ .-])2026(?:$|[\/_ .-])/', $path)) {
            throw new RuntimeException('2026 semantic path forbidden.');
        }
    }

    public static function transfer(array $row): string
    {
        return match (Previous::validation($row)) {
            'NO_INCREMENTAL_ADJUSTMENT_SELECTED' => 'NO_INCREMENTAL_ADJUSTMENT_SELECTED',
            'DIRECTIONALLY_REPLICATED_DEVELOPMENT_ONLY' => 'DIRECTIONALLY_CONSISTENT_POST_SELECTION_DEVELOPMENT_REPLAY',
            default => 'NOT_TRANSFERRED_POST_SELECTION_DEVELOPMENT_REPLAY',
        };
    }
}
