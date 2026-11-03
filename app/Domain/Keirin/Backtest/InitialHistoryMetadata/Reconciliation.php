<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\InitialHistoryMetadata;

use App\Domain\Keirin\Backtest\ChronologicalValidation\Json;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

final class Reconciliation
{
    public static function instant(mixed $raw): ?string
    {
        if (! is_string($raw) || ! preg_match('/\A(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2}):(\d{2})(?:\.\d{1,6})?(?:Z|[+-]\d{2}(?::?\d{2})?)\z/', $raw, $m)
            || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1]) || (int) $m[4] > 23 || (int) $m[5] > 59 || (int) $m[6] > 59) {
            return null;
        }
        try {
            return (new DateTimeImmutable($raw))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.uP');
        } catch (Throwable) {
            return null;
        }
    }

    public static function row(string $type, array $scope, ?array $current, array $related = []): array
    {
        $differences = $times = $reasons = [];
        $expected = $scope['expected'];
        if ($current !== null) {
            foreach ($expected as $field => $value) {
                $actual = $current[$field] ?? null;
                $time = str_ends_with($field, '_at');
                $same = $time && $value !== null && $actual !== null
                    ? self::instant($value) !== null && self::instant($value) === self::instant($actual)
                    : array_key_exists($field, $current) && Json::hash($value) === Json::hash($actual);
                if (! $same) {
                    $differences[] = $field;
                }
            }
            foreach ($current as $field => $value) {
                if (str_ends_with($field, '_at')) {
                    $times[$field] = ['raw' => $value, 'utc' => self::instant($value)];
                    if ($value !== null && $times[$field]['utc'] === null) {
                        $reasons[] = 'INVALID_INSTANT:'.$field;
                    }
                }
            }
        }
        $classification = $current === null ? 'NOT_FOUND_IN_FIXED_PERIOD_SOURCE' : ($differences === [] ? 'MATCH' : 'CHANGED');
        if ($current === null) {
            $reasons[] = 'NOT_FOUND_IN_FIXED_PERIOD_SOURCE';
        }
        if ($differences !== []) {
            $reasons[] = 'EXPECTED_VALUE_CHANGED';
        }
        if ($type === 'CONTEXT') {
            if ($expected['result_status'] === 'CANCELLED') {
                $reasons[] = 'KNOWN_CANCELLED_RACE';
            }
            if (($related['fixed_imports'] ?? 1) === 0) {
                $reasons[] = 'NO_RESULT_FETCH_EVIDENCE';
            }
            foreach (['racetrack_id', 'race_day_id', 'day_number', 'meeting_end', 'meeting_grade', 'meeting_day_kind'] as $field) {
                if ($current !== null && $current[$field] === null) {
                    $reasons[] = 'NULL_CONTEXT:'.$field;
                }
            }
        }
        $timing = [];
        $at = fn (string $f): ?string => $current === null ? null : self::instant($current[$f] ?? null);
        if ($type === 'FETCH') {
            $failed = ($current ?? $expected)['error_type'] !== null || ($current ?? $expected)['http_status'] !== 200;
            if ($failed) {
                $reasons[] = 'KNOWN_FETCH_FAILURE';
            }
            $hasRaw = $current !== null && $current['raw_file_path'] !== null && $current['sha256'] !== null && ! $failed;
            if ($hasRaw && $at('fetched_at') !== null && $at('created_at') !== null && $at('fetched_at') <= $at('created_at')) {
                $timing[] = 'RAW_STORED_BEFORE_LOG_RECORD';
            } elseif ($hasRaw) {
                $reasons[] = 'RAW_LOG_TIME_UNESTABLISHED_OR_INVERTED';
            }
        }
        if ($type === 'IMPORT') {
            if (($related['fixed_result_references'] ?? 0) === 0) {
                $reasons[] = 'NOT_REFERENCED_BY_FIXED_CURRENT_RESULTS';
                $reasons[] = 'VERSION_TIME_NOT_ESTABLISHED_NO_FIXED_RESULT_ROWS';
            }
            $fetch = $related['fetch']['current'] ?? null;
            $fetched = self::instant($fetch['fetched_at'] ?? null);
            if ($classification === 'MATCH' && $fetched !== null && $at('updated_at') !== null && $fetched <= $at('updated_at') && $current['import_status'] === 'SUCCEEDED') {
                $timing[] = 'IMPORT_STATUS_RECORD_TIME';
            }
        }
        if ($type === 'RESULT') {
            $import = $related['import'] ?? null;
            $fetch = $related['fetch'] ?? null;
            $acquired = self::instant($fetch['current']['fetched_at'] ?? null);
            if ($current !== null && $current['race_result_import_id'] === $expected['race_result_import_id']
                && ($import['classification'] ?? null) === 'MATCH' && ($fetch['classification'] ?? null) === 'MATCH'
                && $acquired !== null && $at('fetched_at') !== null && $at('fetched_at') >= $acquired) {
                $timing[] = 'RESULT_VERSION_PROCESSING_TIME';
            } else {
                $reasons[] = 'VERSION_TIME_NOT_ESTABLISHED';
            }
            // created_at belongs to first row creation, not necessarily this import.
            if ($current !== null && $current['race_result_import_id'] !== $expected['race_result_import_id']) {
                $reasons[] = 'RESULT_IMPORT_REPLACED';
            }
        }
        sort($reasons);
        sort($differences);
        $newColumns = $current === null ? [] : array_values(array_diff(array_keys($current), array_keys($expected)));
        sort($newColumns);

        return ['record_type' => $type, 'scope' => $scope, 'current' => $current, 'classification' => $classification,
            'differences' => $differences, 'new_columns' => $newColumns,
            'normalized_times' => $times, 'timing_evidence' => $timing, 'reasons' => $reasons];
    }

    public static function meanings(): array
    {
        return ['RAW_STORED_BEFORE_LOG_RECORD' => 'fetch.created_at records a log after Raw storage; not exact Raw finish or commit.',
            'IMPORT_STATUS_RECORD_TIME' => 'import.updated_at is generic final state record inside transaction; not commit.',
            'RESULT_VERSION_PROCESSING_TIME' => 'race_results.fetched_at is application processing time for the same fixed import; not HTTP acquisition or commit.',
            'result.created_at' => 'First creation of race/bike row; may precede current import acquisition on reimport.',
            'result.updated_at' => 'Generic update; never automatically copied into old-version saved_at.',
            'import.imported_at/created_at' => 'Import start/record; never result save completion.',
            'exported_at/written_at' => 'This export only; not historical timestamps.', 'official_confirmed_at' => null,
            'historical_as_of_available' => false, 'prediction_use' => 'NOT_AUTHORIZED', 'history_event_saved_at_assignment' => 'NOT_RUN'];
    }
}
