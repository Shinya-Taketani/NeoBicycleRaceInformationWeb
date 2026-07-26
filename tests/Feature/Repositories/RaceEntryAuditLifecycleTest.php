<?php

declare(strict_types=1);

namespace Tests\Feature\Repositories;

use App\Domain\Keirin\Scraping\DTO\RaceDayMetadataPageDto;
use App\Domain\Keirin\Scraping\DTO\RaceDayParameterDto;
use App\Domain\Keirin\Scraping\DTO\RaceDetailEntryDto;
use App\Domain\Keirin\Scraping\DTO\RaceDetailPageDto;
use App\Domain\Keirin\Scraping\DTO\RaceEntryListPageDto;
use App\Domain\Keirin\Scraping\DTO\RaceListEntryDto;
use App\Domain\Keirin\Scraping\DTO\RaceListRaceDto;
use App\Domain\Keirin\Scraping\DTO\RaceParameterDto;
use App\Domain\Keirin\Scraping\Enums\RaceCategory;
use App\Domain\Keirin\Statistics\DTO\RaceEntrySnapshotDto;
use App\Domain\Keirin\Statistics\Services\RaceEntrySnapshotService;
use App\Domain\Keirin\Statistics\Services\StatInputAsOfResolver;
use App\Models\Player;
use App\Models\Race;
use App\Models\RaceDay;
use App\Models\RaceEntry;
use App\Models\RaceEntrySnapshot;
use App\Models\RaceEntrySnapshotOccurrence;
use App\Models\RaceEntrySnapshotSource;
use App\Models\RaceMeeting;
use App\Models\Racetrack;
use App\Models\ScrapingFetchLog;
use App\Models\StatFeatureSnapshot;
use App\Repositories\RaceRepository;
use Database\Seeders\StatFeatureDefinitionSeeder;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RaceEntryAuditLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private RaceRepository $races;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(StatFeatureDefinitionSeeder::class);
        $this->races = $this->app->make(RaceRepository::class);
    }

    public function test_jsj017_resync_changes_only_general_fetch_time_and_reuses_statistical_audit(): void
    {
        [$day, $metadata] = $this->context();
        $this->syncRaceDay($day, $metadata, range(1, 5), '2026-07-26 10:00:00+09:00');
        $race = Race::query()->sole();
        $this->races->updateRaceDetail(
            $race,
            $this->detail($this->scores(5)),
            new DateTimeImmutable('2026-07-26 10:05:00+09:00'),
        );
        $this->buildStat01($race, 0);

        $entry = RaceEntry::query()->where('race_id', $race->id)->where('bike_number', 1)->firstOrFail();
        $snapshot = RaceEntrySnapshot::query()
            ->where('race_entry_id', $entry->id)
            ->whereHas('currentOccurrence')
            ->firstOrFail();
        $occurrence = RaceEntrySnapshotOccurrence::query()
            ->where('race_entry_id', $entry->id)
            ->where('is_current', true)
            ->sole();
        $snapshotIdentity = [
            'id' => (int) $snapshot->id,
            'hash' => $snapshot->snapshot_hash,
        ];
        $featureInputHash = StatFeatureSnapshot::query()
            ->where('race_entry_id', $entry->id)
            ->value('input_hash');
        $scoreFetchedAt = $entry->race_score_fetched_at;

        $this->syncRaceDay($day, $metadata, range(1, 5), '2026-07-26 10:30:00+09:00');

        $entry->refresh();
        $this->assertSame('2026-07-26 10:30:00', $entry->fetched_at->format('Y-m-d H:i:s'));
        $this->assertEquals($scoreFetchedAt, $entry->race_score_fetched_at);
        $this->assertSame('100.00', $entry->race_score);

        $this->buildStat01($race->refresh(), 0);
        $current = RaceEntrySnapshot::query()
            ->where('race_entry_id', $entry->id)
            ->whereHas('currentOccurrence')
            ->firstOrFail();
        $currentOccurrence = RaceEntrySnapshotOccurrence::query()
            ->where('race_entry_id', $entry->id)
            ->where('is_current', true)
            ->sole();
        $this->assertSame($snapshotIdentity, [
            'id' => (int) $current->id,
            'hash' => $current->snapshot_hash,
        ]);
        $this->assertSame((int) $occurrence->id, (int) $currentOccurrence->id);
        $this->assertNull($currentOccurrence->effective_to);
        $this->assertSame(
            $featureInputHash,
            StatFeatureSnapshot::query()->where('race_entry_id', $entry->id)->value('input_hash'),
        );
        $this->assertDatabaseCount('race_entry_snapshots', 5);
        $this->assertDatabaseCount('stat_feature_snapshots', 5);
        $this->assertDatabaseCount('stat_feature_values', 55);
    }

    public function test_equal_pj0315_score_preserves_score_fetch_time_snapshot_and_features(): void
    {
        [$day, $metadata] = $this->context();
        $this->syncRaceDay($day, $metadata, range(1, 5), '2026-07-26 10:00:00+09:00');
        $race = Race::query()->sole();
        $scores = $this->scores(5);
        $this->races->updateRaceDetail(
            $race,
            $this->detail($scores),
            new DateTimeImmutable('2026-07-26 10:05:00+09:00'),
        );
        $this->buildStat01($race, 0);
        $entry = RaceEntry::query()->where('race_id', $race->id)->where('bike_number', 1)->firstOrFail();
        $scoreFetchedAt = $entry->race_score_fetched_at;
        $snapshot = RaceEntrySnapshot::query()->where('race_entry_id', $entry->id)->sole();

        $scores[1] = '100.0';
        $this->races->updateRaceDetail(
            $race,
            $this->detail($scores),
            new DateTimeImmutable('2026-07-26 10:40:00+09:00'),
        );
        $this->buildStat01($race->refresh(), 0);

        $entry->refresh();
        $this->assertSame('2026-07-26 10:40:00', $entry->fetched_at->format('Y-m-d H:i:s'));
        $this->assertEquals($scoreFetchedAt, $entry->race_score_fetched_at);
        $this->assertSame((int) $snapshot->id, (int) RaceEntrySnapshot::query()->where('race_entry_id', $entry->id)->sole()->id);
        $this->assertSame($snapshot->snapshot_hash, RaceEntrySnapshot::query()->where('race_entry_id', $entry->id)->sole()->snapshot_hash);
        $this->assertDatabaseCount('race_entry_snapshots', 5);
        $this->assertDatabaseCount('stat_feature_snapshots', 5);
        $this->assertDatabaseCount('stat_feature_values', 55);
    }

    public function test_changed_pj0315_score_advances_score_time_and_keeps_old_snapshot_history(): void
    {
        [$day, $metadata] = $this->context();
        $this->syncRaceDay($day, $metadata, range(1, 5), '2026-07-26 10:00:00+09:00');
        $race = Race::query()->sole();
        $scores = $this->scores(5);
        $this->races->updateRaceDetail(
            $race,
            $this->detail($scores),
            new DateTimeImmutable('2026-07-26 10:05:00+09:00'),
        );
        $this->buildStat01($race, 0);
        $entry = RaceEntry::query()->where('race_id', $race->id)->where('bike_number', 1)->firstOrFail();
        $oldSnapshot = RaceEntrySnapshot::query()->where('race_entry_id', $entry->id)->sole();
        $oldOccurrence = RaceEntrySnapshotOccurrence::query()
            ->where('race_entry_id', $entry->id)
            ->sole();

        $scores[1] = '101.25';
        $this->races->updateRaceDetail(
            $race,
            $this->detail($scores),
            new DateTimeImmutable('2026-07-26 10:45:00+09:00'),
        );
        $this->buildStat01($race->refresh(), 0);

        $entry->refresh();
        $this->assertSame('101.25', $entry->race_score);
        $this->assertSame('2026-07-26 10:45:00', $entry->race_score_fetched_at->format('Y-m-d H:i:s'));
        $oldOccurrence->refresh();
        $this->assertFalse($oldOccurrence->is_current);
        $this->assertSame('2026-07-26 10:45:00', $oldOccurrence->effective_to->format('Y-m-d H:i:s'));
        $newSnapshot = RaceEntrySnapshot::query()
            ->where('race_entry_id', $entry->id)
            ->whereHas('currentOccurrence')
            ->sole();
        $this->assertNotSame((int) $oldSnapshot->id, (int) $newSnapshot->id);
        $this->assertSame('101.25', $newSnapshot->race_score_raw_text);
        $this->assertSame(2, RaceEntrySnapshot::query()->where('race_entry_id', $entry->id)->count());
        $this->assertDatabaseCount('race_entry_snapshots', 6);
        $this->assertDatabaseCount('stat_feature_snapshots', 10);
        $this->assertDatabaseCount('stat_feature_values', 110);
    }

    public function test_missing_jsj017_entry_is_soft_deleted_without_losing_statistical_audit(): void
    {
        [$day, $metadata] = $this->context();
        $this->syncRaceDay($day, $metadata, range(1, 6), '2026-07-26 10:00:00+09:00');
        $race = Race::query()->sole();
        $this->races->updateRaceDetail(
            $race,
            $this->detail($this->scores(6)),
            new DateTimeImmutable('2026-07-26 10:05:00+09:00'),
        );
        $this->buildStat01($race, 0);
        $removed = RaceEntry::query()->where('race_id', $race->id)->where('bike_number', 6)->firstOrFail();
        $entrySnapshot = RaceEntrySnapshot::query()->where('race_entry_id', $removed->id)->sole();
        $featureSnapshot = StatFeatureSnapshot::query()->where('race_entry_id', $removed->id)->sole();

        $this->syncRaceDay($day, $metadata, range(1, 5), '2026-07-26 10:30:00+09:00');

        $this->assertSame(5, RaceEntry::query()->where('race_id', $race->id)->count());
        $this->assertSame(6, RaceEntry::withTrashed()->where('race_id', $race->id)->count());
        $trashed = RaceEntry::withTrashed()->findOrFail($removed->id);
        $this->assertTrue($trashed->trashed());
        $this->assertDatabaseCount('race_entry_snapshots', 6);
        $this->assertDatabaseCount('stat_feature_snapshots', 6);
        $this->assertDatabaseCount('stat_feature_values', 66);
        $this->assertSame((int) $removed->id, (int) $entrySnapshot->refresh()->raceEntry->id);
        $this->assertSame((int) $removed->id, (int) $featureSnapshot->refresh()->raceEntry->id);
    }

    public function test_reappearing_jsj017_bike_restores_same_entry_and_audit_relationships(): void
    {
        [$day, $metadata] = $this->context();
        $this->syncRaceDay($day, $metadata, range(1, 6), '2026-07-26 10:00:00+09:00');
        $race = Race::query()->sole();
        $this->races->updateRaceDetail(
            $race,
            $this->detail($this->scores(6)),
            new DateTimeImmutable('2026-07-26 10:05:00+09:00'),
        );
        $this->buildStat01($race, 0);
        $entry = RaceEntry::query()->where('race_id', $race->id)->where('bike_number', 6)->firstOrFail();
        $scoreFetchedAt = $entry->race_score_fetched_at;
        $frameNumber = $entry->frame_number;
        $grade = $entry->grade;
        $entrySnapshotId = RaceEntrySnapshot::query()->where('race_entry_id', $entry->id)->value('id');
        $featureSnapshotId = StatFeatureSnapshot::query()->where('race_entry_id', $entry->id)->value('id');
        $this->syncRaceDay($day, $metadata, range(1, 5), '2026-07-26 10:30:00+09:00');

        $this->syncRaceDay($day, $metadata, range(1, 6), '2026-07-26 10:45:00+09:00');

        $restored = RaceEntry::query()->where('race_id', $race->id)->where('bike_number', 6)->sole();
        $this->assertSame((int) $entry->id, (int) $restored->id);
        $this->assertNull($restored->deleted_at);
        $this->assertEquals($scoreFetchedAt, $restored->race_score_fetched_at);
        $this->assertSame('95.00', $restored->race_score);
        $this->assertSame($frameNumber, $restored->frame_number);
        $this->assertSame($grade, $restored->grade);
        $this->buildStat01($race->refresh(), 0);
        $this->assertSame((int) $entrySnapshotId, (int) RaceEntrySnapshot::query()->where('race_entry_id', $restored->id)->value('id'));
        $this->assertSame((int) $featureSnapshotId, (int) StatFeatureSnapshot::query()->where('race_entry_id', $restored->id)->value('id'));
        $this->assertSame(6, RaceEntrySnapshot::query()->where('race_id', $race->id)->count());
        $this->assertSame(6, RaceEntry::withTrashed()->where('race_id', $race->id)->count());
    }

    public function test_active_bike_changed_to_another_player_clears_detail_fields_and_keeps_old_audit(): void
    {
        [$day, $metadata] = $this->context();
        $this->syncRaceDay($day, $metadata, range(1, 5), '2026-07-26 10:00:00+09:00');
        $race = Race::query()->sole();
        $this->races->updateRaceDetail(
            $race,
            $this->detail($this->scores(5)),
            new DateTimeImmutable('2026-07-26 10:05:00+09:00'),
        );
        $this->buildStat01($race, 0);

        $entry = RaceEntry::query()->where('race_id', $race->id)->where('bike_number', 5)->sole();
        $entryId = (int) $entry->id;
        $oldSnapshot = RaceEntrySnapshot::query()->where('race_entry_id', $entryId)->sole();
        $oldOccurrence = RaceEntrySnapshotOccurrence::query()
            ->where('race_entry_id', $entryId)
            ->sole();
        $oldFeature = StatFeatureSnapshot::query()->where('race_entry_id', $entryId)->sole();

        $this->syncRaceDay(
            $day,
            $metadata,
            range(1, 5),
            '2026-07-26 10:30:00+09:00',
            [5 => '900005'],
        );

        $changed = RaceEntry::query()->findOrFail($entryId);
        $this->assertSame($entryId, (int) $changed->id);
        $this->assertSame('900005', $changed->external_player_id);
        $this->assertNull($changed->player_id);
        $this->assertNull($changed->frame_number);
        $this->assertNull($changed->grade);
        $this->assertNull($changed->race_score);
        $this->assertNull($changed->race_score_fetched_at);
        $this->assertNull($changed->deleted_at);

        $this->buildStat01($race->refresh(), 1);

        $oldOccurrence->refresh();
        $this->assertFalse($oldOccurrence->is_current);
        $this->assertSame('2026-07-26 10:30:00', $oldOccurrence->effective_to->format('Y-m-d H:i:s'));
        $this->assertSame('000005', $oldSnapshot->external_player_id);
        $newSnapshot = RaceEntrySnapshot::query()
            ->where('race_entry_id', $entryId)
            ->whereHas('currentOccurrence')
            ->sole();
        $this->assertSame('900005', $newSnapshot->external_player_id);
        $this->assertSame('MISSING', $newSnapshot->race_score_validation_status);
        $this->assertNull($newSnapshot->first_observed_at);
        $this->assertNull($newSnapshot->last_observed_at);
        $newFeature = StatFeatureSnapshot::query()
            ->where('race_entry_id', $entryId)
            ->latest('id')
            ->firstOrFail();
        $this->assertSame('LEAKAGE_RISK', $newFeature->status);
        $this->assertFalse((bool) DB::table('stat_feature_values')
            ->where('stat_feature_snapshot_id', $newFeature->id)
            ->where('feature_code', 'RACE_SCORE_AVAILABLE')
            ->value('feature_value_boolean'));
        $this->assertFalse(DB::table('stat_feature_values')
            ->where('stat_feature_snapshot_id', $newFeature->id)
            ->where('feature_code', 'RACE_SCORE_RAW')
            ->exists());
        $this->assertDatabaseHas('race_entry_snapshots', ['id' => $oldSnapshot->id]);
        $this->assertDatabaseHas('stat_feature_snapshots', ['id' => $oldFeature->id]);
    }

    public function test_existing_entry_without_external_identity_is_cleared_when_identity_becomes_known(): void
    {
        [$day, $metadata] = $this->context();
        $this->syncRaceDay($day, $metadata, range(1, 5), '2026-07-26 10:00:00+09:00');
        $race = Race::query()->sole();
        $this->races->updateRaceDetail(
            $race,
            $this->detail($this->scores(5)),
            new DateTimeImmutable('2026-07-26 10:05:00+09:00'),
        );
        $entry = RaceEntry::query()->where('race_id', $race->id)->where('bike_number', 5)->sole();
        $entryId = (int) $entry->id;
        $entry->forceFill(['external_player_id' => null])->save();

        $this->syncRaceDay($day, $metadata, range(1, 5), '2026-07-26 10:30:00+09:00');

        $resolved = RaceEntry::query()->findOrFail($entryId);
        $this->assertSame('000005', $resolved->external_player_id);
        $this->assertNull($resolved->frame_number);
        $this->assertNull($resolved->grade);
        $this->assertNull($resolved->race_score);
        $this->assertNull($resolved->race_score_fetched_at);
    }

    public function test_soft_deleted_bike_changed_to_another_player_restores_slot_without_detail_inheritance(): void
    {
        [$day, $metadata] = $this->context();
        $this->syncRaceDay($day, $metadata, range(1, 5), '2026-07-26 10:00:00+09:00');
        $race = Race::query()->sole();
        $this->races->updateRaceDetail(
            $race,
            $this->detail($this->scores(5)),
            new DateTimeImmutable('2026-07-26 10:05:00+09:00'),
        );
        $this->buildStat01($race, 0);
        $entry = RaceEntry::query()->where('race_id', $race->id)->where('bike_number', 5)->sole();
        $entryId = (int) $entry->id;
        $snapshotId = (int) RaceEntrySnapshot::query()->where('race_entry_id', $entryId)->value('id');
        $featureId = (int) StatFeatureSnapshot::query()->where('race_entry_id', $entryId)->value('id');

        $this->syncRaceDay($day, $metadata, range(1, 4), '2026-07-26 10:20:00+09:00');
        $this->assertTrue(RaceEntry::withTrashed()->findOrFail($entryId)->trashed());

        $this->syncRaceDay(
            $day,
            $metadata,
            range(1, 5),
            '2026-07-26 10:30:00+09:00',
            [5 => '900005'],
        );

        $restored = RaceEntry::query()->findOrFail($entryId);
        $this->assertSame($entryId, (int) $restored->id);
        $this->assertSame('900005', $restored->external_player_id);
        $this->assertNull($restored->deleted_at);
        $this->assertNull($restored->frame_number);
        $this->assertNull($restored->grade);
        $this->assertNull($restored->race_score);
        $this->assertNull($restored->race_score_fetched_at);
        $this->assertDatabaseHas('race_entry_snapshots', ['id' => $snapshotId]);
        $this->assertDatabaseHas('stat_feature_snapshots', ['id' => $featureId]);
    }

    public function test_unresolved_players_with_equal_scores_create_distinct_identity_snapshots_and_features(): void
    {
        [$day, $metadata] = $this->context();
        $this->syncRaceDay($day, $metadata, range(1, 5), '2026-07-26 10:00:00+09:00');
        $race = Race::query()->sole();
        $scores = $this->scores(5);
        $this->races->updateRaceDetail(
            $race,
            $this->detail($scores),
            new DateTimeImmutable('2026-07-26 10:05:00+09:00'),
        );
        $this->buildStat01($race, 0);
        $entry = RaceEntry::query()->where('race_id', $race->id)->where('bike_number', 5)->sole();
        $entryId = (int) $entry->id;
        $oldSnapshot = RaceEntrySnapshot::query()->where('race_entry_id', $entryId)->sole();
        $oldOccurrence = RaceEntrySnapshotOccurrence::query()
            ->where('race_entry_id', $entryId)
            ->sole();
        $oldFeature = StatFeatureSnapshot::query()->where('race_entry_id', $entryId)->sole();

        $this->syncRaceDay(
            $day,
            $metadata,
            range(1, 5),
            '2026-07-26 10:30:00+09:00',
            [5 => '900005'],
        );
        $this->races->updateRaceDetail(
            $race,
            $this->detail($scores, [5 => '900005']),
            new DateTimeImmutable('2026-07-26 10:35:00+09:00'),
        );
        $this->buildStat01($race->refresh(), 0);

        $this->assertNull($oldSnapshot->player_id);
        $this->assertSame('000005', $oldSnapshot->external_player_id);
        $this->assertFalse($oldOccurrence->refresh()->is_current);
        $newSnapshot = RaceEntrySnapshot::query()
            ->where('race_entry_id', $entryId)
            ->whereHas('currentOccurrence')
            ->sole();
        $this->assertNull($newSnapshot->player_id);
        $this->assertSame('900005', $newSnapshot->external_player_id);
        $this->assertSame($oldSnapshot->frame_number, $newSnapshot->frame_number);
        $this->assertSame($oldSnapshot->grade, $newSnapshot->grade);
        $this->assertSame($oldSnapshot->race_score, $newSnapshot->race_score);
        $this->assertNotSame($oldSnapshot->snapshot_hash, $newSnapshot->snapshot_hash);
        $this->assertSame(2, RaceEntrySnapshot::query()->where('race_entry_id', $entryId)->count());

        $newFeature = StatFeatureSnapshot::query()
            ->where('race_entry_id', $entryId)
            ->latest('id')
            ->firstOrFail();
        $this->assertNotSame((int) $oldFeature->id, (int) $newFeature->id);
        $this->assertNotSame($oldFeature->input_hash, $newFeature->input_hash);
        $this->assertDatabaseHas('stat_feature_snapshots', ['id' => $oldFeature->id]);
    }

    public function test_changed_player_does_not_inherit_current_snapshot_fetch_log_or_raw_source(): void
    {
        [$day, $metadata] = $this->context();
        $this->syncRaceDay($day, $metadata, range(1, 5), '2026-07-26 10:00:00+09:00');
        $race = Race::query()->sole();
        $this->races->updateRaceDetail(
            $race,
            $this->detail($this->scores(5)),
            new DateTimeImmutable('2026-07-26 10:05:00+09:00'),
        );
        $this->buildStat01($race, 0);
        $entry = RaceEntry::query()->where('race_id', $race->id)->where('bike_number', 5)->sole();
        $oldSnapshot = RaceEntrySnapshot::query()->where('race_entry_id', $entry->id)->sole();
        $fetchLog = ScrapingFetchLog::query()->create([
            'source' => 'keirin_jp',
            'request_method' => 'POST',
            'request_url' => 'https://example.invalid/player-a',
            'request_key' => 'player-a-detail',
            'http_status' => 200,
            'fetched_at' => '2026-07-26 10:05:00+09:00',
            'utf8_conversion_succeeded' => true,
            'response_size' => 123,
            'sha256' => str_repeat('a', 64),
            'raw_file_path' => 'scraping/raw/player-a.html',
            'retry_count' => 0,
            'parser_version' => 'player-a-parser',
        ]);
        $oldSource = RaceEntrySnapshotSource::query()
            ->where('race_entry_snapshot_id', $oldSnapshot->id)
            ->sole();
        $oldSource->forceFill(['scraping_fetch_log_id' => $fetchLog->id])->save();

        $this->syncRaceDay(
            $day,
            $metadata,
            range(1, 5),
            '2026-07-26 10:30:00+09:00',
            [5 => '900005'],
        );
        $this->buildStat01($race->refresh(), 1);

        $newSnapshot = RaceEntrySnapshot::query()
            ->where('race_entry_id', $entry->id)
            ->whereHas('currentOccurrence')
            ->sole();
        $newSource = RaceEntrySnapshotSource::query()
            ->where('race_entry_snapshot_id', $newSnapshot->id)
            ->sole();
        $this->assertNull($newSource->scraping_fetch_log_id);
        $this->assertSame('900005', $newSource->context_evidence['external_player_id']);
        $newFeature = StatFeatureSnapshot::query()
            ->where('race_entry_id', $entry->id)
            ->latest('id')
            ->firstOrFail();
        $primarySource = DB::table('stat_feature_sources')
            ->where('stat_feature_snapshot_id', $newFeature->id)
            ->where('source_role', 'PRIMARY_INPUT')
            ->sole();
        $this->assertNull($primarySource->scraping_fetch_log_id);
        $this->assertNull($primarySource->raw_file_path);
        $this->assertNull($primarySource->raw_sha256);
        $this->assertNull($primarySource->parser_version);
        $this->assertDatabaseHas('race_entry_snapshot_sources', [
            'id' => $oldSource->id,
            'scraping_fetch_log_id' => $fetchLog->id,
        ]);
    }

    public function test_fetch_log_set_null_changes_source_fingerprint_and_creates_current_feature_audit(): void
    {
        Player::query()->create([
            'source' => 'keirin_jp',
            'external_player_id' => '000005',
            'name' => '監査選手5',
        ]);
        [$day, $metadata] = $this->context();
        $this->syncRaceDay($day, $metadata, range(1, 5), '2026-07-26 10:00:00+09:00');
        $race = Race::query()->sole();
        $this->races->updateRaceDetail(
            $race,
            $this->detail($this->scores(5)),
            new DateTimeImmutable('2026-07-26 10:05:00+09:00'),
        );
        $entry = RaceEntry::query()->where('race_id', $race->id)->where('bike_number', 5)->sole();
        $this->snapshotForEntry($race, (int) $entry->id);
        $source = RaceEntrySnapshotSource::query()
            ->whereHas('snapshot', fn ($query) => $query->where('race_entry_id', $entry->id))
            ->sole();
        $fetchLog = $this->fetchLog('linked-source');
        $source->forceFill(['scraping_fetch_log_id' => $fetchLog->id])->save();

        $linkedInput = $this->snapshotForEntry($race->refresh(), (int) $entry->id);
        $this->buildStat01($race->refresh(), 0);
        $linkedFeature = StatFeatureSnapshot::query()
            ->where('race_entry_id', $entry->id)
            ->sole();
        $this->assertSame('VALID', $linkedFeature->status);
        $this->assertSame('VALID', $linkedFeature->data_quality_status);

        $fetchLog->delete();
        $missingInput = $this->snapshotForEntry($race->refresh(), (int) $entry->id);
        $this->buildStat01($race->refresh(), 0);

        $this->assertNotSame($linkedInput->sourceFingerprint, $missingInput->sourceFingerprint);
        $missingFeature = StatFeatureSnapshot::query()
            ->where('race_entry_id', $entry->id)
            ->latest('id')
            ->firstOrFail();
        $this->assertNotSame($linkedFeature->input_hash, $missingFeature->input_hash);
        $this->assertSame('DEGRADED', $missingFeature->status);
        $this->assertSame('DEGRADED', $missingFeature->data_quality_status);
        $this->assertSame(2, StatFeatureSnapshot::query()->where('race_entry_id', $entry->id)->count());
        $this->assertDatabaseHas('stat_feature_snapshots', ['id' => $linkedFeature->id]);
        $this->assertDatabaseHas('stat_feature_sources', [
            'stat_feature_snapshot_id' => $missingFeature->id,
            'source_role' => 'PRIMARY_INPUT',
            'scraping_fetch_log_id' => null,
            'source_timing_status' => 'SOURCE_LINK_MISSING',
        ]);
    }

    public function test_ineligible_source_changes_fingerprint_and_preserves_old_feature_audit(): void
    {
        [$day, $metadata] = $this->context();
        $this->syncRaceDay($day, $metadata, range(1, 5), '2026-07-26 10:00:00+09:00');
        $race = Race::query()->sole();
        $this->races->updateRaceDetail(
            $race,
            $this->detail($this->scores(5)),
            new DateTimeImmutable('2026-07-26 10:05:00+09:00'),
        );
        $entry = RaceEntry::query()->where('race_id', $race->id)->where('bike_number', 5)->sole();
        $eligibleInput = $this->snapshotForEntry($race, (int) $entry->id);
        $this->buildStat01($race->refresh(), 0);
        $oldFeature = StatFeatureSnapshot::query()
            ->where('race_entry_id', $entry->id)
            ->sole();
        RaceEntrySnapshotSource::query()
            ->where('race_entry_snapshot_id', $eligibleInput->id)
            ->sole()
            ->forceFill(['eligible_fields' => []])
            ->save();

        $ineligibleInput = $this->snapshotForEntry($race->refresh(), (int) $entry->id);
        $this->buildStat01($race->refresh(), 1);

        $this->assertNotSame($eligibleInput->sourceFingerprint, $ineligibleInput->sourceFingerprint);
        $this->assertNotSame($eligibleInput->sourceStateId, $ineligibleInput->sourceStateId);
        $this->assertSame($eligibleInput->id, $ineligibleInput->id);
        $this->assertSame($eligibleInput->occurrenceId, $ineligibleInput->occurrenceId);
        $this->assertSame(
            1,
            RaceEntrySnapshot::query()->where('race_entry_id', $entry->id)->count(),
        );
        $this->assertSame(
            2,
            RaceEntrySnapshotSource::query()->where('race_entry_id', $entry->id)->count(),
        );
        $this->assertSame(
            1,
            RaceEntrySnapshotOccurrence::query()
                ->where('race_entry_id', $entry->id)
                ->count(),
        );
        $latestRun = (int) DB::table('statistic_calculation_runs')->latest('id')->value('id');
        $this->assertSame(
            $eligibleInput->occurrenceId,
            $this->primaryOccurrenceId($latestRun, (int) $entry->id),
        );
        $this->assertSame(
            $ineligibleInput->sourceStateId,
            $this->primarySourceStateId($latestRun, (int) $entry->id),
        );
        $newFeature = StatFeatureSnapshot::query()
            ->where('race_entry_id', $entry->id)
            ->latest('id')
            ->firstOrFail();
        $this->assertNotSame($oldFeature->input_hash, $newFeature->input_hash);
        $this->assertSame('LEAKAGE_RISK', $newFeature->status);
        $this->assertSame('LEAKAGE_RISK', $newFeature->data_quality_status);
        $this->assertDatabaseHas('stat_feature_snapshots', ['id' => $oldFeature->id]);
    }

    public function test_source_field_array_order_does_not_change_fingerprint_or_feature_input_hash(): void
    {
        [$day, $metadata] = $this->context();
        $this->syncRaceDay($day, $metadata, range(1, 5), '2026-07-26 10:00:00+09:00');
        $race = Race::query()->sole();
        $this->races->updateRaceDetail(
            $race,
            $this->detail($this->scores(5)),
            new DateTimeImmutable('2026-07-26 10:05:00+09:00'),
        );
        $entry = RaceEntry::query()->where('race_id', $race->id)->where('bike_number', 5)->sole();
        $this->snapshotForEntry($race, (int) $entry->id);
        $source = RaceEntrySnapshotSource::query()
            ->whereHas('snapshot', fn ($query) => $query->where('race_entry_id', $entry->id))
            ->sole();
        $source->forceFill([
            'contributed_fields' => ['grade', 'race_score', 'frame_number'],
            'eligible_fields' => ['grade', 'race_score'],
        ])->save();
        $firstInput = $this->snapshotForEntry($race->refresh(), (int) $entry->id);
        $this->buildStat01($race->refresh(), 0);
        $featureInputHash = StatFeatureSnapshot::query()
            ->where('race_entry_id', $entry->id)
            ->value('input_hash');

        $source->forceFill([
            'contributed_fields' => ['race_score', 'frame_number', 'grade', 'race_score'],
            'eligible_fields' => ['race_score', 'grade', 'race_score'],
        ])->save();
        $reorderedInput = $this->snapshotForEntry($race->refresh(), (int) $entry->id);
        $this->buildStat01($race->refresh(), 0);

        $this->assertSame($firstInput->sourceFingerprint, $reorderedInput->sourceFingerprint);
        $this->assertSame(
            $featureInputHash,
            StatFeatureSnapshot::query()->where('race_entry_id', $entry->id)->sole()->input_hash,
        );
        $this->assertDatabaseCount('stat_feature_snapshots', 5);
    }

    public function test_grade_only_detail_change_uses_state_fetch_time_for_effective_end(): void
    {
        [$day, $metadata] = $this->context();
        $this->syncRaceDay($day, $metadata, range(1, 5), '2026-07-26 10:00:00+09:00');
        $race = Race::query()->sole();
        $scores = $this->scores(5);
        $this->races->updateRaceDetail(
            $race,
            $this->detail($scores),
            new DateTimeImmutable('2026-07-26 10:05:00+09:00'),
        );
        $this->buildStat01($race, 0);
        $entry = RaceEntry::query()->where('race_id', $race->id)->where('bike_number', 1)->sole();
        $oldSnapshot = RaceEntrySnapshot::query()->where('race_entry_id', $entry->id)->sole();
        $oldOccurrence = RaceEntrySnapshotOccurrence::query()
            ->where('race_entry_id', $entry->id)
            ->sole();

        $this->races->updateRaceDetail(
            $race,
            $this->detail($scores, grades: [1 => 'S2']),
            new DateTimeImmutable('2026-07-26 10:40:00+09:00'),
        );
        $this->buildStat01($race->refresh(), 0);

        $this->assertSame(
            '2026-07-26 10:40:00',
            $oldOccurrence->refresh()->effective_to->format('Y-m-d H:i:s'),
        );
        $newSnapshot = RaceEntrySnapshot::query()
            ->where('race_entry_id', $entry->id)
            ->whereHas('currentOccurrence')
            ->sole();
        $this->assertSame('S2', $newSnapshot->grade);
        $this->assertSame('2026-07-26 10:05:00', $newSnapshot->first_observed_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-07-26 10:05:00', $newSnapshot->last_observed_at->format('Y-m-d H:i:s'));
        $this->assertSame(
            '2026-07-26 10:05:00',
            RaceEntry::query()->findOrFail($entry->id)->race_score_fetched_at->format('Y-m-d H:i:s'),
        );
    }

    public function test_reappearing_content_creates_a_new_occurrence_and_each_run_tracks_its_actual_period(): void
    {
        [$day, $metadata] = $this->context();
        $this->syncRaceDay($day, $metadata, range(1, 5), '2026-07-26 09:55:00+09:00');
        $race = Race::query()->sole();
        $scores = $this->scores(5);
        $this->races->updateRaceDetail(
            $race,
            $this->detail($scores),
            new DateTimeImmutable('2026-07-26 10:00:00+09:00'),
        );
        $this->buildStat01($race, 0);
        $runA1 = (int) DB::table('statistic_calculation_runs')->latest('id')->value('id');
        $entry = RaceEntry::query()->where('race_id', $race->id)->where('bike_number', 1)->sole();
        $snapshotA = RaceEntrySnapshot::query()->where('race_entry_id', $entry->id)->sole();
        $occurrenceA1 = RaceEntrySnapshotOccurrence::query()
            ->where('race_entry_id', $entry->id)
            ->sole();

        $this->races->updateRaceDetail(
            $race,
            $this->detail($scores, grades: [1 => 'S2']),
            new DateTimeImmutable('2026-07-26 10:30:00+09:00'),
        );
        $this->buildStat01($race->refresh(), 0);
        $runB = (int) DB::table('statistic_calculation_runs')->latest('id')->value('id');
        $snapshotB = RaceEntrySnapshot::query()
            ->where('race_entry_id', $entry->id)
            ->where('snapshot_hash', '!=', $snapshotA->snapshot_hash)
            ->sole();
        $occurrenceB = RaceEntrySnapshotOccurrence::query()
            ->where('race_entry_id', $entry->id)
            ->where('race_entry_snapshot_id', $snapshotB->id)
            ->sole();
        $sourceA1 = $this->primarySourceStateId($runA1, (int) $entry->id);
        $sourceB = $this->primarySourceStateId($runB, (int) $entry->id);
        $occurrenceB->sourceState->forceFill([
            'source_page_type' => 'PLAYER_PROFILE',
            'historical_backfill_scope' => 'NOT_ELIGIBLE',
        ])->save();

        $this->races->updateRaceDetail(
            $race,
            $this->detail($scores),
            new DateTimeImmutable('2026-07-26 11:00:00+09:00'),
        );
        $this->buildStat01($race->refresh(), 0);
        $runA2 = (int) DB::table('statistic_calculation_runs')->latest('id')->value('id');
        $occurrenceA2 = RaceEntrySnapshotOccurrence::query()
            ->where('race_entry_id', $entry->id)
            ->where('race_entry_snapshot_id', $snapshotA->id)
            ->where('is_current', true)
            ->sole();
        $sourceA2 = $this->primarySourceStateId($runA2, (int) $entry->id);

        $this->assertSame('2026-07-26 10:00:00', $occurrenceA1->effective_from->format('Y-m-d H:i:s'));
        $this->assertSame(
            '2026-07-26 10:30:00',
            $occurrenceA1->refresh()->effective_to->format('Y-m-d H:i:s'),
        );
        $this->assertFalse($occurrenceA1->is_current);
        $this->assertSame('2026-07-26 10:30:00', $occurrenceB->effective_from->format('Y-m-d H:i:s'));
        $this->assertSame(
            '2026-07-26 11:00:00',
            $occurrenceB->refresh()->effective_to->format('Y-m-d H:i:s'),
        );
        $this->assertFalse($occurrenceB->is_current);
        $this->assertSame('2026-07-26 11:00:00', $occurrenceA2->effective_from->format('Y-m-d H:i:s'));
        $this->assertNull($occurrenceA2->effective_to);
        $this->assertTrue($occurrenceA2->is_current);
        $this->assertNotSame((int) $occurrenceA1->id, (int) $occurrenceA2->id);
        $this->assertSame((int) $snapshotA->id, (int) $occurrenceA2->race_entry_snapshot_id);
        $this->assertSame(2, RaceEntrySnapshot::query()->where('race_entry_id', $entry->id)->count());
        $this->assertSame(
            3,
            RaceEntrySnapshotOccurrence::query()->where('race_entry_id', $entry->id)->count(),
        );
        $this->assertSame(1, RaceEntrySnapshotOccurrence::query()
            ->where('race_entry_id', $entry->id)
            ->where('is_current', true)
            ->count());
        $this->assertSame((int) $occurrenceA1->id, $this->primaryOccurrenceId($runA1, (int) $entry->id));
        $this->assertSame((int) $occurrenceB->id, $this->primaryOccurrenceId($runB, (int) $entry->id));
        $this->assertSame((int) $occurrenceA2->id, $this->primaryOccurrenceId($runA2, (int) $entry->id));
        $this->assertNotSame($sourceA1, $sourceB);
        $this->assertNotSame($sourceA1, $sourceA2);
        $this->assertNotSame($sourceB, $sourceA2);
        $this->assertSame($sourceA2, (int) $occurrenceA2->race_entry_snapshot_source_id);
        $this->assertSame(
            3,
            RaceEntrySnapshotSource::query()->where('race_entry_id', $entry->id)->count(),
        );
        $this->assertNotSame(
            $this->primaryOccurrenceId($runA1, (int) $entry->id),
            $this->primaryOccurrenceId($runA2, (int) $entry->id),
        );
        $runA1Feature = $this->featureSnapshotIdForRun($runA1, (int) $entry->id);
        $runA2Feature = $this->featureSnapshotIdForRun($runA2, (int) $entry->id);
        $this->assertNotSame($runA1Feature, $runA2Feature);
        $featureValueCount = DB::table('stat_feature_values')->count();
        $occurrenceCount = RaceEntrySnapshotOccurrence::query()->count();
        $effectiveFrom = $occurrenceA2->effective_from;

        $this->buildStat01($race->refresh(), 0);
        $rerun = (int) DB::table('statistic_calculation_runs')->latest('id')->value('id');

        $occurrenceA2->refresh();
        $this->assertSame($occurrenceCount, RaceEntrySnapshotOccurrence::query()->count());
        $this->assertEquals($effectiveFrom, $occurrenceA2->effective_from);
        $this->assertNull($occurrenceA2->effective_to);
        $this->assertSame($featureValueCount, DB::table('stat_feature_values')->count());
        $this->assertSame((int) $occurrenceA2->id, $this->primaryOccurrenceId($rerun, (int) $entry->id));
        $this->assertSame($sourceA2, $this->primarySourceStateId($rerun, (int) $entry->id));
        $this->assertSame($runA2Feature, $this->featureSnapshotIdForRun($rerun, (int) $entry->id));
    }

    public function test_state_observation_older_than_snapshot_history_is_rejected_without_rewriting_audit(): void
    {
        [$day, $metadata] = $this->context();
        $this->syncRaceDay($day, $metadata, range(1, 5), '2026-07-26 10:00:00+09:00');
        $race = Race::query()->sole();
        $scores = $this->scores(5);
        $this->races->updateRaceDetail(
            $race,
            $this->detail($scores),
            new DateTimeImmutable('2026-07-26 10:05:00+09:00'),
        );
        $this->buildStat01($race, 0);
        $entry = RaceEntry::query()->where('race_id', $race->id)->where('bike_number', 1)->sole();
        $snapshot = RaceEntrySnapshot::query()->where('race_entry_id', $entry->id)->sole();
        $occurrence = RaceEntrySnapshotOccurrence::query()
            ->where('race_entry_id', $entry->id)
            ->sole();

        $this->races->updateRaceDetail(
            $race,
            $this->detail($scores, grades: [1 => 'S2']),
            new DateTimeImmutable('2026-07-26 10:00:00+09:00'),
        );
        $this->buildStat01($race->refresh(), 1);

        $occurrence->refresh();
        $this->assertTrue($occurrence->is_current);
        $this->assertNull($occurrence->effective_to);
        $this->assertSame(1, RaceEntrySnapshot::query()->where('race_entry_id', $entry->id)->count());
        $this->assertSame(
            1,
            RaceEntrySnapshotOccurrence::query()->where('race_entry_id', $entry->id)->count(),
        );
        $this->assertStringContainsString(
            'preceded audited snapshot history',
            (string) DB::table('statistic_calculation_runs')->latest('id')->value('error_summary'),
        );
    }

    public function test_legacy_score_without_dedicated_time_never_uses_general_fetch_time_as_live_input(): void
    {
        [$day, $metadata] = $this->context();
        $this->syncRaceDay($day, $metadata, range(1, 5), '2026-07-26 10:00:00+09:00');
        $race = Race::query()->sole();
        RaceEntry::query()->where('race_id', $race->id)->orderBy('bike_number')->get()
            ->each(function (RaceEntry $entry): void {
                $entry->forceFill(['race_score' => number_format(101 - $entry->bike_number, 2, '.', '')])->save();
            });

        $this->buildStat01($race, 1);

        $snapshot = RaceEntrySnapshot::query()->where('race_id', $race->id)->oldest('id')->firstOrFail();
        $this->assertNull($snapshot->first_observed_at);
        $this->assertNull($snapshot->last_observed_at);
        $this->assertSame(
            ['UNKNOWN_SOURCE_TIMING'],
            StatFeatureSnapshot::query()
                ->where('race_id', $race->id)
                ->distinct()
                ->pluck('input_snapshot_type')
                ->all(),
        );
        $this->assertSame(
            ['LEAKAGE_RISK'],
            StatFeatureSnapshot::query()->where('race_id', $race->id)->distinct()->pluck('status')->all(),
        );
        $this->assertSame(
            ['LEAKAGE_RISK'],
            StatFeatureSnapshot::query()->where('race_id', $race->id)->distinct()->pluck('data_quality_status')->all(),
        );
        $this->assertSame(
            [null],
            StatFeatureSnapshot::query()->where('race_id', $race->id)->distinct()->pluck('source_max_fetched_at')->all(),
        );
        $this->assertSame(
            ['UNKNOWN_SOURCE_TIMING'],
            DB::table('stat_feature_sources')->distinct()->pluck('source_timing_status')->all(),
        );
    }

    /**
     * @return array{RaceDay,RaceDayMetadataPageDto}
     */
    private function context(): array
    {
        $track = Racetrack::query()->create([
            'source' => 'keirin_jp',
            'external_track_id' => '56',
            'name' => '監査試験場',
        ]);
        $meeting = RaceMeeting::query()->create([
            'source' => 'keirin_jp',
            'external_meeting_id' => '56:20260726:audit',
            'racetrack_id' => $track->id,
            'meeting_name' => '監査試験開催',
            'starts_on' => '2026-07-26',
            'ends_on' => '2026-07-26',
            'duration_days' => 1,
            'encrypted_parameter' => 'enc-meeting',
        ]);
        $day = RaceDay::query()->create([
            'race_meeting_id' => $meeting->id,
            'external_race_day_id' => 'audit-day',
            'race_date' => '2026-07-26',
            'day_number' => 1,
            'encrypted_parameter' => 'enc-day',
        ]);
        $raceParameter = new RaceParameterDto('enc-race', false, false);
        $metadata = new RaceDayMetadataPageDto(
            selectedDate: '20260726',
            trackCode: '56',
            selectedRaceNumber: 1,
            meetingName: '監査試験開催',
            trackName: '監査試験場',
            grade: 'F1',
            days: [new RaceDayParameterDto('20260726', '初日', 'enc-day')],
            races: [$raceParameter],
        );

        return [$day, $metadata];
    }

    /**
     * @param  list<int>  $bikeNumbers
     * @param  array<int,string>  $externalPlayerIds
     */
    private function syncRaceDay(
        RaceDay $day,
        RaceDayMetadataPageDto $metadata,
        array $bikeNumbers,
        string $fetchedAt,
        array $externalPlayerIds = [],
    ): void {
        $entries = array_map(
            fn (int $bikeNumber): RaceListEntryDto => new RaceListEntryDto(
                bikeNumber: $bikeNumber,
                externalPlayerId: $externalPlayerIds[$bikeNumber] ?? sprintf('%06d', $bikeNumber),
                playerName: "監査選手{$bikeNumber}",
                prefecture: '東京',
                ridingStyle: '両',
            ),
            $bikeNumbers,
        );
        $page = new RaceEntryListPageDto(
            trackCode: '56',
            raceDate: '20260726',
            lastUpdatedAt: null,
            races: [new RaceListRaceDto(
                raceNumber: 1,
                raceType: 'S級予選',
                salesCloseTime: '11:55',
                startTime: '12:00',
                resultAvailable: false,
                category: RaceCategory::Men,
                entries: $entries,
            )],
        );

        $this->races->syncRaceDay(
            $day,
            $metadata,
            $page,
            [1 => new RaceParameterDto('enc-race', false, false)],
            new DateTimeImmutable($fetchedAt),
        );
    }

    /**
     * @param  array<int,string>  $scores
     * @param  array<int,string>  $externalPlayerIds
     */
    private function detail(
        array $scores,
        array $externalPlayerIds = [],
        array $frameNumbers = [],
        array $grades = [],
    ): RaceDetailPageDto {
        $entries = [];
        foreach ($scores as $bikeNumber => $score) {
            $entries[] = new RaceDetailEntryDto(
                bikeNumber: $bikeNumber,
                frameNumber: $frameNumbers[$bikeNumber] ?? $bikeNumber,
                externalPlayerId: $externalPlayerIds[$bikeNumber] ?? sprintf('%06d', $bikeNumber),
                playerName: "監査選手{$bikeNumber}",
                prefecture: '東京',
                previousGrade: null,
                grade: $grades[$bikeNumber] ?? 'S1',
                ridingStyle: '両',
                graduationPeriod: null,
                age: 30,
                raceScore: $score,
                escapeCount: null,
                sprintCount: null,
                overtakeCount: null,
                markCount: null,
                backCount: null,
                homeCount: null,
                startCount: null,
                winRate: null,
                quinellaRate: null,
                trioRate: null,
            );
        }

        return new RaceDetailPageDto(
            raceDate: '20260726',
            trackCode: '56',
            raceNumber: 1,
            raceType: 'S級予選',
            distance: 2000,
            laps: 5,
            raceName: '監査試験競走',
            startTime: '12:00',
            salesCloseTime: '11:55',
            entries: $entries,
        );
    }

    /**
     * @return array<int,string>
     */
    private function scores(int $count): array
    {
        $scores = [];
        foreach (range(1, $count) as $bikeNumber) {
            $scores[$bikeNumber] = number_format(101 - $bikeNumber, 2, '.', '');
        }

        return $scores;
    }

    private function buildStat01(Race $race, int $exitCode): void
    {
        $this->artisan('keirin:statistics:build-stat01', [
            '--race-id' => (string) $race->id,
        ])->assertExitCode($exitCode);
    }

    private function snapshotForEntry(Race $race, int $raceEntryId): RaceEntrySnapshotDto
    {
        $race = $race->unsetRelation('entries')->load('entries');
        $snapshots = $this->app->make(RaceEntrySnapshotService::class)->snapshotsForRace(
            $race,
            $this->app->make(StatInputAsOfResolver::class)->resolve($race),
            true,
        );

        foreach ($snapshots as $snapshot) {
            if ($snapshot->raceEntryId === $raceEntryId) {
                return $snapshot;
            }
        }

        throw new \RuntimeException("Race entry snapshot {$raceEntryId} was missing.");
    }

    private function fetchLog(string $requestKey): ScrapingFetchLog
    {
        return ScrapingFetchLog::query()->create([
            'source' => 'keirin_jp',
            'request_method' => 'POST',
            'request_url' => "https://example.invalid/{$requestKey}",
            'request_key' => $requestKey,
            'http_status' => 200,
            'fetched_at' => '2026-07-26 10:05:00+09:00',
            'utf8_conversion_succeeded' => true,
            'response_size' => 123,
            'sha256' => str_repeat('b', 64),
            'raw_file_path' => "scraping/raw/{$requestKey}.html",
            'retry_count' => 0,
            'parser_version' => 'source-fingerprint-test',
        ]);
    }

    private function primaryOccurrenceId(int $runId, int $raceEntryId): int
    {
        return (int) DB::table('statistic_run_feature_snapshot_occurrences')
            ->where('calculation_run_id', $runId)
            ->where('source_race_entry_id', $raceEntryId)
            ->where('source_role', 'PRIMARY_INPUT')
            ->sole()
            ->race_entry_snapshot_occurrence_id;
    }

    private function primarySourceStateId(int $runId, int $raceEntryId): int
    {
        return (int) DB::table('statistic_run_feature_snapshot_occurrences')
            ->where('calculation_run_id', $runId)
            ->where('source_race_entry_id', $raceEntryId)
            ->where('source_role', 'PRIMARY_INPUT')
            ->sole()
            ->race_entry_snapshot_source_id;
    }

    private function featureSnapshotIdForRun(int $runId, int $raceEntryId): int
    {
        return (int) DB::table('statistic_run_feature_snapshots')
            ->join(
                'stat_feature_snapshots',
                'stat_feature_snapshots.id',
                '=',
                'statistic_run_feature_snapshots.stat_feature_snapshot_id',
            )
            ->where('statistic_run_feature_snapshots.calculation_run_id', $runId)
            ->where('stat_feature_snapshots.race_entry_id', $raceEntryId)
            ->value('stat_feature_snapshots.id');
    }
}
