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
use App\Models\Race;
use App\Models\RaceDay;
use App\Models\RaceEntry;
use App\Models\RaceEntrySnapshot;
use App\Models\RaceMeeting;
use App\Models\Racetrack;
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
            ->where('is_current', true)
            ->firstOrFail();
        $snapshotIdentity = [
            'id' => (int) $snapshot->id,
            'hash' => $snapshot->snapshot_hash,
            'type' => $snapshot->input_snapshot_type,
        ];
        $scoreFetchedAt = $entry->race_score_fetched_at;

        $this->syncRaceDay($day, $metadata, range(1, 5), '2026-07-26 10:30:00+09:00');

        $entry->refresh();
        $this->assertSame('2026-07-26 10:30:00', $entry->fetched_at->format('Y-m-d H:i:s'));
        $this->assertEquals($scoreFetchedAt, $entry->race_score_fetched_at);
        $this->assertSame('100.00', $entry->race_score);

        $this->buildStat01($race->refresh(), 0);
        $current = RaceEntrySnapshot::query()
            ->where('race_entry_id', $entry->id)
            ->where('is_current', true)
            ->firstOrFail();
        $this->assertSame($snapshotIdentity, [
            'id' => (int) $current->id,
            'hash' => $current->snapshot_hash,
            'type' => $current->input_snapshot_type,
        ]);
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
        $oldSnapshot->refresh();
        $this->assertFalse($oldSnapshot->is_current);
        $this->assertSame('2026-07-26 10:45:00', $oldSnapshot->effective_to->format('Y-m-d H:i:s'));
        $newSnapshot = RaceEntrySnapshot::query()
            ->where('race_entry_id', $entry->id)
            ->where('is_current', true)
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
        $entrySnapshotId = RaceEntrySnapshot::query()->where('race_entry_id', $entry->id)->value('id');
        $featureSnapshotId = StatFeatureSnapshot::query()->where('race_entry_id', $entry->id)->value('id');
        $this->syncRaceDay($day, $metadata, range(1, 5), '2026-07-26 10:30:00+09:00');

        $this->syncRaceDay($day, $metadata, range(1, 6), '2026-07-26 10:45:00+09:00');

        $restored = RaceEntry::query()->where('race_id', $race->id)->where('bike_number', 6)->sole();
        $this->assertSame((int) $entry->id, (int) $restored->id);
        $this->assertNull($restored->deleted_at);
        $this->assertEquals($scoreFetchedAt, $restored->race_score_fetched_at);
        $this->assertSame('95.00', $restored->race_score);
        $this->assertSame((int) $entrySnapshotId, (int) RaceEntrySnapshot::query()->where('race_entry_id', $restored->id)->value('id'));
        $this->assertSame((int) $featureSnapshotId, (int) StatFeatureSnapshot::query()->where('race_entry_id', $restored->id)->value('id'));
        $this->assertSame(6, RaceEntry::withTrashed()->where('race_id', $race->id)->count());
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
        $this->assertSame('UNKNOWN_SOURCE_TIMING', $snapshot->input_snapshot_type);
        $this->assertNull($snapshot->first_observed_at);
        $this->assertNull($snapshot->last_observed_at);
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
     */
    private function syncRaceDay(
        RaceDay $day,
        RaceDayMetadataPageDto $metadata,
        array $bikeNumbers,
        string $fetchedAt,
    ): void {
        $entries = array_map(fn (int $bikeNumber): RaceListEntryDto => new RaceListEntryDto(
            bikeNumber: $bikeNumber,
            externalPlayerId: sprintf('%06d', $bikeNumber),
            playerName: "監査選手{$bikeNumber}",
            prefecture: '東京',
            ridingStyle: '両',
        ), $bikeNumbers);
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
     */
    private function detail(array $scores): RaceDetailPageDto
    {
        $entries = [];
        foreach ($scores as $bikeNumber => $score) {
            $entries[] = new RaceDetailEntryDto(
                bikeNumber: $bikeNumber,
                frameNumber: $bikeNumber,
                externalPlayerId: sprintf('%06d', $bikeNumber),
                playerName: "監査選手{$bikeNumber}",
                prefecture: '東京',
                previousGrade: null,
                grade: 'S1',
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
}
