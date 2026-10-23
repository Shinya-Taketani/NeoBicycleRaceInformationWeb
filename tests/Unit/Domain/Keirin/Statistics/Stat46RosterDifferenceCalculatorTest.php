<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Statistics;

use App\Domain\Keirin\Scraping\DTO\RaceRosterSnapshotDto;
use App\Domain\Keirin\Statistics\Calculators\Stat46RosterDifferenceCalculator;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Artificial difference arithmetic, never evidence of a real race's roster changes. */
final class Stat46RosterDifferenceCalculatorTest extends TestCase
{
    public function test_added_removed_and_replaced_riders_are_separate_from_official_change_events(): void
    {
        $before = $this->snapshot(1);
        $after = $this->snapshot(2, entries: [$this->entry(1, '000009'), $this->entry(5, '000003'), $this->entry(7, '000004'), $this->entry(9, '000005')]);
        $result = $this->compare($after, $before);
        $this->assertSame('OBSERVED_ROSTER_CHANGED', $result['status']);
        $delta = $result['difference'];
        $this->assertSame(3, $delta['entrant_count_before']);
        $this->assertSame(4, $delta['entrant_count_after']);
        $this->assertSame(1, $delta['entrant_count_delta']);
        $this->assertSame(['000009', '000004', '000005'], $delta['added_registrations']);
        $this->assertSame(['000001', '000002'], $delta['removed_registrations']);
        $this->assertSame([['bike_number' => 1, 'before' => '000001', 'after' => '000009']], $delta['rider_changes_at_same_bike']);
        $this->assertSame(1, $result['before_capture']['fetch_log_id']);
        $this->assertSame(2, $result['after_capture']['fetch_log_id']);
        foreach (['official_entry_change_type', 'individual_adjustment', 'race_reconstruction_impact', 'uncertainty_score', 'points', 'confidence'] as $key) {
            $this->assertNull($result[$key]);
        }
        $this->assertFalse($result['historical_as_of_available']);
        $this->assertSame('NOT_AUTHORIZED', $result['prediction_use']);
        $this->assertSame('RETROSPECTIVE_ONLY', $result['use_class']);
    }

    public function test_bike_and_frame_changes_for_same_person_are_detected(): void
    {
        $result = $this->compare($this->snapshot(1), $this->snapshot(2, entries: [
            $this->entry(3, '000001', 2), $this->entry(1, '000002', 1), $this->entry(5, '000003', 3),
        ]));
        $this->assertSame('OBSERVED_ROSTER_CHANGED', $result['status']);
        $this->assertSame([], $result['difference']['added_registrations']);
        $this->assertSame([], $result['difference']['removed_registrations']);
        $this->assertSame([
            ['external_player_id' => '000001', 'before' => 1, 'after' => 3],
            ['external_player_id' => '000002', 'before' => 3, 'after' => 1],
        ], $result['difference']['bike_changes']);
        $this->assertSame([
            ['external_player_id' => '000001', 'before' => 1, 'after' => 2],
            ['external_player_id' => '000003', 'before' => 1, 'after' => 3],
        ], $result['difference']['frame_changes']);
    }

    public function test_order_only_changes_leave_the_observed_roster_unchanged_without_mutating_inputs(): void
    {
        $first = $this->snapshot(1);
        $second = $this->snapshot(2, entries: array_reverse($first->attributes['entries']));
        $original = $second->attributes;
        $out = $this->compare($first, $second);
        $this->assertSame('OBSERVED_ROSTER_UNCHANGED', $out['status']);
        $this->assertSame([1, 3, 5], array_column($out['difference']['before'], 'bike_number'));
        $this->assertSame(['000001', '000002', '000003'], array_column($out['difference']['after'], 'external_player_id'));
        $this->assertSame($original, $second->attributes);
    }

    public function test_display_only_changes_are_not_rider_changes(): void
    {
        $first = $this->snapshot(1);
        $entries = $first->attributes['entries'];
        $entries[0] += ['player_name' => 'ARTIFICIAL DISPLAY NAME', 'grade' => 'UNKNOWN', 'riding_style' => null, 'race_score' => '0'];
        $out = $this->compare($first, $this->snapshot(2, entries: $entries));
        $this->assertSame('OBSERVED_ROSTER_UNCHANGED', $out['status']);
    }

    #[DataProvider('ambiguousTimes')]
    public function test_same_instant_different_content_and_unknown_times_never_get_db_id_order(?string $firstTime, ?string $secondTime): void
    {
        $out = $this->compare($this->snapshot(99, time: $firstTime), $this->snapshot(1, time: $secondTime));
        $this->assertSame('AMBIGUOUS_OBSERVATION_ORDER', $out['status']);
        $this->assertNull($out['difference']);
    }

    public static function ambiguousTimes(): iterable
    {
        yield 'identical timestamp' => ['2025-01-01T09:00:00+09:00', '2025-01-01T09:00:00+09:00'];
        yield 'same instant distinct offsets' => ['2025-01-01T09:00:00+09:00', '2025-01-01T00:00:00Z'];
        yield 'first unknown' => [null, '2025-01-01T09:00:00+09:00'];
        yield 'second unknown' => ['2025-01-01T09:00:00+09:00', null];
    }

    public function test_same_hash_does_not_override_a_same_instant_roster_conflict(): void
    {
        $first = $this->snapshot(1);
        $entries = $first->attributes['entries'];
        $entries[0]['external_player_id'] = '000009';
        $second = $this->snapshot(2, entries: $entries, time: $first->attributes['fetched_at'], changes: ['raw_sha256' => $first->attributes['raw_sha256']]);
        $this->assertSame('AMBIGUOUS_OBSERVATION_ORDER', $this->compare($first, $second)['status']);
    }

    public function test_same_raw_same_time_distinct_captures_do_not_infer_change_or_order(): void
    {
        $first = $this->snapshot(1);
        $second = $this->snapshot(2, time: $first->attributes['fetched_at'], changes: ['raw_sha256' => $first->attributes['raw_sha256']]);
        $out = $this->compare($first, $second);
        $this->assertSame('OBSERVED_ROSTER_UNCHANGED', $out['status']);
        $this->assertSame('SAME_INSTANT_SAME_RAW', $out['observation_order']);
    }

    public function test_one_capture_is_not_evidence_of_no_change(): void
    {
        $first = $this->snapshot(1);
        $this->assertSame('INSUFFICIENT_SNAPSHOTS', $this->compare($first, null)['status']);
        $this->assertSame('INSUFFICIENT_SNAPSHOTS', $this->compare(null, $first)['status']);
        $this->assertSame('INSUFFICIENT_SNAPSHOTS', $this->compare($first, $first)['status']);
    }

    #[DataProvider('sourceMismatches')]
    public function test_mismatched_race_or_source_is_not_compared(array $changes): void
    {
        $this->assertSame('SOURCE_MISMATCH', $this->compare($this->snapshot(1), $this->snapshot(2, changes: $changes))['status']);
    }

    public static function sourceMismatches(): iterable
    {
        yield 'race' => [['race_id' => 2]];
        yield 'date' => [['race_date' => '2025-01-02']];
        yield 'track' => [['track_code' => '22']];
        yield 'number' => [['race_number' => 2]];
        yield 'source' => [['source' => 'JSJ017']];
        yield 'url' => [['source_url' => 'https://invalid.example/']];
    }

    public function test_unverified_frames_and_unsupported_version_do_not_establish_full_unchanged(): void
    {
        $first = $this->snapshot(1);
        $entries = $first->attributes['entries'];
        $entries[0]['frame_number'] = null;
        $out = $this->compare($first, $this->snapshot(2, entries: $entries));
        $this->assertSame('NOT_ESTABLISHED', $out['status']);
        $this->assertSame(['000001'], $out['difference']['frame_comparison_unknown_registrations']);
        $this->assertSame([], $out['difference']['frame_changes']);
        $out = $this->compare($first, $this->snapshot(2, changes: ['snapshot_version' => 'UNSUPPORTED']));
        $this->assertSame('NOT_ESTABLISHED', $out['status']);
    }

    #[DataProvider('cutoffBoundaries')]
    public function test_cutoff_audits_are_strict_and_never_authorize_prediction(?string $saved, string $asOf, ?bool $available, bool $beforeStart): void
    {
        $first = $this->snapshot(1, time: '2025-01-01T09:00:00+09:00', changes: ['fetch_log_saved_at' => $saved]);
        $out = (new Stat46RosterDifferenceCalculator)->calculate($first, $this->snapshot(2), new DateTimeImmutable($asOf));
        $this->assertSame($available, $out['captures'][0]['acquired_and_logged_before_input_as_of']);
        $this->assertSame($beforeStart, $out['captures'][0]['input_as_of_before_same_raw_start']);
        $this->assertSame('NOT_AUTHORIZED', $out['prediction_use']);
        $this->assertFalse($out['historical_as_of_available']);
    }

    public static function cutoffBoundaries(): iterable
    {
        yield 'both before' => ['2025-01-01T09:00:01+09:00', '2025-01-01T09:30:00+09:00', true, true];
        yield 'same instant UTC' => ['2025-01-01T00:00:01Z', '2025-01-01T00:30:00Z', true, true];
        yield 'acquired at cutoff' => ['2025-01-01T09:00:01+09:00', '2025-01-01T09:00:00+09:00', false, true];
        yield 'saved at cutoff' => ['2025-01-01T09:30:00+09:00', '2025-01-01T09:30:00+09:00', false, true];
        yield 'saved after cutoff' => ['2025-01-01T09:30:01+09:00', '2025-01-01T09:30:00+09:00', false, true];
        yield 'save unknown' => [null, '2025-01-01T09:30:00+09:00', null, true];
        yield 'clock inconsistency' => ['2025-01-01T08:59:59+09:00', '2025-01-01T09:30:00+09:00', false, true];
        yield 'cutoff at scheduled start' => ['2025-01-01T09:00:01+09:00', '2025-01-01T10:00:00+09:00', true, false];
    }

    public function test_pure_storage_contract_and_calculator_are_not_hard_coded_to_historical_years(): void
    {
        $first = $this->snapshot(1, time: '2030-01-01T09:00:00+09:00', changes: ['race_date' => '2030-01-01']);
        $second = $this->snapshot(2, time: '2030-01-01T09:01:00+09:00', changes: ['race_date' => '2030-01-01']);
        $this->assertSame('OBSERVED_ROSTER_UNCHANGED', $this->compare($first, $second)['status']);
        $this->assertSame('NOT_AUTHORIZED', $this->compare($first, $second)['prediction_use']);
    }

    #[DataProvider('invalidEntries')]
    public function test_invalid_identity_sets_are_rejected(array $entries): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->snapshot(1, entries: $entries);
    }

    public static function invalidEntries(): iterable
    {
        yield 'no entrants' => [[]];
        yield 'duplicate bike' => [[['bike_number' => 1, 'frame_number' => null, 'external_player_id' => '000001'], ['bike_number' => 1, 'frame_number' => null, 'external_player_id' => '000002']]];
        yield 'duplicate person' => [[['bike_number' => 1, 'frame_number' => null, 'external_player_id' => '000001'], ['bike_number' => 3, 'frame_number' => null, 'external_player_id' => '000001']]];
        yield 'zero bike' => [[['bike_number' => 0, 'frame_number' => null, 'external_player_id' => '000001']]];
        yield 'numeric registration loses zero' => [[['bike_number' => 1, 'frame_number' => null, 'external_player_id' => 1]]];
    }

    private function snapshot(int $id, ?array $entries = null, ?string $time = 'DEFAULT', array $changes = []): RaceRosterSnapshotDto
    {
        $entries ??= [$this->entry(1, '000001'), $this->entry(3, '000002'), $this->entry(5, '000003')];

        return new RaceRosterSnapshotDto(array_replace(['race_id' => 1, 'race_date' => '2025-01-01', 'track_code' => '11', 'race_number' => 1,
            'scraping_fetch_log_id' => $id, 'source' => 'PJ0315', 'source_url' => 'https://fixture.invalid/roster',
            'raw_sha256' => hash('sha256', 'ARTIFICIAL_CAPTURE_'.$id), 'parser_version' => RaceRosterSnapshotDto::VERSION,
            'snapshot_version' => RaceRosterSnapshotDto::VERSION, 'entrant_count' => count($entries), 'entries' => $entries,
            'fetched_at' => $time === 'DEFAULT' ? '2025-01-01T09:'.($id === 1 ? '00' : '01').':00+09:00' : $time,
            'fetch_log_saved_at' => null, 'scheduled_start_at' => '2025-01-01T10:00:00+09:00', 'timing_status' => 'BEFORE_SAME_RAW_START'], $changes));
    }

    private function entry(int $bike, string $registration, ?int $frame = 1): array
    {
        return ['bike_number' => $bike, 'frame_number' => $frame, 'external_player_id' => $registration];
    }

    private function compare(?RaceRosterSnapshotDto $first, ?RaceRosterSnapshotDto $second): array
    {
        return (new Stat46RosterDifferenceCalculator)->calculate($first, $second);
    }
}
