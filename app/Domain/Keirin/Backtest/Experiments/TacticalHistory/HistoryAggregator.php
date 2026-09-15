<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\TacticalHistory;

use App\Domain\Keirin\Scraping\Support\HtmlTextNormalizer;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

final class HistoryAggregator
{
    public const VERSION = 'TACTICAL-HISTORY-01-120D-PRE-MEETING-v1';

    public const FEATURES = [
        'HIST_OBSERVED_ESCAPE_TOP2_COUNT_120D_PRE_MEETING',
        'HIST_OBSERVED_MAKURI_TOP2_COUNT_120D_PRE_MEETING',
        'HIST_OBSERVED_SASHI_TOP2_COUNT_120D_PRE_MEETING',
        'HIST_OBSERVED_MARK_TOP2_COUNT_120D_PRE_MEETING',
    ];

    private const METHODS = ["\u{9003}\u{3052}", "\u{6372}\u{308a}", "\u{5dee}\u{3057}", "\u{30de}\u{30fc}\u{30af}"];

    /** @param array<string,mixed> $target @param iterable<array<string,mixed>> $history */
    public function aggregate(array $target, iterable $history): array
    {
        $audit = [
            'values' => [null, null, null, null],
            'status' => 'MISSING_TARGET_METADATA',
            'coverage_scope' => 'OBSERVED_DB_HISTORY',
            'acquisition_mode' => 'BACKFILLED_FINAL_RESULT',
            'event_cutoff_verified' => false,
            'publication_time_verified' => 'UNKNOWN',
            'calculation_version' => self::VERSION,
            'observed_count' => 0,
            'history_references' => [],
        ];
        foreach (['race_id', 'meeting_id', 'player_id', 'race_date', 'meeting_start', 'meeting_end', 'input_as_of'] as $key) {
            if (! isset($target[$key])) {
                return $audit;
            }
        }
        $zone = new DateTimeZone('Asia/Tokyo');
        $cutoff = $this->date($target['meeting_start']);
        $end = $this->date($target['meeting_end']);
        $date = $this->date($target['race_date']);
        $asOf = new DateTimeImmutable($target['input_as_of'], $zone);
        if ($date < $cutoff || $date > $end || $cutoff > $asOf || $date->format('Y') < '2022' || $date->format('Y') > '2025') {
            throw new RuntimeException('Tactical history target temporal identity was invalid.');
        }
        $start = $cutoff->modify('-120 days');
        $audit += ['window_start' => $start->format(DATE_ATOM), 'window_end' => $cutoff->format(DATE_ATOM)];
        $audit['event_cutoff_verified'] = true;
        if ($start < $this->date('2022-01-01')) {
            $audit['status'] = 'LEFT_TRUNCATED';

            return $audit;
        }
        $counts = [0, 0, 0, 0];
        $problems = [];
        $seen = [];
        foreach ($history as $row) {
            if ($row['race_id'] === $target['race_id'] || $row['meeting_id'] === $target['meeting_id']
                || $row['player_id'] !== $target['player_id'] || $row['male_category'] !== true) {
                continue;
            }
            $historyDate = $this->date($row['race_date']);
            if ($historyDate->format('Y') < '2022' || $historyDate->format('Y') > '2025'
                || $historyDate < $start || $historyDate >= $cutoff) {
                continue;
            }
            if ($row['scheduled_start_at'] === null || $row['meeting_id'] === null) {
                $problems['PARTIAL_HISTORY'] = true;

                continue;
            }
            $scheduled = new DateTimeImmutable($row['scheduled_start_at'], $zone);
            if ($scheduled->setTimezone($zone)->format('Y-m-d') !== $row['race_date']) {
                throw new RuntimeException('History scheduled date disagreed with race date.');
            }
            if ($scheduled < $start || $scheduled >= $cutoff) {
                continue;
            }
            $key = $row['race_id'].':'.$row['bike'];
            $identity = hash('sha256', json_encode($row, JSON_THROW_ON_ERROR));
            if (isset($seen[$key])) {
                if ($seen[$key] !== $identity) {
                    throw new RuntimeException('Conflicting duplicate history entry.');
                }

                continue;
            }
            $seen[$key] = $identity;
            if (($row['result_player_id'] !== null && $row['result_player_id'] !== $row['player_id'])
                || ($row['result_entry_id'] !== null && $row['result_entry_id'] !== $row['entry_id'])) {
                throw new RuntimeException('History result and entry identity disagreed.');
            }
            $audit['history_references'][] = ['race_id' => $row['race_id'], 'entry_id' => $row['entry_id'], 'bike' => $row['bike'], 'sha256' => $identity];
            if ($row['race_status'] === 'CANCELLED') {
                continue;
            }
            if (! in_array($row['race_status'], ['CONFIRMED', 'CORRECTED'], true) || $row['result_id'] === null) {
                $problems['PARTIAL_HISTORY'] = true;

                continue;
            }
            if (($row['formal_provenance_verified'] ?? true) !== true) {
                $problems['PARTIAL_HISTORY'] = true;

                continue;
            }
            if (($row['correction_effective_at'] ?? null) !== null
                && new DateTimeImmutable($row['correction_effective_at'], $zone) >= $cutoff) {
                $problems['PARTIAL_HISTORY'] = true;

                continue;
            }
            if (! in_array($row['result_status'], ['FINISHED', 'TIED', 'DISQUALIFIED', 'DID_NOT_START', 'DID_NOT_FINISH', 'WITHDRAWN', 'CRASHED', 'UNKNOWN'], true)
                || $row['result_status'] === 'UNKNOWN') {
                $problems['PARTIAL_HISTORY'] = true;

                continue;
            }
            if (in_array($row['result_status'], ['DID_NOT_START', 'WITHDRAWN'], true)) {
                continue;
            }
            $audit['observed_count']++;
            if (! in_array($row['result_status'], ['FINISHED', 'TIED'], true)) {
                continue;
            }
            if (! is_int($row['rank']) || $row['rank'] < 1) {
                $problems['INVALID_HISTORY'] = true;

                continue;
            }
            $method = $row['winning_technique'] === null ? null : HtmlTextNormalizer::normalize($row['winning_technique']);
            if ($row['rank'] > 2) {
                continue;
            }
            $index = array_search($method, self::METHODS, true);
            if ($index === false) {
                $problems['MISSING_METHOD_HISTORY'] = true;
            } elseif ($index === 3 && $row['rank'] !== 2) {
                $problems['INVALID_HISTORY'] = true;
            } else {
                $counts[$index]++;
            }
        }
        foreach (['INVALID_HISTORY', 'PARTIAL_HISTORY', 'MISSING_METHOD_HISTORY'] as $problem) {
            if (isset($problems[$problem])) {
                $audit['status'] = $problem;
                $audit['reasons'] = array_keys($problems);

                return $audit;
            }
        }
        $audit['status'] = $audit['observed_count'] > 0 ? 'AVAILABLE' : 'NO_HISTORY';
        if ($audit['status'] === 'AVAILABLE') {
            $audit['values'] = $counts;
        }

        return $audit;
    }

    private function date(string $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('Asia/Tokyo'));
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new RuntimeException('Invalid tactical history date.');
        }

        return $date;
    }
}
