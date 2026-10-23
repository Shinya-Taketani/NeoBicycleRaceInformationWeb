<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\Calculators;

use App\Domain\Keirin\Scraping\DTO\RaceRosterSnapshotDto;
use DateTimeImmutable;

final class Stat46RosterDifferenceCalculator
{
    public const VERSION = 'STAT46-OBSERVED-ROSTER-DIFFERENCE-v1';

    public function calculate(?RaceRosterSnapshotDto $first, ?RaceRosterSnapshotDto $second, ?DateTimeImmutable $inputAsOf = null): array
    {
        $result = ['calculation_version' => self::VERSION, 'status' => 'INSUFFICIENT_SNAPSHOTS',
            'official_entry_change_type' => null, 'individual_adjustment' => null, 'race_reconstruction_impact' => null,
            'uncertainty_score' => null, 'points' => null, 'confidence' => null, 'prediction_use' => 'NOT_AUTHORIZED',
            'historical_as_of_available' => false, 'use_class' => 'RETROSPECTIVE_ONLY',
            'input_as_of' => $inputAsOf?->format(DATE_ATOM), 'captures' => [], 'difference' => null];
        foreach ([$first, $second] as $dto) {
            if ($dto !== null) {
                $result['captures'][] = $this->capture($dto->attributes, $inputAsOf);
            }
        }
        if ($first === null || $second === null || $first->attributes['scraping_fetch_log_id'] === $second->attributes['scraping_fetch_log_id']) {
            return $result;
        }
        $a = $first->attributes;
        $b = $second->attributes;
        foreach (['race_id', 'race_date', 'track_code', 'race_number', 'source', 'source_url'] as $key) {
            if ($a[$key] !== $b[$key]) {
                return [...$result, 'status' => 'SOURCE_MISMATCH'];
            }
        }
        if ($a['snapshot_version'] !== RaceRosterSnapshotDto::VERSION || $b['snapshot_version'] !== RaceRosterSnapshotDto::VERSION) {
            return [...$result, 'status' => 'NOT_ESTABLISHED'];
        }
        if ($a['fetched_at'] === null || $b['fetched_at'] === null) {
            return [...$result, 'status' => 'AMBIGUOUS_OBSERVATION_ORDER'];
        }
        $timeA = new DateTimeImmutable($a['fetched_at']);
        $timeB = new DateTimeImmutable($b['fetched_at']);
        if ($timeA == $timeB && ($a['raw_sha256'] !== $b['raw_sha256'] || $this->roster($a) !== $this->roster($b))) {
            return [...$result, 'status' => 'AMBIGUOUS_OBSERVATION_ORDER'];
        }
        if ($timeA > $timeB) {
            [$a, $b] = [$b, $a];
        }
        $before = $this->roster($a);
        $after = $this->roster($b);
        $oldByBike = array_column($before, null, 'bike_number');
        $newByBike = array_column($after, null, 'bike_number');
        $oldByRider = array_column($before, null, 'external_player_id');
        $newByRider = array_column($after, null, 'external_player_id');
        $bikeChanges = $frameChanges = $riderChanges = $unverifiedFrames = [];
        foreach ($oldByBike as $bike => $old) {
            $new = $newByBike[$bike] ?? null;
            if ($new !== null && $old['external_player_id'] !== $new['external_player_id']) {
                $riderChanges[] = ['bike_number' => $bike, 'before' => $old['external_player_id'], 'after' => $new['external_player_id']];
            }
        }
        foreach ($before as $old) {
            $new = $newByRider[$old['external_player_id']] ?? null;
            if ($new === null) {
                continue;
            }
            if ($old['bike_number'] !== $new['bike_number']) {
                $bikeChanges[] = ['external_player_id' => $old['external_player_id'], 'before' => $old['bike_number'], 'after' => $new['bike_number']];
            }
            if ($old['frame_number'] === null || $new['frame_number'] === null) {
                $unverifiedFrames[] = $old['external_player_id'];
            } elseif ($old['frame_number'] !== $new['frame_number']) {
                $frameChanges[] = ['external_player_id' => $old['external_player_id'], 'before' => $old['frame_number'], 'after' => $new['frame_number']];
            }
        }
        $added = array_values(array_diff(array_column($after, 'external_player_id'), array_column($before, 'external_player_id')));
        $removed = array_values(array_diff(array_column($before, 'external_player_id'), array_column($after, 'external_player_id')));
        $changed = $added !== [] || $removed !== [] || $bikeChanges !== [] || $frameChanges !== [];

        return [...$result, 'status' => $changed ? 'OBSERVED_ROSTER_CHANGED' : ($unverifiedFrames === [] ? 'OBSERVED_ROSTER_UNCHANGED' : 'NOT_ESTABLISHED'),
            'observation_order' => $timeA == $timeB ? 'SAME_INSTANT_SAME_RAW' : 'FETCHED_AT_STRICT_ORDER',
            'before_capture' => $this->capture($a, $inputAsOf), 'after_capture' => $this->capture($b, $inputAsOf),
            'difference' => ['entrant_count_before' => count($before), 'entrant_count_after' => count($after),
                'entrant_count_delta' => count($after) - count($before), 'added_registrations' => $added,
                'removed_registrations' => $removed, 'rider_changes_at_same_bike' => $riderChanges,
                'bike_changes' => $bikeChanges, 'frame_changes' => $frameChanges,
                'frame_comparison_unknown_registrations' => $unverifiedFrames, 'before' => $before, 'after' => $after]];
    }

    private function roster(array $snapshot): array
    {
        $entries = array_map(fn ($row) => array_intersect_key($row, array_flip(['bike_number', 'frame_number', 'external_player_id'])), $snapshot['entries']);
        usort($entries, fn ($a, $b) => $a['bike_number'] <=> $b['bike_number']);

        return $entries;
    }

    private function capture(array $snapshot, ?DateTimeImmutable $asOf): array
    {
        $fetched = $snapshot['fetched_at'] === null ? null : new DateTimeImmutable($snapshot['fetched_at']);
        $saved = $snapshot['fetch_log_saved_at'] === null ? null : new DateTimeImmutable($snapshot['fetch_log_saved_at']);
        $start = $snapshot['scheduled_start_at'] === null ? null : new DateTimeImmutable($snapshot['scheduled_start_at']);

        return ['fetch_log_id' => $snapshot['scraping_fetch_log_id'], 'fetched_at' => $snapshot['fetched_at'],
            'fetch_log_saved_at' => $snapshot['fetch_log_saved_at'], 'scheduled_start_at' => $snapshot['scheduled_start_at'],
            'raw_sha256' => $snapshot['raw_sha256'], 'parser_version' => $snapshot['parser_version'],
            'snapshot_version' => $snapshot['snapshot_version'], 'timing_status' => $snapshot['timing_status'],
            'acquired_and_logged_before_input_as_of' => $asOf === null || $fetched === null || $saved === null ? null
                : ($fetched <= $saved && $fetched < $asOf && $saved < $asOf),
            'input_as_of_before_same_raw_start' => $asOf === null || $start === null ? null : $asOf < $start,
            'official_publication_status' => 'UNKNOWN', 'prediction_use' => 'NOT_AUTHORIZED'];
    }
}
