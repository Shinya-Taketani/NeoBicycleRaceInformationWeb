<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Keirin\Statistics;

use App\Domain\Keirin\Statistics\Enums\RaceScoreValidationStatus;
use App\Domain\Keirin\Statistics\Services\RaceEntrySnapshotService;
use App\Models\Race;
use App\Models\RaceEntry;
use App\Models\RaceEntrySnapshotSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RaceEntrySnapshotServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_same_content_reuses_snapshot_and_changed_score_creates_new_current_snapshot(): void
    {
        [$race, $entry] = $this->raceEntry('100.00');
        $service = $this->app->make(RaceEntrySnapshotService::class);

        $first = $service->snapshotsForRace($race->load('entries'), true)[0];
        $second = $service->snapshotsForRace($race->load('entries'), true)[0];

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('race_entry_snapshots', 1);
        $this->assertDatabaseCount('race_entry_snapshot_sources', 1);

        $entry->forceFill([
            'race_score' => '101.25',
            'fetched_at' => '2026-07-25 13:00:00+09:00',
        ])->save();
        $changed = $service->snapshotsForRace($race->unsetRelation('entries')->load('entries'), true)[0];

        $this->assertNotSame($first->id, $changed->id);
        $this->assertDatabaseCount('race_entry_snapshots', 2);
        $this->assertDatabaseHas('race_entry_snapshots', [
            'id' => $first->id,
            'is_current' => false,
        ]);
        $this->assertDatabaseHas('race_entry_snapshots', [
            'id' => $changed->id,
            'race_score_raw_text' => '101.25',
            'race_score' => 101.25,
            'race_score_validation_status' => 'VALID',
            'is_current' => true,
        ]);
    }

    public function test_zero_and_null_scores_preserve_raw_validation_without_imputation(): void
    {
        $service = $this->app->make(RaceEntrySnapshotService::class);
        [$zeroRace] = $this->raceEntry('0.00');
        [$nullRace] = $this->raceEntry(null);

        $zero = $service->snapshotsForRace($zeroRace->load('entries'), true)[0];
        $missing = $service->snapshotsForRace($nullRace->load('entries'), true)[0];

        $this->assertSame('0.00', $zero->raceScoreRawText);
        $this->assertNull($zero->raceScore);
        $this->assertSame(RaceScoreValidationStatus::NonPositive, $zero->validationStatus);
        $this->assertNull($missing->raceScoreRawText);
        $this->assertNull($missing->raceScore);
        $this->assertSame(RaceScoreValidationStatus::Missing, $missing->validationStatus);
    }

    public function test_legacy_source_is_eligible_but_never_guesses_a_fetch_log(): void
    {
        [$race] = $this->raceEntry('100.00');
        $snapshot = $this->app->make(RaceEntrySnapshotService::class)
            ->snapshotsForRace($race->load('entries'), true)[0];

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

    public function test_score_normalization_has_no_domain_ceiling_below_storage_capacity(): void
    {
        $service = $this->app->make(RaceEntrySnapshotService::class);

        $this->assertSame(RaceScoreValidationStatus::InvalidFormat, $service->normalizeRaceScore('unknown')->status);
        $this->assertSame(RaceScoreValidationStatus::NonPositive, $service->normalizeRaceScore('-1')->status);
        $this->assertSame(RaceScoreValidationStatus::Valid, $service->normalizeRaceScore('99999999.9998')->status);
        $this->assertSame(RaceScoreValidationStatus::OutOfStorageRange, $service->normalizeRaceScore('100000000')->status);
    }

    public function test_persisted_player_profile_source_is_not_eligible_for_stat01_backfill(): void
    {
        [$race] = $this->raceEntry('100.00');
        $service = $this->app->make(RaceEntrySnapshotService::class);
        $service->snapshotsForRace($race->load('entries'), true);
        RaceEntrySnapshotSource::query()->sole()->forceFill([
            'source_page_type' => 'PLAYER_PROFILE',
            'historical_backfill_scope' => 'NOT_ELIGIBLE',
        ])->save();

        $snapshot = $service->snapshotsForRace($race->unsetRelation('entries')->load('entries'), true)[0];

        $this->assertFalse($snapshot->raceScoreEligible);
    }

    /**
     * @return array{Race,RaceEntry}
     */
    private function raceEntry(?string $score): array
    {
        $sequence = Race::query()->count() + 1;
        $race = Race::query()->create([
            'source' => 'keirin_jp',
            'external_race_id' => "snapshot:{$sequence}",
            'race_date' => '2024-01-01',
            'race_number' => $sequence,
            'scheduled_start_at' => '2024-01-01 12:00:00+09:00',
        ]);
        $entry = RaceEntry::query()->create([
            'race_id' => $race->id,
            'external_player_id' => sprintf('%06d', $sequence),
            'bike_number' => 1,
            'race_score' => $score,
            'fetched_at' => '2026-07-24 12:00:00+09:00',
        ]);

        return [$race, $entry];
    }
}
