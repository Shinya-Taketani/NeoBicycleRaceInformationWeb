<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\AgariPlayerHistory;

use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class History
{
    public function build(PDO $db, string $from): void
    {
        $targets = $db->query('SELECT * FROM groups ORDER BY id');
        $past = $db->prepare('SELECT payload FROM groups WHERE external=? AND class=? AND eligible=1 AND ends<? AND meeting<>?
            ORDER BY ends DESC,starts DESC,id LIMIT 13');
        $put = $db->prepare('UPDATE groups SET history=? WHERE id=?');
        $db->beginTransaction();
        while ($target = $targets->fetch()) {
            $group = json_decode($target['payload'], true, flags: JSON_THROW_ON_ERROR);
            $candidates = [];
            $reasons = array_values(array_diff($group['context_flags'], ['MEETING_CROSSES_INPUT_START', 'MEETING_CROSSES_INPUT_END']));
            if ($reasons === []) {
                $past->execute([$target['external'], $target['class'], $target['starts'], $target['meeting']]);
                while ($row = $past->fetch()) {
                    $candidates[] = json_decode($row['payload'], true, flags: JSON_THROW_ON_ERROR);
                }
            }
            $history = self::calculate($candidates, $target['starts'], $from, $reasons);
            $put->execute([Files::canonical($history), $target['id']]);
        }
        $db->commit();
    }

    // Candidate order is calendar end DESC. The extra 13th row detects overlap across a 12-slot boundary.
    public static function calculate(array $candidates, ?string $targetStart, string $from, array $reasons = []): array
    {
        $windows = [];
        foreach (Contract::WINDOWS as $n) {
            $windowReasons = $reasons;
            $selected = array_slice($candidates, 0, $n);
            for ($i = 0; $i < count($selected); $i++) {
                for ($j = $i + 1; $j < count($candidates); $j++) {
                    if ($candidates[$j]['meeting']['ends_on'] >= $selected[$i]['meeting']['starts_on']) {
                        $windowReasons[] = 'AMBIGUOUS_MEETING_ORDER';
                    }
                }
            }
            $windowReasons = array_values(array_unique($windowReasons));
            sort($windowReasons);
            // Never resolve ambiguous calendar ordering using the arbitrary meeting-ID tie breaker.
            if ($windowReasons !== []) {
                $selected = [];
            }
            $values = [];
            $adopted = 0;
            $excluded = [];
            $references = [];
            foreach ($selected as $meeting) {
                if ($meeting['meeting_percentile_mean'] !== null) {
                    $values[] = $meeting['meeting_percentile_mean'];
                }
                $adopted += $meeting['adopted_races'];
                foreach ($meeting['exclusion_reasons'] as $key => $count) {
                    $excluded[$key] = ($excluded[$key] ?? 0) + $count;
                }
                $references[] = ['player_meeting_id' => $meeting['id'], 'meeting_id' => $meeting['meeting']['meeting_id'],
                    'starts_on' => $meeting['meeting']['starts_on'], 'ends_on' => $meeting['meeting']['ends_on'],
                    'meeting_percentile_mean' => $meeting['meeting_percentile_mean'], 'context_flags' => $meeting['context_flags']];
            }
            ksort($excluded);
            $oldest = $selected === [] ? null : $selected[count($selected) - 1]['meeting']['starts_on'];
            $latest = $selected[0]['meeting']['ends_on'] ?? null;
            $observed = count($selected);
            $valid = count($values);
            $windows[(string) $n] = ['requested_meetings' => $n, 'observed_meetings' => $observed,
                'valid_meetings' => $valid, 'adopted_races' => $adopted, 'exclusion_reasons' => $excluded,
                ...Exact::statistics($values), 'meetings' => $references, 'oldest_history_date' => $oldest,
                'latest_history_date' => $latest, 'days_since_latest_end' => $latest === null ? null : (int) (new DateTimeImmutable($latest, new DateTimeZone('Asia/Tokyo')))->diff(new DateTimeImmutable($targetStart, new DateTimeZone('Asia/Tokyo')))->format('%a'),
                'flags' => ['no_timing_history' => $valid === 0, 'insufficient_observed_meetings' => $observed < $n,
                    'insufficient_valid_meetings' => $valid < $n, 'missing_meeting_values' => $valid < $observed,
                    'input_left_truncated' => $observed < $n || ($oldest !== null && $oldest < $from)],
                'blocking_reasons' => $windowReasons];
        }
        $six = $windows['6'];
        $trendReason = match (true) {
            $six['blocking_reasons'] !== [] => 'BLOCKED_CONTEXT_OR_ORDER',
            $six['observed_meetings'] < 6 => 'INSUFFICIENT_OBSERVED_MEETINGS',
            $six['valid_meetings'] < 6 => 'MISSING_MEETING_VALUES',
            default => null,
        };
        $difference = null;
        if ($trendReason === null) {
            $a = Exact::statistics(array_column(array_slice($six['meetings'], 0, 3), 'meeting_percentile_mean'))['mean'];
            $b = Exact::statistics(array_column(array_slice($six['meetings'], 3, 3), 'meeting_percentile_mean'))['mean'];
            $difference = Exact::value(Exact::rational($a)->minus(Exact::rational($b)));
        }

        return ['windows' => $windows, 'recent3_minus_previous3_meeting_percentile' => $difference, 'trend_null_reason' => $trendReason];
    }
}
