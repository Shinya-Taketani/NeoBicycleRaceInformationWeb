<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\DTO;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class RaceRosterSnapshotDto
{
    public const VERSION = 'STAT46-PJ0315-ROSTER-SNAPSHOT-v1';

    public function __construct(public array $attributes)
    {
        $date = $attributes['race_date'] ?? '';
        $parsed = is_string($date) ? DateTimeImmutable::createFromFormat('!Y-m-d', $date) : false;
        if (! $parsed || $parsed->format('Y-m-d') !== $date
            || ! is_int($attributes['race_id'] ?? null) || $attributes['race_id'] < 1
            || ! is_int($attributes['scraping_fetch_log_id'] ?? null) || $attributes['scraping_fetch_log_id'] < 1
            || ! is_string($attributes['track_code'] ?? null) || preg_match('/^[0-9]+$/D', $attributes['track_code']) !== 1
            || ! is_int($attributes['race_number'] ?? null) || $attributes['race_number'] < 1 || $attributes['race_number'] > 12) {
            throw new InvalidArgumentException('Invalid roster snapshot race identity.');
        }
        self::validateEntries($attributes['entries'] ?? []);
        if (($attributes['entrant_count'] ?? null) !== count($attributes['entries'])) {
            throw new InvalidArgumentException('Roster snapshot count mismatch.');
        }
    }

    public static function validateEntries(array $entries): void
    {
        if (! array_is_list($entries) || count($entries) < 1 || count($entries) > 9) {
            throw new InvalidArgumentException('A complete roster of 1..9 entries is required.');
        }
        $bikes = $registrations = [];
        foreach ($entries as $entry) {
            $bike = $entry['bike_number'] ?? null;
            $registration = $entry['external_player_id'] ?? null;
            $frame = $entry['frame_number'] ?? null;
            if (! is_int($bike) || $bike < 1 || $bike > 9 || isset($bikes[$bike])
                || ! is_string($registration) || preg_match('/^[0-9]{6}$/D', $registration) !== 1
                || isset($registrations['r'.$registration]) || ! array_key_exists('frame_number', $entry)
                || ($frame !== null && (! is_int($frame) || $frame < 1 || $frame > 9))) {
                throw new InvalidArgumentException('Duplicate or invalid roster bike/registration/frame.');
            }
            $bikes[$bike] = true;
            $registrations['r'.$registration] = true;
        }
    }
}
