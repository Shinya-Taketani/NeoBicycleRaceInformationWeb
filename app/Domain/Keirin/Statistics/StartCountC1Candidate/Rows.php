<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\StartCountC1Candidate;

use App\Domain\Keirin\Statistics\AgariC1Context\Contract as Context;
use App\Domain\Keirin\Statistics\AgariC1Input\Contract as C1;
use RuntimeException;

final class Rows
{
    public const BASE = ['source', 'fetch_log_id', 'race', 'fetched_at', 'fetched_at_meaning', 'source_parser_version',
        'version', 'raw_file_path', 'original_sha256'];

    public static function observations(string $path): \Generator
    {
        if (is_link($path) || ! is_file($path) || ($handle = fopen($path, 'rb')) === false) {
            throw new RuntimeException('Unreadable snapshot JSONL.');
        }
        try {
            while (($line = fgets($handle, 1048577)) !== false) {
                if (! str_ends_with($line, "\n")) {
                    throw new RuntimeException('Truncated or oversized snapshot row.');
                }
                $native = json_decode($line, false, 64, JSON_THROW_ON_ERROR);
                if (! $native instanceof \stdClass) {
                    throw new RuntimeException('Snapshot must be an object.');
                }
                $row = json_decode($line, true, 64, JSON_THROW_ON_ERROR);
                // Preserve nested JSON object/list distinctions in the original S value.
                if (isset($native->field) && property_exists($native->field, 'raw')) {
                    $row['field']['raw'] = $native->field->raw;
                }
                yield $row;
            }
            if (! feof($handle)) {
                throw new RuntimeException('Snapshot read failed.');
            }
        } finally {
            fclose($handle);
        }
    }

    public static function base(array $r, int $year): void
    {
        Contract::year($year);
        C1::keys($r['race'], ['race_id', 'race_date', 'track_code', 'race_number']);
        if (! C1::id($r['race']['race_id']) || ! C1::date($r['race']['race_date'])
            || (int) substr($r['race']['race_date'], 0, 4) !== $year || ! C1::id($r['fetch_log_id'])
            || $r['source'] !== 'keirin_jp' || $r['version'] !== 'STAT36-START-COUNT-v1'
            || $r['fetched_at_meaning'] !== 'SYSTEM_FETCH_TIME' || ! C1::id($r['race']['race_number'])
            || ! is_string($r['race']['track_code']) || ! preg_match('/\A[0-9]+\z/D', $r['race']['track_code'])
            || ($r['fetched_at'] !== null && ! is_string($r['fetched_at']))) {
            throw new RuntimeException('Invalid snapshot race/source identity.');
        }
    }

    public static function snapshot(array $r, int $year): void
    {
        C1::keys($r, [...self::BASE, 'converted_sha256', 'row_index', 'entry_id', 'bike_number', 'ledger_external_id',
            'observed_external_id', 'observed_bike', 'pc0201_external_id', 'identity_issues', 'field', 'displayed_start_count',
            'value_signature', 'source_pointer', 'observed_race', 'aggregation_period', 'statistical_as_of',
            'correction_as_of', 'timing_status', 'historical_as_of_available', 'prediction_use', 'points']);
        self::base($r, $year);
        C1::keys($r['field'], ['presence', 'raw', 'type', 'status', 'parsed_value']);
        C1::keys($r['observed_race'], ['race_date', 'track_code', 'race_number']);
        foreach (['original_sha256', 'converted_sha256', 'value_signature'] as $key) {
            if (! is_string($r[$key]) || ! preg_match('/\A[0-9a-f]{64}\z/D', $r[$key])) {
                throw new RuntimeException('Invalid observation hash.');
            }
        }
        $field = $r['field'];
        if (! is_int($r['row_index']) || $r['row_index'] < 0 || ! is_array($r['identity_issues']) || ! array_is_list($r['identity_issues'])
            || ! in_array($field['status'], ['NUMERIC', 'MISSING', 'NULL', 'EMPTY_STRING', 'MISSING_SYMBOL', 'INVALID_FORMAT', 'OUT_OF_RANGE'], true)
            || ! in_array($field['presence'], ['MISSING', 'NULL', 'PRESENT'], true) || ! is_string($field['type'])
            || ($r['entry_id'] !== null && ! C1::id($r['entry_id'])) || ($r['bike_number'] !== null && (! C1::id($r['bike_number']) || $r['bike_number'] > 9))
            || ! is_string($r['source_pointer']) || $r['source_pointer'] !== 'PJ0315.sensyuTypeInfo['.$r['row_index'].'].stTori'
            || $r['historical_as_of_available'] !== false || $r['prediction_use'] !== 'NOT_AUTHORIZED' || $r['points'] !== null
            || $r['aggregation_period'] !== null || $r['statistical_as_of'] !== null || $r['correction_as_of'] !== null
            || $r['timing_status'] !== 'UNKNOWN_NO_S_SPECIFIC_PERIOD_EVIDENCE') {
            throw new RuntimeException('Unknown/contradictory snapshot state.');
        }
        if ($field['status'] === 'NUMERIC') {
            if (! is_int($field['parsed_value']) || $field['parsed_value'] < 0 || $field['parsed_value'] > 9999
                || ! in_array($field['type'], ['int', 'string'], true) || get_debug_type($field['raw']) !== $field['type']
                || ! preg_match('/\A[0-9]+\z/D', (string) $field['raw'])
                || ltrim((string) $field['raw'], '0') !== ltrim((string) $field['parsed_value'], '0') || $field['presence'] !== 'PRESENT') {
                throw new RuntimeException('Numeric S field contradicts its saved value.');
            }
        } elseif ($field['parsed_value'] !== null) {
            throw new RuntimeException('Non-numeric S field contains a numeric value.');
        }
        $stateValid = match ($field['status']) {
            'MISSING' => $field['presence'] === 'MISSING' && $field['raw'] === null && $field['type'] === 'MISSING',
            'NULL' => $field['presence'] === 'NULL' && $field['raw'] === null && $field['type'] === 'null',
            'EMPTY_STRING' => $field['presence'] === 'PRESENT' && $field['raw'] === '' && $field['type'] === 'string',
            'MISSING_SYMBOL' => $field['presence'] === 'PRESENT' && $field['raw'] === '－' && $field['type'] === 'string',
            default => $field['presence'] === 'PRESENT' && get_debug_type($field['raw']) === $field['type'],
        };
        if (! $stateValid) {
            throw new RuntimeException('S presence/raw/type contradiction.');
        }
        if ($r['displayed_start_count'] !== ($r['identity_issues'] === [] ? $field['parsed_value'] : null)) {
            throw new RuntimeException('Snapshot value/identity contradiction.');
        }
        foreach ($r['identity_issues'] as $issue) {
            if (! is_string($issue) || ! in_array($issue, ['RACE_IDENTITY_MISMATCH', 'INVALID_BIKE', 'INVALID_EXTERNAL_ID',
                'DUPLICATE_BIKE', 'DUPLICATE_EXTERNAL_ID', 'EXTRA_OR_INVALID_ENTRY', 'LEDGER_IDENTITY_MISMATCH', 'PC0201_PJ0315_IDENTITY_MISMATCH'], true)) {
                throw new RuntimeException('Unknown snapshot identity issue.');
            }
        }
    }

    public static function unresolved(array $r, int $year): void
    {
        if (($r['reason'] ?? null) === 'NO_PJ0315_CANDIDATE') {
            C1::keys($r, ['race', 'reason']);
            Contract::year((int) substr($r['race']['race_date'] ?? '', 0, 4));
            if (! C1::id($r['race']['race_id']) || ! C1::date($r['race']['race_date'])
                || (int) substr($r['race']['race_date'], 0, 4) !== $year) {
                throw new RuntimeException('Unresolved target partition mismatch.');
            }

            return;
        }
        $extra = array_intersect(['converted_sha256', 'row_index', 'bike_number', 'issues'], array_keys($r));
        C1::keys($r, [...self::BASE, 'reason', ...$extra]);
        self::base($r, $year);
        if (! is_string($r['reason']) || $r['reason'] === '') {
            throw new RuntimeException('Invalid unresolved reason.');
        }
    }

    public static function mapping(array $m): array
    {
        C1::keys($m, ['year', 'race_id', 'entry_id', 'extraction_record_line', 'target', 'context', 'race_class',
            'player_id_status', 'checks', 'timing', 'reasons', 'candidate']);
        Contract::year($m['year']);
        Context::target($m['target'], $m['year']);
        C1::keys($m['context'], ['source', 'external_player_id', 'race_id', 'entry_id', 'bike', 'race_date',
            'meeting', 'race_type', 'observed_at', 'source_record_id']);
        C1::keys($m['context']['meeting'], ['meeting_id', 'starts_on', 'ends_on']);
        C1::keys($m['checks'], ['db_matched', 'valid_external_id', 'identity_matched', 'meeting_matched', 'class_known']);
        C1::keys($m['timing'], ['entry_fetched_at', 'field_observation_time_verified', 'historical_as_of_available', 'meaning']);
        $t = $m['target'];
        $c = $m['context'];
        if ($m['race_id'] !== $t['race_id'] || $m['entry_id'] !== $t['entry_id'] || ! is_array($m['reasons'])
            || ! array_is_list($m['reasons']) || ! is_bool($m['candidate']) || ! is_string($m['race_class']) || ! is_string($m['player_id_status'])) {
            throw new RuntimeException('Invalid mapping identity/schema.');
        }
        foreach ($m['checks'] as $v) {
            if (! is_bool($v)) {
                throw new RuntimeException('Mapping checks must be explicit booleans.');
            }
        }
        $reasons = [];
        if ($m['checks']['identity_matched'] !== true || $m['player_id_status'] !== 'MATCH' || ! C1::id($t['player_id'])
            || $m['checks']['valid_external_id'] !== true || ! is_string($c['external_player_id'])
            || ! preg_match('/\A[0-9]{6}\z/D', $c['external_player_id'])) {
            $reasons[] = 'UNVERIFIED_MAPPING_IDENTITY';
        }
        if ($m['checks']['meeting_matched'] !== true || $c['source'] !== 'keirin_jp' || $c['race_id'] !== $t['race_id']
            || $c['entry_id'] !== $t['entry_id'] || $c['bike'] !== $t['bike'] || $c['race_date'] !== $t['race_date']
            || $c['meeting']['meeting_id'] !== $t['meeting_id'] || $c['meeting']['starts_on'] !== $t['meeting_start']
            || $c['meeting']['ends_on'] !== $t['meeting_end'] || ! C1::date($t['meeting_start']) || ! C1::date($t['meeting_end'])
            || $t['meeting_start'] > $t['race_date'] || $t['meeting_end'] < $t['race_date']) {
            $reasons[] = 'UNVERIFIED_MAPPING_MEETING_OR_ASSOCIATION';
        }
        foreach ($m['reasons'] as $reason) {
            if (! is_string($reason)) {
                throw new RuntimeException('Invalid mapping reason.');
            }
            if ($reason !== 'UNKNOWN_RACE_CLASS') {
                $reasons[] = 'MAPPING_'.$reason;
            }
        }

        return array_values(array_unique($reasons));
    }
}
