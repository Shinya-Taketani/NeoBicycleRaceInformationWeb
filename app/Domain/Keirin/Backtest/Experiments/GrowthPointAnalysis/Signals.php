<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\GrowthPointAnalysis;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

final class Signals
{
    public static function score(mixed $score): ?int
    {
        if ($score === null) {
            return null;
        }
        if (! preg_match('/\A([0-9]{1,4})(?:\.([0-9]{1,2}))?\z/D', (string) $score, $m)) {
            throw new RuntimeException('Invalid historical score.');
        }
        $value = (int) $m[1] * 100 + (int) str_pad($m[2] ?? '', 2, '0');

        return $value > 0 ? $value : null;
    }

    public static function timestamp(string $date, ?string $time): ?int
    {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('Asia/Tokyo'));
        if ($d === false || $d->format('Y-m-d') !== $date) {
            throw new RuntimeException('Invalid historical date.');
        }
        Contract::year((int) $d->format('Y'));
        if ($time === null) {
            return null;
        }
        $t = new DateTimeImmutable($time, new DateTimeZone('Asia/Tokyo'));
        if ($t->setTimezone(new DateTimeZone('Asia/Tokyo'))->format('Y-m-d') !== $date) {
            throw new RuntimeException('Scheduled time/race date mismatch.');
        }

        return $t->getTimestamp();
    }

    /** The target contains no result fields. History candidates include blockers, not only normal finishes. */
    public function calculate(array $target, array $candidates): array
    {
        Contract::year($target['year']);
        foreach (['rank', 'status', 'residual', 'normal', 'outcome'] as $key) {
            if (array_key_exists($key, $target)) {
                throw new RuntimeException('Target outcome must not enter growth calculation.');
            }
        }
        $out = ['SCORE' => ['raw' => null, 'status' => 'PARTIAL_HISTORY'], 'PERFORMANCE' => ['raw' => null, 'status' => 'PARTIAL_HISTORY'],
            'prev1' => null, 'prev2' => null, 'same_meeting_previous' => null, 'observed_history_from' => '2022-01-01'];
        if ($target['player_id'] === null) {
            $out['SCORE']['status'] = $out['PERFORMANCE']['status'] = 'MISSING_PLAYER';

            return $out;
        }
        if ($target['ts'] === null) {
            return $out;
        }
        $eligible = $seen = [];
        foreach ($candidates as $row) {
            Contract::year($row['year']);
            if ($row['player_id'] !== $target['player_id']) {
                throw new RuntimeException('Previous player mismatch.');
            }
            if ($row['race_id'] === $target['race_id'] || $row['date'] > $target['date'] || ($row['ts'] !== null && $row['ts'] >= $target['ts'])) {
                continue;
            }
            if (isset($seen[$row['race_id']])) {
                throw new RuntimeException('Duplicate previous race.');
            }
            $seen[$row['race_id']] = true;
            if ($row['started'] === 0) {
                continue;
            }
            $eligible[] = $row;
        }
        usort($eligible, fn ($a, $b) => [$b['date'], $b['ts'] ?? PHP_INT_MAX] <=> [$a['date'], $a['ts'] ?? PHP_INT_MAX]);
        $p1 = $eligible[0] ?? null;
        $p2 = $eligible[1] ?? null;
        $p3 = $eligible[2] ?? null;
        if ($p1 === null) {
            $out['SCORE']['status'] = $out['PERFORMANCE']['status'] = 'NO_PREVIOUS_RACE';

            return $out;
        }
        $out['prev1'] = $p1;
        $out['prev2'] = $p2;
        if ($p1['started'] !== 1 || $p1['ts'] === null || ($p2 !== null && $p1['date'] === $p2['date'] && ($p2['ts'] === null || $p1['ts'] === $p2['ts']))) {
            return $out;
        }
        $out['same_meeting_previous'] = $target['meeting_id'] !== null && $p1['meeting_id'] !== null
            ? $target['meeting_id'] === $p1['meeting_id'] : null;
        $out['SCORE'] = $target['score'] === null ? ['raw' => null, 'status' => 'MISSING_TARGET_SCORE']
            : ($p1['score'] === null ? ['raw' => null, 'status' => 'MISSING_PREVIOUS_SCORE']
                : ['raw' => ($target['score'] - $p1['score']) / 100.0, 'status' => 'VALID']);
        if ($p2 === null) {
            $out['PERFORMANCE']['status'] = 'NO_SECOND_PREVIOUS_RACE';
        } elseif ($p2['started'] !== 1 || $p2['ts'] === null || ($p3 !== null && $p2['date'] === $p3['date'] && ($p3['ts'] === null || $p2['ts'] === $p3['ts']))) {
            $out['PERFORMANCE']['status'] = 'PARTIAL_HISTORY';
        } elseif ($p1['residual'] === null || $p2['residual'] === null) {
            $out['PERFORMANCE']['status'] = 'MISSING_OR_ABNORMAL_PREVIOUS_RESIDUAL';
        } else {
            $out['PERFORMANCE'] = ['raw' => $p1['residual'] - $p2['residual'], 'status' => 'VALID'];
        }

        return $out;
    }
}
