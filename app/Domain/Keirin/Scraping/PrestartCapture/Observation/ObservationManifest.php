<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\PrestartCapture\Observation;

use App\Domain\Keirin\Scraping\DTO\RaceRosterSnapshotDto;
use App\Domain\Keirin\Scraping\Enums\RaceCategory;
use App\Domain\Keirin\Scraping\PrestartCapture\Contract as CaptureContract;
use App\Domain\Keirin\Scraping\PrestartCapture\Operational\Contract;
use App\Domain\Keirin\Scraping\Support\RaceCategoryPolicy;
use RuntimeException;

final readonly class ObservationManifest
{
    public function __construct(public array $data)
    {
        if (($data['contract_version'] ?? null) !== ObservationContract::VERSION
            || ! Contract::reference($data['authorization_id'] ?? null) || ! Contract::reference($data['approval_scope'] ?? null)
            || ! is_int($data['revision'] ?? null) || $data['revision'] < 1
            || Contract::instant($data['approved_from'] ?? null) === null || Contract::instant($data['expires_at'] ?? null) === null
            || Contract::instant($data['approved_from']) >= Contract::instant($data['expires_at'])
            || ! is_string($data['storage_root'] ?? null) || ! str_starts_with($data['storage_root'], '/')
            || ! is_int($data['minimum_free_bytes'] ?? null) || $data['minimum_free_bytes'] < 16777216
            || ($data['profile'] ?? null) !== 'SYNTHETIC_ACCEPTANCE_ONLY'
            || Contract::digest(is_array($data['limits'] ?? null) ? $data['limits'] : []) !== Contract::digest(self::syntheticLimits())
            || ! is_array($data['targets'] ?? null) || ! array_is_list($data['targets'])
            || count($data['targets']) < 1 || count($data['targets']) > 2) {
            throw new RuntimeException('Invalid observation manifest or unsupported (unapproved) profile.');
        }
        $ids = $numbers = $dates = $tracks = [];
        foreach ($data['targets'] as $target) {
            $race = $target['race'] ?? [];
            $date = $race['race_date'] ?? null;
            $parsed = is_string($date) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $date) : false;
            $keys = array_keys($target['parameters'] ?? []);
            sort($keys);
            if (! $parsed || $parsed->format('Y-m-d') !== $date || (int) substr($date, 0, 4) < 2022
                || str_starts_with($date, '2026-') || ! is_int($race['race_id'] ?? null) || $race['race_id'] < 1
                || ! is_int($race['race_number'] ?? null) || $race['race_number'] < 1 || $race['race_number'] > 12
                || ! is_string($race['track_code'] ?? null) || preg_match('/^[0-9]{2}$/D', $race['track_code']) !== 1
                || ($race['external_race_id'] ?? null) !== sprintf('%s:%s:%02d', $race['track_code'], str_replace('-', '', $date), $race['race_number'])
                || (new RaceCategoryPolicy)->classify($race['race_type'] ?? null) !== RaceCategory::Men
                || ($target['request_uri'] ?? null) !== ObservationContract::URI || $keys !== ['disp', 'encp']
                || $target['parameters']['disp'] !== 'PJ0315' || ! is_string($target['parameters']['encp'])
                || $target['parameters']['encp'] === '' || strlen($target['parameters']['encp']) > 8192
                || ($target['binding_sha256'] ?? null) !== CaptureContract::binding(ObservationContract::URI, $target['parameters'])
                || ! Contract::sha($target['baseline_snapshot_sha256'] ?? null)
                || $target['baseline_snapshot_sha256'] !== Contract::digest($target['entries'] ?? [])
                || ! Contract::sha($target['identity_evidence_sha256'] ?? null)
                || ! Contract::reference($target['schedule_source'] ?? null)
                || Contract::instant($target['scheduled_start_at'] ?? null) === null
                || (new \DateTimeImmutable($target['scheduled_start_at']))->setTimezone(new \DateTimeZone('Asia/Tokyo'))->format('Y-m-d') !== $date
                || ($target['slots'] ?? null) !== [60, 15]
                || isset($ids[$race['race_id']]) || isset($numbers[$race['race_number']])) {
                throw new RuntimeException('Invalid, duplicate, forbidden-year or unbound PJ0315 target.');
            }
            RaceRosterSnapshotDto::validateEntries($target['entries']);
            $ids[$race['race_id']] = $numbers[$race['race_number']] = true;
            $dates[$date] = $tracks[$race['track_code']] = true;
        }
        if (count($dates) !== 1 || count($tracks) !== 1) {
            throw new RuntimeException('Whole scope must be one track and one date.');
        }
    }

    public static function syntheticLimits(): array
    {
        return ['max_tracks' => 1, 'max_dates' => 1, 'max_races' => 2, 'slots_per_race' => 2, 'max_attempts' => 4,
            'max_concurrent' => 1, 'min_interval_seconds' => 60, 'retry' => 0, 'connect_timeout_seconds' => 5,
            'total_timeout_seconds' => 20, 'slot_window_seconds' => 60];
    }

    public function target(int $raceId): array
    {
        foreach ($this->data['targets'] as $target) {
            if ($target['race']['race_id'] === $raceId) {
                return $target;
            }
        }
        throw new RuntimeException('Race outside authorization scope.');
    }

    public function digest(): string
    {
        return Contract::digest($this->data);
    }
}
