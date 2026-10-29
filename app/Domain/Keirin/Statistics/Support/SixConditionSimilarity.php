<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\Support;

use App\Domain\Keirin\Statistics\DTO\SimilarityConditionsDto;
use App\Domain\Keirin\Statistics\Enums\RaceStage;

final class SixConditionSimilarity
{
    public const VERSION = 'SIX-CONDITIONS-EQUAL-WEIGHT-NO-DECAY-v1';

    public const FIELDS = ['field_size', 'race_stage', 'meeting_grade', 'entry_grade', 'day_number', 'start_minute_jst'];

    public function compare(SimilarityConditionsDto $target, SimilarityConditionsDto $history): array
    {
        $fields = [];
        $sum = 0.0;
        $m = 0;
        foreach (self::FIELDS as $name) {
            $a = $target->values[$name] ?? null;
            $b = $history->values[$name] ?? null;
            $valid = fn ($v) => match ($name) {
                'field_size', 'day_number' => is_int($v) && $v > 0,
                'start_minute_jst' => (is_int($v) || is_float($v)) && is_finite((float) $v) && $v >= 0 && $v < 1440,
                'race_stage' => in_array($v, array_diff(array_column(RaceStage::cases(), 'value'), ['UNKNOWN', 'OTHER']), true),
                'meeting_grade' => in_array($v, ['GP', 'G1', 'G2', 'G3', 'F1', 'F2'], true),
                'entry_grade' => in_array($v, ['SS', 'S1', 'S2', 'S3', 'A1', 'A2', 'A3', 'A4', 'B1', 'B2'], true),
            };
            $distance = null;
            $reasons = [];
            if (! $valid($a) || ! $valid($b)) {
                if (! $valid($a)) {
                    $reasons[] = 'TARGET_CONDITION_UNAVAILABLE';
                }
                if (! $valid($b)) {
                    $reasons[] = 'HISTORY_CONDITION_UNAVAILABLE';
                }
            } else {
                $distance = match ($name) {
                    'field_size', 'day_number' => abs($a - $b) / max($a, $b),
                    'start_minute_jst' => min(abs($a - $b), 1440 - abs($a - $b)) / 720,
                    default => $a === $b ? 0.0 : 1.0,
                };
                $m++;
                $sum += 1 - $distance;
            }
            $fields[$name] = ['target' => $a, 'history' => $b, 'distance' => $distance,
                'similarity' => $distance === null ? null : 1 - $distance, 'reasons' => $reasons];
        }
        $coverage = $m / 6;

        return ['version' => self::VERSION, 'conditions' => $fields, 'comparable_conditions' => $m,
            'similarity' => $m >= 2 ? $sum / $m : null, 'six_condition_coverage' => $coverage,
            'weight' => $m >= 2 ? ($sum / $m) * $coverage : null,
            'reasons' => $m < 2 ? ['INSUFFICIENT_COMPARABLE_CONDITIONS'] : ($m < 6 ? ['PARTIAL_SIX_CONDITION_COVERAGE'] : []),
            'target_condition_reasons' => $target->reasons, 'history_condition_reasons' => $history->reasons];
    }
}
