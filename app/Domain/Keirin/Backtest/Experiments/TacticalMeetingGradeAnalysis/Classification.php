<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\TacticalMeetingGradeAnalysis;

use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use RuntimeException;

final class Classification
{
    public const MAP = ['GP' => 'GP', 'G1' => 'G1', 'G2' => 'G2', 'G3' => 'G3', 'F1' => 'F1', 'F2' => 'F2',
        'GⅠ' => 'G1', 'GⅡ' => 'G2', 'GⅢ' => 'G3', 'FⅠ' => 'F1', 'FⅡ' => 'F2',
        'GI' => 'G1', 'GII' => 'G2', 'GIII' => 'G3', 'FI' => 'F1', 'FII' => 'F2'];

    public static function normalize(?string $raw): string
    {
        return self::MAP[self::text($raw)] ?? 'UNKNOWN';
    }

    private static function text(?string $raw): string
    {
        return preg_replace('/[\s\x{3000}]+/u', '', mb_convert_kana($raw ?? '', 'as', 'UTF-8'));
    }

    public function meetings(iterable $rows): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $key = (string) ($row['meeting_id'] ?? 'missing:'.$row['race_id']);
            $identity = array_intersect_key($row, array_flip(['meeting_id', 'meeting_grade_raw', 'starts_on', 'ends_on', 'meeting_track_id', 'track_code']));
            if (isset($groups[$key])) {
                Files::same($groups[$key]['identity'], $identity, 'same meeting identity');
            } else {
                $groups[$key] = ['identity' => $identity, 'race_grade_raw_values' => [], 'races' => 0];
            }
            $groups[$key]['race_grade_raw_values'][Files::canonical([$row['race_grade_raw']])] = $row['race_grade_raw'];
            $groups[$key]['races']++;
        }
        ksort($groups, SORT_STRING);
        foreach ($groups as &$group) {
            ksort($group['race_grade_raw_values'], SORT_STRING);
            $group['race_grade_raw_values'] = array_values($group['race_grade_raw_values']);
            $raw = $group['identity']['meeting_grade_raw'];
            $meeting = self::normalize($raw);
            $raceGrades = array_values(array_unique(array_map(self::normalize(...), $group['race_grade_raw_values'])));
            $grade = 'UNKNOWN';
            $reason = 'UNRESOLVED_MEETING_GRADE';
            if ($group['identity']['meeting_id'] === null) {
                $reason = 'MISSING_MEETING_RELATION';
            } elseif (count($raceGrades) > 1 || ($meeting !== 'UNKNOWN' && count(array_diff($raceGrades, [$meeting, 'UNKNOWN'])) > 0)) {
                $reason = 'CONFLICTING_OR_MIXED_GRADES';
            } elseif ($meeting !== 'UNKNOWN' && $raceGrades === [$meeting]) {
                $grade = $meeting;
                $reason = 'SCHEDULE_AND_JSJ001_HEADER_AGREE';
            } elseif ($meeting !== 'UNKNOWN' && $group['race_grade_raw_values'] === [null]) {
                $grade = $meeting;
                $reason = 'SCHEDULE_ONLY_MISSING_HEADER';
            } elseif (self::text($raw) === '' && count($raceGrades) === 1 && $raceGrades[0] !== 'UNKNOWN') {
                $grade = $raceGrades[0];
                $reason = 'JSJ001_MEETING_HEADER_FALLBACK';
            }
            $group['grade'] = $grade;
            $group['reason'] = $reason;
        }
        unset($group);

        return $groups;
    }

    public static function raceClass(?string $raw): string
    {
        $text = self::text($raw);
        if (str_starts_with($text, 'S級')) {
            return 'S_CLASS';
        }
        if (str_starts_with($text, 'A級チ')) {
            return 'A_CHALLENGE';
        }

        // Special amateur/men's festival abbreviations are not inferred as A1/A2.
        return in_array($text, ['A級一般', 'A級予選', 'A級予選1', 'A級予選2', 'A級初特選', 'A級決勝',
            'A級準決勝', 'A級特一般', 'A級特予選', 'A級特選', 'A級選抜', 'A級A級F'], true) ? 'A1_A2' : 'UNKNOWN';
    }

    public static function stage(?string $raw): string
    {
        $text = self::text($raw);
        foreach (['準決勝' => 'SEMIFINAL', '準決' => 'SEMIFINAL', '決勝' => 'FINAL', '予選' => 'QUALIFIER',
            '予選1' => 'QUALIFIER', '予選2' => 'QUALIFIER', '一般' => 'GENERAL', '特選' => 'SPECIAL_SELECTION', '選抜' => 'SELECTION'] as $suffix => $stage) {
            if (str_ends_with($text, $suffix)) {
                return $stage;
            }
        }

        return 'UNKNOWN';
    }

    public static function validate(array $row): void
    {
        if (! in_array($row['year'] ?? null, [2024, 2025], true) || ! is_int($row['race_id'] ?? null) || $row['race_id'] < 1
            || ! is_string($row['race_date'] ?? null) || ! str_starts_with($row['race_date'], $row['year'].'-')
            || ! is_int($row['entrant_count'] ?? null) || $row['entrant_count'] < 5 || $row['entrant_count'] > 9
            || (($row['meeting_id'] ?? null) !== null && (! is_int($row['meeting_id']) || $row['meeting_id'] < 1))) {
            throw new RuntimeException('Invalid meeting analysis identity/year/count.');
        }
    }
}
