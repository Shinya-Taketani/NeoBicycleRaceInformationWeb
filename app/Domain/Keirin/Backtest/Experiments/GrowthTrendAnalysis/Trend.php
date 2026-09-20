<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\GrowthTrendAnalysis;

use App\Domain\Keirin\Backtest\Experiments\GrowthTrendScoreSource\Contract as SourceContract;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

final class Trend
{
    public static function median(array $values): ?float
    {
        if ($values === []) {
            return null;
        }
        sort($values, SORT_NUMERIC);
        $n = count($values);

        return ($values[(int) floor(($n - 1) / 2)] + $values[(int) floor($n / 2)]) / 2.0;
    }

    public static function slope(array $x, array $y, bool $robust): ?float
    {
        if (count($x) !== count($y) || count($x) < 2) {
            return null;
        }
        if ($robust) {
            $pairs = [];
            for ($i = 0; $i < count($x); $i++) {
                for ($j = $i + 1; $j < count($x); $j++) {
                    if ($x[$i] !== $x[$j]) {
                        $pairs[] = ($y[$j] - $y[$i]) / ($x[$j] - $x[$i]);
                    }
                }
            }

            return self::median($pairs);
        }
        $mx = array_sum($x) / count($x);
        $my = array_sum($y) / count($y);
        $xy = $xx = $cxy = $cxx = 0.0;
        foreach ($x as $i => $value) {
            self::add($xy, $cxy, ($value - $mx) * ($y[$i] - $my));
            self::add($xx, $cxx, ($value - $mx) ** 2);
        }

        return $xx + $cxx > 0 ? ($xy + $cxy) / ($xx + $cxx) : null;
    }

    private static function add(float &$sum, float &$correction, float $value): void
    {
        $next = $sum + $value;
        $correction += abs($sum) >= abs($value) ? ($sum - $next) + $value : ($value - $next) + $sum;
        $sum = $next;
    }

    public static function day(string $date): int
    {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('UTC'));
        if ($d === false || $d->format('Y-m-d') !== $date) {
            throw new RuntimeException('Invalid meeting date.');
        }

        return intdiv($d->getTimestamp(), 86400);
    }

    public function representative(array $rows): array
    {
        if ($rows === []) {
            throw new RuntimeException('Empty observation meeting.');
        }
        $dates = array_column($rows, 'date');
        $first = array_values(array_filter($rows, fn ($r) => $r['date'] === min($dates)));
        $last = array_values(array_filter($rows, fn ($r) => $r['date'] === max($dates)));
        $earliest = $this->edge($first, false);
        $latest = $this->edge($last, true);
        $scores = array_values(array_filter(array_column($rows, 'score'), fn ($s) => $s !== null));
        $ids = array_column($earliest['rows'], 'entry_id');
        sort($ids, SORT_NUMERIC);
        foreach ($rows as $r) {
            if ($r['meeting_id'] !== $rows[0]['meeting_id'] || $r['player_id'] !== $rows[0]['player_id'] || $r['start'] !== $rows[0]['start']) {
                throw new RuntimeException('Mixed meeting/player identity.');
            }
        }

        return ['meeting_id' => $rows[0]['meeting_id'], 'player_id' => $rows[0]['player_id'], 'start' => $rows[0]['start'],
            'order_date' => $rows[0]['start'] ?? min($dates), 'first_date' => min($dates), 'last_date' => max($dates),
            'score' => $earliest['score'], 'status' => $earliest['status'], 'representative_entry_ids' => $ids,
            'observation_count' => count($rows), 'distinct_score_count' => count(array_unique($scores)),
            'score_min' => $scores === [] ? null : min($scores), 'score_max' => $scores === [] ? null : max($scores),
            'score_first' => $earliest['score'], 'score_last_if_ordered' => $latest['status'] === 'VALID' ? $latest['score'] : null,
            'intra_meeting_score_drift' => count(array_unique($scores)) > 1];
    }

    private function edge(array $rows, bool $last): array
    {
        $times = array_column($rows, 'ts');
        if (! in_array(null, $times, true)) {
            $time = $last ? max($times) : min($times);
            $rows = array_values(array_filter($rows, fn ($r) => $r['ts'] === $time));
        }
        $scores = array_unique(array_column($rows, 'score'), SORT_REGULAR);
        $same = count($scores) === 1;
        $value = $same ? reset($scores) : null;
        $status = ! $same ? 'PARTIAL_TIME_ORDER' : ($value === null ? 'MISSING_SCORE' : (count($rows) > 1 ? 'ORDER_AMBIGUOUS_SCORE_EQUAL' : 'VALID'));

        return ['score' => $value, 'status' => $status, 'rows' => $rows];
    }

    public function firstObservation(array $target, array $sameMeeting): ?bool
    {
        $unknown = false;
        foreach ($sameMeeting as $row) {
            if ($row['entry_id'] === $target['entry_id']) {
                continue;
            }
            if ($row['date'] < $target['date'] || ($row['date'] === $target['date'] && $row['ts'] !== null && $target['ts'] !== null && $row['ts'] < $target['ts'])) {
                return false;
            }
            if ($row['date'] === $target['date'] && ($row['ts'] === null || $target['ts'] === null || $row['ts'] === $target['ts'])) {
                $unknown = true;
            }
        }

        return $unknown ? null : true;
    }

    public function calculate(array $target, array $meetings): array
    {
        SourceContract::year((int) substr($target['date'], 0, 4));
        foreach (['rank', 'status', 'normal', 'outcome', 'result_status', 'winner', 'hit'] as $key) {
            if (array_key_exists($key, $target)) {
                throw new RuntimeException('Outcome passed to trend.');
            }
        }
        $anchor = $target['start'] ?? $target['date'];
        $prior = [];
        foreach ($meetings as $m) {
            if ((int) substr($m['order_date'], 0, 4) > 2025) {
                throw new RuntimeException('Future meeting start.');
            }
            SourceContract::year((int) substr($m['last_date'], 0, 4));
            if ($m['player_id'] !== $target['player_id']) {
                throw new RuntimeException('History player mismatch.');
            }
            if ($m['meeting_id'] !== null && $m['meeting_id'] !== $target['meeting_id'] && $m['order_date'] < $anchor && $m['last_date'] < $anchor) {
                if (isset($prior[$m['meeting_id']])) {
                    throw new RuntimeException('Duplicate history meeting.');
                }
                $prior[$m['meeting_id']] = $m;
            }
        }
        usort($prior, fn ($a, $b) => $b['order_date'] <=> $a['order_date']);
        for ($i = 1; $i < count($prior); $i++) {
            if ($prior[$i]['order_date'] === $prior[$i - 1]['order_date']) {
                $prior[$i]['status'] = $prior[$i - 1]['status'] = 'PARTIAL_TIME_ORDER';
                $prior[$i]['score'] = $prior[$i - 1]['score'] = null;
            }
        }
        $state = $target['player_id'] === null ? 'MISSING_PLAYER' : ($target['meeting_id'] === null ? 'MISSING_MEETING_ID'
            : ($target['score'] === null ? 'MISSING_TARGET_SCORE' : null));
        $candidates = [];
        $dayExclusions = [];
        $missingStarts = count(array_filter($prior, fn ($m) => $m['start'] === null));
        foreach (Contract::grid() as $c) {
            $status = $state;
            $raw = null;
            $day = str_starts_with($c['family'], 'DAY_');
            $need = $c['family'] === 'MEETING_DELTA' ? $c['grain'] : $c['grain'] - 1;
            $points = $day ? array_values(array_filter($prior, fn ($m) => $m['start'] !== null
                && self::day($anchor) - self::day($m['start']) > 0
                && self::day($anchor) - self::day($m['start']) <= $c['grain'])) : array_slice($prior, 0, $need);
            if ($day) {
                $dayExclusions[$c['id']] = $missingStarts;
            }
            if ($status === null && $day && $target['start'] === null) {
                $status = 'MISSING_MEETING_START';
            }
            if ($status === null && ($day ? count($points) < 2 : count($points) < $need)) {
                $status = $day && self::day($anchor) - $c['grain'] >= self::day('2022-01-01')
                    ? 'INSUFFICIENT_POINTS_IN_DAY_WINDOW' : 'LEFT_TRUNCATED_POSSIBLE';
            }
            foreach ($points as $m) {
                $status ??= $m['status'] === 'PARTIAL_TIME_ORDER' ? 'PARTIAL_TIME_ORDER'
                    : ($m['score'] === null ? 'MISSING_PREVIOUS_SCORE' : null);
            }
            if ($status === null) {
                if ($c['family'] === 'MEETING_DELTA') {
                    $raw = ($target['score'] - $points[$need - 1]['score']) / 100.0;
                } else {
                    $chronological = array_reverse($points);
                    $y = [...array_column($chronological, 'score'), $target['score']];
                    $x = $day ? [...array_map(fn ($m) => self::day($m['start']) - self::day($anchor), $chronological), 0] : range(0, count($y) - 1);
                    $slope = self::slope($x, $y, str_contains($c['family'], 'THEIL_SEN'));
                    $raw = $slope === null ? null : $slope / 100.0;
                }
                $status = $raw === null ? 'INVALID_INPUT' : 'VALID';
            }
            $candidates[$c['id']] = ['raw' => $raw === 0.0 ? 0.0 : $raw, 'status' => $status];
        }

        return ['candidates' => $candidates, 'previous' => $prior, 'events' => $this->events($target, $prior, $state), 'day_exclusions' => $dayExclusions];
    }

    private function events(array $target, array $prior, ?string $state): array
    {
        $same = $duration = 0;
        $previous = null;
        $start = $target['start'];
        $observationCount = 1;
        foreach ($prior as $m) {
            if ($state !== null || $m['score'] === null) {
                $state ??= $m['status'];
                break;
            }
            if ($previous === null && $m['score'] === $target['score']) {
                $same++;
                $start = $m['start'] === null || $start === null ? null : $m['start'];
                $observationCount += $m['observation_count'];
            } elseif ($previous === null || $m['score'] === $previous) {
                $previous ??= $m['score'];
                $duration++;
            } else {
                break;
            }
        }
        $delta = $state === null && $previous !== null ? ($target['score'] - $previous) / 100.0 : null;
        $streak = null;
        if ($state === null && count($prior) >= 3 && ! in_array(null, array_column(array_slice($prior, 0, 3), 'score'), true)) {
            $p = [$target['score'], ...array_column(array_slice($prior, 0, 3), 'score')];
            $streak = 0;
            $sign = $p[0] <=> $p[1];
            for ($i = 0; $i < 3 && $sign !== 0 && ($p[$i] <=> $p[$i + 1]) === $sign; $i++) {
                $streak += $sign;
            }
        }

        return ['LAST_SCORE_CHANGE_DELTA' => $delta, 'MEETINGS_SINCE_LAST_SCORE_CHANGE' => $state === null ? $same : null,
            'DAYS_SINCE_LAST_SCORE_CHANGE' => $state === null && $start !== null && $target['start'] !== null ? self::day($target['start']) - self::day($start) : null,
            'PREVIOUS_SCORE_LEVEL_DURATION_MEETINGS' => $delta !== null ? $duration : null,
            'CURRENT_SCORE_LEVEL_OBSERVATION_COUNT' => $state === null ? $observationCount : null,
            'level_status' => $state ?? ($previous === null ? 'NO_DISTINCT_PREVIOUS_SCORE_LEVEL' : 'VALID'),
            'signed_streak' => $streak, 'streak_status' => $streak === null ? 'INSUFFICIENT_STREAK_HISTORY' : 'VALID'];
    }
}
