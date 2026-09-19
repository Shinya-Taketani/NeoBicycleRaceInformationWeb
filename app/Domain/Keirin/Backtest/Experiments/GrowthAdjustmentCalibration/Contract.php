<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\GrowthAdjustmentCalibration;

use RuntimeException;

final class Contract
{
    public const VERSION = 'GROWTH-ADJUSTMENT-CALIBRATION-01-v1';

    public const YEARS = [2024, 2025];

    public const COUNTS = [2024 => 25212, 2025 => 24866];

    public const ENTRIES = [2024 => 179089, 2025 => 177120];

    public const MISSING = [2024 => 206, 2025 => 202];

    public const SAME = [2024 => 118820, 2025 => 117729];

    public const METRICS = ['POSITION_1_ACCURACY', 'POSITION_2_ACCURACY', 'POSITION_3_ACCURACY', 'POSITION_HIT_RATE_AT_3'];

    public const DENOMINATORS = [2024 => [25158, 25106, 25094, 75120], 2025 => [24789, 24727, 24739, 73989]];

    public static function grid(): array
    {
        return array_map(fn (int $k) => ['k' => $k, 'w' => $k / 100, 'per_point_odds_multiplier' => exp($k / 100),
            'plus3_vs_zero_odds_multiplier' => exp(3 * $k / 100), 'minus3_vs_zero_odds_multiplier' => exp(-3 * $k / 100)], range(-50, 50));
    }

    public static function plan(): array
    {
        return ['version' => self::VERSION, 'years' => self::YEARS, 'races' => self::COUNTS, 'entries' => self::ENTRIES,
            'signal' => 'SCORE_POINT_V2_ONLY', 'adjustment' => 'anchor + (k / 100) * point; same for all positions',
            'grid' => self::grid(), 'missing' => 'GROWTH_MISSING_NO_ADJUSTMENT',
            'selection_year' => 2024, 'eligibility' => ['position_delta_gte' => -0.003, 'hit3_delta_gte' => 0.0],
            'selection_order' => ['MAX_HIT3', 'MIN_ABS_K', 'MIN_K'], 'validation_year' => 2025,
            'baseline_2025_preflight' => 'W0_REPRODUCTION_ONLY_BEFORE_GRID',
            'candidate_2025_access' => 'AFTER_SELECTION_SEAL_ONLY',
            'pooled' => 'COUNT_WEIGHTED_DIAGNOSTIC_ONLY', 'metrics' => self::METRICS,
            'zero_invariance' => 'ANCHOR_ONLY; relative predictions may change when opponents change',
            'odds_caveat' => 'Utility difference reference; not a multiplier on probabilities',
            'database' => 'NONE', '2026_access' => 'FORBIDDEN', 'training' => 'FORBIDDEN', 'gate' => 'NOT_APPLICABLE'];
    }

    public static function race(array $race): void
    {
        if (! in_array($race['year'] ?? null, self::YEARS, true) || ! is_int($race['race_id'] ?? null) || $race['race_id'] < 1) {
            throw new RuntimeException('Only valid 2024/2025 race identities are permitted.');
        }
    }

    public static function select(array $curve): array
    {
        if (($curve['year'] ?? null) !== 2024 || array_column($curve['candidates'] ?? [], 'k') !== range(-50, 50)) {
            throw new RuntimeException('Selection requires the complete 2024 grid.');
        }
        $eligible = array_values(array_filter($curve['candidates'], static function (array $row): bool {
            foreach (self::METRICS as $metric) {
                if (! is_numeric($row['metrics'][$metric]['delta'] ?? null) || ! is_numeric($row['metrics'][$metric]['rate'] ?? null)) {
                    throw new RuntimeException('Selection metrics must be evaluable.');
                }
                if ($row['metrics'][$metric]['delta'] < ($metric === self::METRICS[3] ? 0.0 : -0.003)) {
                    return false;
                }
            }

            return true;
        }));
        usort($eligible, fn ($a, $b) => [-$a['metrics'][self::METRICS[3]]['rate'], abs($a['k']), $a['k']]
            <=> [-$b['metrics'][self::METRICS[3]]['rate'], abs($b['k']), $b['k']]);
        $selected = $eligible[0] ?? throw new RuntimeException('Baseline was not eligible.');

        return ['selected' => $selected, 'eligible_k' => array_column($eligible, 'k'),
            'boundary_status' => abs($selected['k']) === 50 ? 'BOUNDARY_SELECTED' : 'INTERIOR_SELECTED',
            'algorithm' => self::plan()['selection_order'], 'selection_year' => 2024];
    }

    public static function validation(array $row): string
    {
        if ($row['k'] === 0) {
            return 'NO_INCREMENTAL_ADJUSTMENT_SELECTED';
        }
        foreach (self::METRICS as $i => $metric) {
            if ($i === 3 ? $row['metrics'][$metric]['delta'] <= 0 : $row['metrics'][$metric]['delta'] < -0.003) {
                return 'NOT_REPLICATED';
            }
        }

        return 'DIRECTIONALLY_REPLICATED_DEVELOPMENT_ONLY';
    }
}
