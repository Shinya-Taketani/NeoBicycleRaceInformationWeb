<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\ChronologicalValidation;

final class InputManifest
{
    public const IDENTITY = ['original_entry_id', 'original_player_id', 'external_player_id', 'bike'];

    public const PROJECTED_ENTRY = [...self::IDENTITY, 'frame_number', 'grade', 'race_score_raw'];

    public static function keys(array $data, array $allowed, array $optional = []): void
    {
        BundleRules::require(array_diff(array_keys($data), $allowed) === [], 'Unapproved input keys.');
        BundleRules::require(array_diff($allowed, [...array_keys($data), ...$optional]) === [], 'Missing required input keys.');
    }

    public static function validate(array $manifest): void
    {
        self::keys($manifest, ['run_id', 'material_kind', 'scope', 'fixture_root', 'history', 'candidates'], ['fixture_root']);
        BundleRules::require(is_string($manifest['run_id']) && $manifest['run_id'] !== '' && is_string($manifest['material_kind']), 'Invalid manifest identity.');
        self::keys($manifest['scope'], ['kind', 'from', 'to']);
        self::keys($manifest['history'], ['history_event_from', 'sources']);
        Scope::day($manifest['history']['history_event_from']);
        BundleRules::require(array_is_list($manifest['history']['sources']) && array_is_list($manifest['candidates']), 'Manifest lists required.');
        foreach ($manifest['history']['sources'] as $source) {
            self::keys($source, ['path', 'seal', 'material_kind']);
            self::seal($source['seal']);
            BundleRules::require(is_string($source['path']) && is_string($source['material_kind']), 'Invalid history source.');
        }
        foreach ($manifest['candidates'] as $candidate) {
            self::candidate($candidate);
        }
    }

    public static function candidate(array $candidate): void
    {
        self::keys($candidate, ['original_race_id', 'external_race_key', 'race_date', 'track_code', 'race_number', 'entrant_count',
            'meeting_id', 'meeting_start', 'meeting_end', 'day_number', 'context', 'entries', 'capture']);
        self::keys($candidate['context'], ['racetrack_id', 'race_day_id', 'meeting_grade', 'meeting_day_kind']);
        foreach (['original_race_id', 'meeting_id', 'day_number'] as $key) {
            self::id($candidate[$key]);
        }
        foreach (['racetrack_id', 'race_day_id'] as $key) {
            self::id($candidate['context'][$key]);
        }
        foreach (['meeting_grade', 'meeting_day_kind'] as $key) {
            BundleRules::require($candidate['context'][$key] === null || is_string($candidate['context'][$key]), 'Invalid context field type.');
        }
        BundleRules::require(is_string($candidate['external_race_key']) && is_string($candidate['track_code']) && ctype_digit($candidate['track_code'])
            && is_int($candidate['race_number']) && $candidate['race_number'] > 0 && is_int($candidate['entrant_count']) && $candidate['entrant_count'] >= 0
            && array_is_list($candidate['entries']), 'Invalid candidate types.');
        Scope::day($candidate['race_date']);
        foreach (['meeting_start', 'meeting_end'] as $key) {
            if ($candidate[$key] !== null) {
                Scope::day($candidate[$key]);
            }
        }
        foreach ($candidate['entries'] as $entry) {
            self::keys($entry, self::IDENTITY);
            self::identity($entry);
        }
        if ($candidate['capture'] !== null) {
            $c = $candidate['capture'];
            self::keys($c, ['capture_id', 'source', 'method', 'material_kind', 'source_status', 'parser_version', 'private_ref', 'raw', 'content_type', 'fetched_at', 'saved_at']);
            self::seal($c['raw']);
            foreach (array_diff(array_keys($c), ['raw']) as $key) {
                BundleRules::require(is_string($c[$key]), 'Invalid capture field type.');
            }
            Scope::instant($c['fetched_at']);
            Scope::instant($c['saved_at']);
        }
    }

    public static function projectedEntries(array $entries): void
    {
        foreach ($entries as $entry) {
            self::keys($entry, self::PROJECTED_ENTRY);
            self::identity($entry);
            BundleRules::require(! in_array(null, array_intersect_key($entry, array_flip(self::IDENTITY)), true), 'Normal input requires resolved original identities.');
        }
    }

    private static function identity(array $entry): void
    {
        self::id($entry['original_entry_id']);
        self::id($entry['original_player_id']);
        BundleRules::require(is_int($entry['bike']) && $entry['bike'] > 0 && $entry['bike'] <= 9
            && ($entry['external_player_id'] === null || (is_string($entry['external_player_id']) && ctype_digit($entry['external_player_id']))), 'Invalid entry identity type.');
    }

    private static function id(mixed $value): void
    {
        BundleRules::require($value === null || (is_int($value) && $value > 0), 'Invalid input ID type.');
    }

    private static function seal(array $seal): void
    {
        self::keys($seal, ['bytes', 'sha256']);
        BundleRules::require(is_int($seal['bytes']) && $seal['bytes'] >= 0 && is_string($seal['sha256']) && preg_match('/^[a-f0-9]{64}$/D', $seal['sha256']) === 1, 'Invalid source seal.');
    }
}
