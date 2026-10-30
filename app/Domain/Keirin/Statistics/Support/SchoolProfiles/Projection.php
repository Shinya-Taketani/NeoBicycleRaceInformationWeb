<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\Support\SchoolProfiles;

use App\Domain\Keirin\Statistics\Support\AcquiredHistoryTimestamp;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

final class Projection
{
    public static function validate(array $p, array $source): void
    {
        $id = $p['external_player_id'] ?? null;
        $s = $p['source'] ?? [];
        if (($p['version'] ?? null) !== 'school-profile-projection-v1' || ! is_string($id) || preg_match('/^\d{6}$/D', $id) !== 1
            || ($source['external_player_id'] ?? null) !== $id || ! is_int($s['fetch_log_id'] ?? null) || $s['fetch_log_id'] < 1
            || ($source['fetch_log_id'] ?? null) !== $s['fetch_log_id'] || (isset($p['fetch_log_id']) && $p['fetch_log_id'] !== $s['fetch_log_id'])
            || ! is_int($s['bytes'] ?? null) || $s['bytes'] < 1 || ($source['bytes'] ?? null) !== $s['bytes']
            || ! is_string($s['sha256'] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $s['sha256']) !== 1
            || ($source['sha256'] ?? null) !== $s['sha256'] || ! is_string($s['parser_version'] ?? null) || $s['parser_version'] === ''
            || ($source['parser_version'] ?? null) !== $s['parser_version']
            || ($p['data_role'] ?? null) !== 'RETROSPECTIVE_ONLY' || ($p['prediction_use'] ?? null) !== 'NOT_AUTHORIZED'
            || ($p['historical_as_of_available'] ?? null) !== false || ! array_key_exists('points', $p) || $p['points'] !== null
            || ! array_key_exists('confidence', $p) || $p['confidence'] !== null
            || ! is_array($p['STAT-02'] ?? null) || ! is_array($p['STAT-03'] ?? null) || ! is_array($p['original_fields'] ?? null)) {
            throw new RuntimeException('School projection identity/contract/source mismatch.');
        }
        if (AcquiredHistoryTimestamp::parse($s['fetched_at'] ?? null)->format('U.u')
            !== AcquiredHistoryTimestamp::parse($source['fetched_at'] ?? null)->format('U.u')) {
            throw new RuntimeException('School projection fetch time mismatch.');
        }
        $uri = parse_url($source['source_url'] ?? '');
        parse_str($uri['query'] ?? '', $query);
        if (($uri['scheme'] ?? null) !== 'https' || ($uri['host'] ?? null) !== 'keirin.jp' || ($uri['path'] ?? null) !== '/pc/racerprofile'
            || ($query['snum'] ?? null) !== $id || ! is_string($source['raw_file_path'] ?? null) || $source['raw_file_path'] === '') {
            throw new RuntimeException('School source URL/Raw reference mismatch.');
        }
        foreach (['school_rank_raw' => $p['STAT-02']['school_rank_raw'] ?? null, 'graduation_period' => $p['graduation_period'] ?? null] as $value) {
            if ($value !== null && (! is_int($value) || $value < 1)) {
                throw new RuntimeException('Invalid school integer projection.');
            }
        }
        $time = $p['STAT-03']['school_200m_seconds'] ?? null;
        if ($time !== null && ((! is_int($time) && ! is_float($time)) || ! is_finite((float) $time) || $time <= 0)) {
            throw new RuntimeException('Invalid school time projection.');
        }
        $debut = $p['debut_date'] ?? null;
        if ($debut !== null && self::date($debut) === null) {
            throw new RuntimeException('Invalid projected debut date.');
        }
        $raw = $p['original_fields']['debut_date_raw'] ?? null;
        if ($debut !== null && (! is_string($raw) || str_replace('/', '-', $raw) !== $debut)) {
            throw new RuntimeException('Projected debut date and original text disagree.');
        }
    }

    public static function date(mixed $value): ?DateTimeImmutable
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('Asia/Tokyo'));

        return $date !== false && $date->format('Y-m-d') === $value ? $date : null;
    }

    public static function state(mixed $value, mixed $raw): string
    {
        return $value !== null ? 'VALID' : ($raw === null || in_array($raw, ['', '-', '－'], true) ? 'MISSING' : 'INVALID');
    }

    public static function fields(array $p): array
    {
        return ['school_rank' => ['value' => $p['STAT-02']['school_rank_raw'] ?? null, 'raw' => $p['original_fields']['競輪学校順位'] ?? null],
            'school_200m' => ['value' => $p['STAT-03']['school_200m_seconds'] ?? null, 'raw' => $p['original_fields']['200mタイム'] ?? null],
            'graduation_period' => ['value' => $p['graduation_period'] ?? null, 'raw_status' => 'NOT_RECORDED_IN_PROJECTION_V1'],
            'debut_date' => ['value' => $p['debut_date'] ?? null, 'raw' => $p['original_fields']['debut_date_raw'] ?? null]];
    }
}
