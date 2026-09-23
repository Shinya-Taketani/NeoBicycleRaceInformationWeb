<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\AgariRaceRelative;

use App\Domain\Keirin\TrackContext\StructureValues;
use RuntimeException;

final class Contract
{
    public const VERSION = 'STAT35-RACE-RELATIVE-v1';

    public const DEFINITION = 'keirin-jp-final-back-half-lap-v1';

    public const DISCLOSURE = [
        'analysis_mode' => 'FINAL_RESULT_DESCRIPTIVE_ONLY',
        'historical_as_of_available' => false,
        'prediction_use' => 'NOT_AUTHORIZED',
        'points' => null,
    ];

    public static function dates(string $from, string $to): void
    {
        StructureValues::date($from);
        StructureValues::date($to);
        if ($from < '2022-01-01' || $to > '2025-12-31' || $from > $to) {
            throw new RuntimeException('Only ordered 2022-2025 development dates are authorized.');
        }
    }

    public static function race(array $row, string $from, string $to, int $previousId): void
    {
        self::dates($from, $to);
        if (($row['schema'] ?? null) !== self::VERSION || ($row['source'] ?? null) !== 'keirin_jp'
            || ! is_int($row['race_id'] ?? null) || $row['race_id'] <= $previousId
            || ! is_string($row['race_date'] ?? null) || ! is_array($row['results'] ?? null)
            || ! array_is_list($row['results']) || count($row['results']) > 100
            || ! is_array($row['context'] ?? null)) {
            throw new RuntimeException('Invalid snapshot schema, race identity/order or result collection.');
        }
        StructureValues::date($row['race_date']);
        if ($row['race_date'] < $from || $row['race_date'] > $to) {
            throw new RuntimeException('Race outside sealed development interval.');
        }
        foreach (['race_status', 'race_type'] as $key) {
            if (! array_key_exists($key, $row) || ($row[$key] !== null && ! is_string($row[$key]))) {
                throw new RuntimeException('Invalid race state schema.');
            }
        }
        foreach (['meeting_id', 'meeting_grade_raw', 'race_grade_raw', 'track_code', 'race_day_id', 'racetrack_id',
            'day_date', 'starts_on', 'ends_on', 'meeting_track_id', 'meeting_race_grade_raw_values'] as $key) {
            if (! array_key_exists($key, $row['context'])) {
                throw new RuntimeException('Missing race context: '.$key);
            }
        }
        foreach ($row['results'] as $entry) {
            if (! is_array($entry) || ! is_int($entry['id'] ?? null) || $entry['id'] < 1
                || ! is_int($entry['bike_number'] ?? null) || ! array_key_exists('import', $entry)
                || ! array_key_exists('observation', $entry)) {
                throw new RuntimeException('Invalid result snapshot schema.');
            }
            foreach (['result_status', 'agari_status', 'agari_raw_text', 'agari_time_seconds'] as $key) {
                if (! array_key_exists($key, $entry) || ($entry[$key] !== null && ! is_string($entry[$key]))) {
                    throw new RuntimeException('Missing/invalid result value field: '.$key);
                }
            }
        }
    }
}
