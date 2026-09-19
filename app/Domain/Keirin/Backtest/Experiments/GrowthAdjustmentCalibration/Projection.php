<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\GrowthAdjustmentCalibration;

use Generator;
use RuntimeException;

final class Projection
{
    public function growth(iterable $details): Generator
    {
        foreach ($details as $row) {
            $signal = $row['signals']['SCORE'] ?? [];
            $projected = ['year' => $row['year'], 'race_id' => $row['race_id'], 'entry_id' => $row['entry_id'], 'player_id' => $row['player_id'],
                'score_raw' => $signal['raw'], 'score_point' => $signal['point'], 'score_status' => $signal['status'],
                'same_meeting_previous' => match ($row['same']) {
                    'PREVIOUS_SAME_MEETING' => true, 'PREVIOUS_OTHER_MEETING' => false, 'UNKNOWN' => null,
                    default => throw new RuntimeException('Unknown same-meeting state.'),
                }];
            self::validate($projected);
            yield $projected;
        }
    }

    public static function validate(array $row): void
    {
        Contract::race($row);
        $keys = ['year', 'race_id', 'entry_id', 'player_id', 'score_raw', 'score_point', 'score_status', 'same_meeting_previous'];
        if (count($row) !== count($keys) || array_diff($keys, array_keys($row)) !== []
            || ! is_int($row['entry_id']) || $row['entry_id'] < 1 || ! is_int($row['player_id']) || $row['player_id'] < 1
            || ! in_array($row['same_meeting_previous'], [null, false, true], true)) {
            throw new RuntimeException('Invalid or outcome-bearing growth snapshot.');
        }
        $raw = $row['score_raw'];
        $point = $row['score_point'];
        if ($raw === null) {
            if ($point !== null || $row['score_status'] === 'VALID') {
                throw new RuntimeException('Missing growth was inconsistent.');
            }
        } elseif ((! is_float($raw) && ! is_int($raw)) || ! is_finite($raw) || ! is_int($point) || abs($point) > 3
            || ($raw == 0 ? $point !== 0 : ($raw < 0 ? $point >= 0 : $point <= 0)) || $row['score_status'] !== 'VALID') {
            throw new RuntimeException('Frozen SCORE sign/status mismatch.');
        }
        if ($row['same_meeting_previous'] === true && ($raw === null || $raw != 0 || $point !== 0)) {
            throw new RuntimeException('Expected same-meeting SCORE zero.');
        }
    }
}
