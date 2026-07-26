<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Keirin\Statistics;

use App\Domain\Keirin\Statistics\Calculators\Stat01Calculator;
use App\Domain\Keirin\Statistics\DTO\RaceEntrySnapshotDto;
use App\Domain\Keirin\Statistics\Enums\RaceScoreValidationStatus;
use App\Domain\Keirin\Statistics\Enums\StatFeatureStatus;
use App\Domain\Keirin\Statistics\Services\RaceEntrySnapshotService;
use App\Domain\Keirin\Statistics\Services\Stat01RaceInputFactory;
use App\Domain\Keirin\Statistics\Services\StatInputAsOfResolver;
use App\Models\Race;
use App\Models\RaceEntry;
use App\Models\RaceEntrySnapshot;
use App\Models\RaceEntrySnapshotSource;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RaceEntrySnapshotServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_same_content_reuses_snapshot_and_changed_score_creates_one_new_current_snapshot(): void
    {
        [$race, $entry] = $this->raceEntry('100.00');
        $service = $this->app->make(RaceEntrySnapshotService::class);

        $first = $this->snapshots($service, $race)[0];
        $second = $this->snapshots($service, $race)[0];

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('race_entry_snapshots', 1);
        $this->assertDatabaseCount('race_entry_snapshot_sources', 1);

        $entry->forceFill([
            'fetched_at' => '2026-07-25 13:00:00+09:00',
            'race_score_fetched_at' => '2026-07-25 13:00:00+09:00',
        ])->save();
        $observedAgain = $this->snapshots($service, $race)[0];
        $this->assertSame($first->id, $observedAgain->id);
        $this->assertSame(
            '2026-07-25 13:00:00',
            RaceEntrySnapshot::query()->findOrFail($first->id)->last_observed_at->format('Y-m-d H:i:s'),
        );
        $this->assertDatabaseCount('race_entry_snapshots', 1);

        $entry->forceFill([
            'race_score' => '101.25',
            'fetched_at' => '2026-07-26 13:00:00+09:00',
            'race_score_fetched_at' => '2026-07-26 13:00:00+09:00',
        ])->save();
        $changed = $this->snapshots($service, $race)[0];

        $this->assertNotSame($first->id, $changed->id);
        $this->assertDatabaseCount('race_entry_snapshots', 2);
        $old = RaceEntrySnapshot::query()->findOrFail($first->id);
        $this->assertFalse($old->is_current);
        $this->assertSame('2026-07-26 13:00:00', $old->effective_to->format('Y-m-d H:i:s'));
        $this->assertDatabaseHas('race_entry_snapshots', [
            'id' => $changed->id,
            'race_score_raw_text' => '101.25',
            'race_score' => 101.25,
            'race_score_validation_status' => 'VALID',
            'is_current' => true,
        ]);
        $this->assertSame(
            1,
            RaceEntrySnapshot::query()
                ->where('race_entry_id', $entry->id)
                ->where('is_current', true)
                ->count(),
        );

        $rerun = $this->snapshots($service, $race)[0];
        $this->assertSame($changed->id, $rerun->id);
        $this->assertDatabaseCount('race_entry_snapshots', 2);
        $this->assertSame(
            1,
            RaceEntrySnapshot::query()
                ->where('race_entry_id', $entry->id)
                ->where('is_current', true)
                ->count(),
        );
    }

    public function test_database_rejects_two_current_snapshots_for_one_race_entry(): void
    {
        [$race] = $this->raceEntry('100.00');
        $service = $this->app->make(RaceEntrySnapshotService::class);
        $this->snapshots($service, $race);
        $duplicate = RaceEntrySnapshot::query()->sole()->replicate();
        $duplicate->snapshot_hash = str_repeat('b', 64);

        $this->expectException(QueryException::class);
        $duplicate->save();
    }

    public function test_database_allows_multiple_non_current_history_snapshots(): void
    {
        [$race, $entry] = $this->raceEntry('100.00');
        $service = $this->app->make(RaceEntrySnapshotService::class);
        $this->snapshots($service, $race);
        $current = RaceEntrySnapshot::query()->sole();

        foreach (['b', 'c'] as $character) {
            $history = $current->replicate();
            $history->snapshot_hash = str_repeat($character, 64);
            $history->is_current = false;
            $history->effective_to = '2026-07-24 13:00:00+09:00';
            $history->save();
        }

        $this->assertSame(
            1,
            RaceEntrySnapshot::query()
                ->where('race_entry_id', $entry->id)
                ->where('is_current', true)
                ->count(),
        );
        $this->assertSame(
            2,
            RaceEntrySnapshot::query()
                ->where('race_entry_id', $entry->id)
                ->where('is_current', false)
                ->count(),
        );
    }

    public function test_zero_and_null_scores_preserve_raw_validation_without_imputation(): void
    {
        $service = $this->app->make(RaceEntrySnapshotService::class);
        [$zeroRace] = $this->raceEntry('0.00');
        [$nullRace] = $this->raceEntry(null);

        $zero = $this->snapshots($service, $zeroRace)[0];
        $missing = $this->snapshots($service, $nullRace)[0];

        $this->assertSame('0.00', $zero->raceScoreRawText);
        $this->assertNull($zero->raceScore);
        $this->assertSame(RaceScoreValidationStatus::NonPositive, $zero->validationStatus);
        $this->assertNull($missing->raceScoreRawText);
        $this->assertNull($missing->raceScore);
        $this->assertSame(RaceScoreValidationStatus::Missing, $missing->validationStatus);
    }

    public function test_verified_race_card_observed_after_input_as_of_is_historical_and_eligible(): void
    {
        [$race] = $this->raceEntry('100.00');
        $snapshot = $this->snapshots(
            $this->app->make(RaceEntrySnapshotService::class),
            $race,
        )[0];

        $this->assertSame('LEGACY_BACKFILL', $snapshot->snapshotType);
        $this->assertSame('HISTORICAL_RACE_CARD_BACKFILL', $snapshot->inputSnapshotType);
        $this->assertTrue($snapshot->raceScoreEligible);
        $this->assertTrue($snapshot->sourceLinkMissing);
        $this->assertNull($snapshot->scrapingFetchLogId);
        $this->assertDatabaseHas('race_entry_snapshot_sources', [
            'race_entry_snapshot_id' => $snapshot->id,
            'scraping_fetch_log_id' => null,
            'source_page_type' => 'RACE_DETAIL',
            'context_verification_status' => 'VERIFIED_LEGACY_RECONCILED',
            'historical_backfill_scope' => 'STATIC_RACE_CARD_FIELDS_ONLY',
        ]);
        $source = RaceEntrySnapshotSource::query()->sole();
        $this->assertSame(['race_score'], $source->eligible_fields);
        $this->assertSame('SOURCE_LINK_MISSING', $source->context_evidence['source_link_status']);
    }

    public function test_observed_before_sales_close_is_live_pre_race_card(): void
    {
        [$race] = $this->raceEntry(
            '100.00',
            fetchedAt: '2026-07-25 11:50:00+09:00',
            salesCloseAt: '2026-07-25 11:55:00+09:00',
            scheduledStartAt: '2026-07-25 12:00:00+09:00',
        );

        $snapshot = $this->snapshots(
            $this->app->make(RaceEntrySnapshotService::class),
            $race,
        )[0];

        $this->assertSame('LIVE_PRE_RACE_CARD', $snapshot->inputSnapshotType);
        $this->assertTrue($snapshot->raceScoreEligible);
    }

    public function test_observed_before_start_is_live_when_sales_close_is_unavailable(): void
    {
        [$race] = $this->raceEntry(
            '100.00',
            fetchedAt: '2026-07-25 11:50:00+09:00',
            salesCloseAt: null,
            scheduledStartAt: '2026-07-25 12:00:00+09:00',
        );

        $snapshot = $this->snapshots(
            $this->app->make(RaceEntrySnapshotService::class),
            $race,
        )[0];

        $this->assertSame('LIVE_PRE_RACE_CARD', $snapshot->inputSnapshotType);
    }

    public function test_unavailable_input_as_of_is_unknown_and_blocks_stat01(): void
    {
        [$race] = $this->raceEntry(
            '100.00',
            salesCloseAt: null,
            scheduledStartAt: null,
        );
        $service = $this->app->make(RaceEntrySnapshotService::class);
        $asOf = $this->app->make(StatInputAsOfResolver::class)->resolve($race);
        $snapshot = $service->snapshotsForRace($race->load('entries'), $asOf, true)[0];
        $input = $this->app->make(Stat01RaceInputFactory::class)->make($race, [$snapshot], $asOf);
        $result = $this->app->make(Stat01Calculator::class)->calculate($input)->results[0];

        $this->assertSame('UNKNOWN_SOURCE_TIMING', $snapshot->inputSnapshotType);
        $this->assertFalse($snapshot->raceScoreEligible);
        $this->assertSame(StatFeatureStatus::Blocked, $result->status);
    }

    public function test_player_profile_is_current_profile_ineligible_and_leakage_risk(): void
    {
        [$race] = $this->raceEntry(
            '100.00',
            salesCloseAt: null,
            scheduledStartAt: null,
        );
        $service = $this->app->make(RaceEntrySnapshotService::class);
        $this->snapshots($service, $race);
        RaceEntrySnapshotSource::query()->sole()->forceFill([
            'source_page_type' => 'PLAYER_PROFILE',
            'historical_backfill_scope' => 'NOT_ELIGIBLE',
        ])->save();

        $asOf = $this->app->make(StatInputAsOfResolver::class)->resolve($race);
        $snapshot = $service->snapshotsForRace(
            $race->unsetRelation('entries')->load('entries'),
            $asOf,
            true,
        )[0];
        $input = $this->app->make(Stat01RaceInputFactory::class)->make($race, [$snapshot], $asOf);
        $result = $this->app->make(Stat01Calculator::class)->calculate($input)->results[0];

        $this->assertSame('CURRENT_PLAYER_PROFILE', $snapshot->inputSnapshotType);
        $this->assertSame('PLAYER_PROFILE', $snapshot->sourcePageType);
        $this->assertFalse($snapshot->raceScoreEligible);
        $this->assertSame(StatFeatureStatus::LeakageRisk, $result->status);
        $this->assertDatabaseCount('race_entry_snapshots', 2);
        $this->assertSame(1, RaceEntrySnapshot::query()->where('is_current', true)->count());
    }

    public function test_score_normalization_has_no_domain_ceiling_below_storage_capacity(): void
    {
        $service = $this->app->make(RaceEntrySnapshotService::class);

        $this->assertSame(RaceScoreValidationStatus::InvalidFormat, $service->normalizeRaceScore('unknown')->status);
        $this->assertSame(RaceScoreValidationStatus::NonPositive, $service->normalizeRaceScore('-1')->status);
        $this->assertSame(RaceScoreValidationStatus::Valid, $service->normalizeRaceScore('99999999.9998')->status);
        $this->assertSame(RaceScoreValidationStatus::OutOfStorageRange, $service->normalizeRaceScore('100000000')->status);
    }

    /**
     * @return list<RaceEntrySnapshotDto>
     */
    private function snapshots(
        RaceEntrySnapshotService $service,
        Race $race,
        bool $persist = true,
    ): array {
        $race = $race->unsetRelation('entries')->load('entries');

        return $service->snapshotsForRace(
            $race,
            $this->app->make(StatInputAsOfResolver::class)->resolve($race),
            $persist,
        );
    }

    /**
     * @return array{Race,RaceEntry}
     */
    private function raceEntry(
        ?string $score,
        string $fetchedAt = '2026-07-24 12:00:00+09:00',
        ?string $salesCloseAt = null,
        ?string $scheduledStartAt = '2024-01-01 12:00:00+09:00',
    ): array {
        $sequence = Race::query()->count() + 1;
        $race = Race::query()->create([
            'source' => 'keirin_jp',
            'external_race_id' => "snapshot:{$sequence}",
            'race_date' => '2024-01-01',
            'race_number' => $sequence,
            'sales_close_at' => $salesCloseAt,
            'scheduled_start_at' => $scheduledStartAt,
        ]);
        $entry = RaceEntry::query()->create([
            'race_id' => $race->id,
            'external_player_id' => sprintf('%06d', $sequence),
            'bike_number' => 1,
            'race_score' => $score,
            'fetched_at' => $fetchedAt,
            'race_score_fetched_at' => $fetchedAt,
        ]);

        return [$race, $entry];
    }
}
