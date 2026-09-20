<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\GrowthTrendAdjustmentCalibration;

use App\Domain\Keirin\Backtest\Experiments\GrowthTrendScoreSource\OuterSource;
use Generator;
use RuntimeException;

final class Signal
{
    public const STATUSES = ['VALID', 'MISSING_PLAYER', 'MISSING_MEETING_ID', 'MISSING_TARGET_SCORE',
        'MISSING_PREVIOUS_SCORE', 'PARTIAL_TIME_ORDER', 'LEFT_TRUNCATED_POSSIBLE'];

    public static function projection(iterable $rows): Generator
    {
        foreach ($rows as $row) {
            $c = $row['candidates'][Contract::SIGNAL] ?? throw new RuntimeException('Required signal missing.');
            $p = ['year' => $row['year'], 'race_id' => $row['race_id'], 'entry_id' => $row['entry_id'],
                'player_id' => $row['player_id'], 'bike' => $row['bike'], 'signal_id' => Contract::SIGNAL,
                'raw' => $c['raw'], 'status' => $c['status'],
                'first_observation' => $row['first_score_observation_in_target_meeting'],
                'boundary_ambiguous' => $row['target_boundary_partial_time_order']];
            self::validate($p);
            yield $p;
        }
    }

    public static function validate(array $g): void
    {
        OuterSource::keys($g, ['year', 'race_id', 'entry_id', 'player_id', 'bike', 'signal_id', 'raw', 'status', 'first_observation', 'boundary_ambiguous']);
        Contract::year($g['year']);
        foreach (['race_id', 'entry_id', 'bike'] as $key) {
            if (! is_int($g[$key]) || $g[$key] < 1) {
                throw new RuntimeException('Invalid signal identity.');
            }
        }
        if ($g['bike'] > 9 || ($g['player_id'] !== null && (! is_int($g['player_id']) || $g['player_id'] < 1))
            || $g['signal_id'] !== Contract::SIGNAL || ! in_array($g['status'], self::STATUSES, true)
            || ($g['raw'] !== null && ((! is_int($g['raw']) && ! is_float($g['raw'])) || ! is_finite($g['raw'])))
            || (($g['status'] === 'VALID') !== ($g['raw'] !== null)) || ! is_bool($g['boundary_ambiguous'])
            || ($g['boundary_ambiguous'] && $g['status'] !== 'PARTIAL_TIME_ORDER')
            || ! in_array($g['first_observation'], [true, false, null], true)) {
            throw new RuntimeException('Invalid signal status/value.');
        }
    }

    public static function normalized(array $g, float $scale): ?float
    {
        self::validate($g);
        if (! is_finite($scale) || $scale <= 0) {
            throw new RuntimeException('SCALE_P99 must be positive finite.');
        }

        return $g['raw'] === null ? null : max(-1.0, min(1.0, $g['raw'] / $scale));
    }

    public static function magnitude(?float $g): string
    {
        if ($g === null) {
            return 'MISSING';
        }
        if ($g === 0.0) {
            return 'ZERO';
        }
        if ($g < 0) {
            return $g < -0.75 ? '[-1,-0.75)' : ($g < -0.5 ? '[-0.75,-0.5)' : ($g < -0.25 ? '[-0.5,-0.25)' : '[-0.25,0)'));
        }

        return $g <= 0.25 ? '(0,0.25]' : ($g <= 0.5 ? '(0.25,0.5]' : ($g <= 0.75 ? '(0.5,0.75]' : '(0.75,1]'));
    }
}
