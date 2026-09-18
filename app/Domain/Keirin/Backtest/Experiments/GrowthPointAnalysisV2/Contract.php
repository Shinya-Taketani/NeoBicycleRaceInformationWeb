<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\GrowthPointAnalysisV2;

use App\Domain\Keirin\Backtest\Experiments\GrowthPointAnalysis\Contract as V1;
use RuntimeException;

final class Contract
{
    public const VERSION = 'GROWTH-POINT-ANALYSIS-01-v2';

    public const QUANTILES = [0.3333333333333333, 0.6666666666666666];

    public const MINIMUM_SIDE = 3;

    public static function plan(): array
    {
        return ['version' => self::VERSION, 'point_semantics' => 'SIGN_PRESERVING_POINT', 'source_version' => V1::plan()['version'],
            'cohort' => V1::plan()['cohort'], 'threshold_years' => V1::plan()['threshold_years'],
            'quantile_type' => 7, 'quantiles' => self::QUANTILES, 'minimum_side_training' => self::MINIMUM_SIDE,
            'negative' => 'sign=-1; magnitude=abs(raw)', 'positive' => 'sign=+1; magnitude=raw',
            'boundary' => 'm<P33:sign*1; P33<=m<P67:sign*2; m>=P67:sign*3',
            'zero' => 'ALWAYS_POINT_ZERO', 'insufficient_side' => 'NULL / INSUFFICIENT_SIGN_TRAINING',
            'missing' => 'NULL_NOT_ZERO', 'composite' => 'A_POINT_PLUS_B_POINT_WHEN_BOTH_AVAILABLE',
            'diagnostic' => V1::plan()['diagnostic'], 'mean_fp_monotonicity' => 'SUPPLEMENT_ONLY_NO_CLASSIFIER_CHANGE',
            'publication_time_verified' => 'UNKNOWN', 'mode' => 'BACKFILLED_FINAL_RESULT / DEVELOPMENT_ONLY',
            'database' => 'NONE', 'training_inference_gate_bootstrap' => 'NONE', 'holdout_2026' => 'FORBIDDEN'];
    }

    public static function point(?float $raw, array $thresholds): array
    {
        if ($raw === null) {
            return ['point' => null, 'point_status' => 'MISSING_RAW'];
        }
        if (! is_finite($raw)) {
            throw new RuntimeException('Non-finite raw.');
        }
        if ($raw == 0.0) {
            return ['point' => 0, 'point_status' => 'VALID'];
        }
        $side = $thresholds[$raw < 0 ? 'negative' : 'positive'];
        if (! is_int($side['n']) || $side['n'] < 0) {
            throw new RuntimeException('Invalid side count.');
        }
        if ($side['n'] < self::MINIMUM_SIDE) {
            if ($side['values'] !== null) {
                throw new RuntimeException('Insufficient side must have null thresholds.');
            }

            return ['point' => null, 'point_status' => 'INSUFFICIENT_SIGN_TRAINING'];
        }
        $q = $side['values'];
        if (! is_array($q) || ! array_is_list($q) || count($q) !== 2) {
            throw new RuntimeException('Missing side quantiles.');
        }
        foreach ($q as $i => $v) {
            if ((! is_int($v) && ! is_float($v)) || ! is_finite((float) $v) || $v <= 0 || ($i === 1 && $q[0] > $v)) {
                throw new RuntimeException('Invalid side quantiles.');
            }
        }
        $m = abs($raw);

        return ['point' => ($raw < 0 ? -1 : 1) * ($m < $q[0] ? 1 : ($m < $q[1] ? 2 : 3)), 'point_status' => 'VALID'];
    }
}
