<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\Services;

use App\Domain\Keirin\Statistics\DTO\NormalizedRaceScoreDto;
use App\Domain\Keirin\Statistics\DTO\RaceEntrySnapshotDto;
use App\Domain\Keirin\Statistics\Enums\RaceScoreValidationStatus;
use App\Models\Race;
use App\Models\RaceEntry;
use App\Models\RaceEntrySnapshot;
use App\Models\RaceEntrySnapshotSource;
use DateTimeImmutable;
use Illuminate\Support\Collection;
use JsonException;

final class RaceEntrySnapshotService
{
    private const SNAPSHOT_TYPE = 'LEGACY_BACKFILL';

    private const INPUT_SNAPSHOT_TYPE = 'HISTORICAL_RACE_CARD_BACKFILL';

    /**
     * @return list<RaceEntrySnapshotDto>
     *
     * @throws JsonException
     */
    public function snapshotsForRace(Race $race, bool $persist): array
    {
        /** @var Collection<int,RaceEntry> $entries */
        $entries = $race->relationLoaded('entries')
            ? $race->entries
            : $race->entries()->orderBy('id')->get();

        return $entries->sortBy('id')
            ->map(fn (RaceEntry $entry): RaceEntrySnapshotDto => $this->snapshot($race, $entry, $persist))
            ->values()
            ->all();
    }

    public function normalizeRaceScore(?string $rawText): NormalizedRaceScoreDto
    {
        if ($rawText === null || trim($rawText) === '') {
            return new NormalizedRaceScoreDto($rawText, null, RaceScoreValidationStatus::Missing);
        }
        $normalized = trim($rawText);
        if (preg_match('/^[+-]?\d+(?:\.\d+)?$/', $normalized) !== 1) {
            return new NormalizedRaceScoreDto($rawText, null, RaceScoreValidationStatus::InvalidFormat);
        }
        $value = (float) $normalized;
        if (! is_finite($value)) {
            return new NormalizedRaceScoreDto($rawText, null, RaceScoreValidationStatus::InvalidFormat);
        }
        if ($value <= 0.0) {
            return new NormalizedRaceScoreDto($rawText, null, RaceScoreValidationStatus::NonPositive);
        }
        if ($value >= 100000000.0) {
            return new NormalizedRaceScoreDto($rawText, null, RaceScoreValidationStatus::OutOfStorageRange);
        }

        return new NormalizedRaceScoreDto($rawText, $value, RaceScoreValidationStatus::Valid);
    }

    /**
     * @throws JsonException
     */
    private function snapshot(Race $race, RaceEntry $entry, bool $persist): RaceEntrySnapshotDto
    {
        $rawScore = $entry->race_score;
        $score = $this->normalizeRaceScore($rawScore);
        $observedAt = $entry->fetched_at;
        if (! $observedAt instanceof DateTimeImmutable) {
            throw new \RuntimeException("Race entry {$entry->id} fetched_at was unavailable.");
        }
        $hash = hash('sha256', json_encode([
            'race_entry_id' => (int) $entry->id,
            'race_id' => (int) $race->id,
            'player_id' => $entry->player_id === null ? null : (int) $entry->player_id,
            'bike_number' => (int) $entry->bike_number,
            'frame_number' => $entry->frame_number === null ? null : (int) $entry->frame_number,
            'grade' => $entry->grade,
            'race_score_raw_text' => $score->rawText,
            'race_score' => $score->value,
            'race_score_validation_status' => $score->status->value,
            'snapshot_type' => self::SNAPSHOT_TYPE,
            'input_snapshot_type' => self::INPUT_SNAPSHOT_TYPE,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
        $sourceIdentityKey = "legacy-race-entry:{$entry->id}:{$hash}";

        if (! $persist) {
            return $this->dto(
                null,
                $race,
                $entry,
                $score,
                $hash,
                $observedAt,
                $sourceIdentityKey,
            );
        }

        RaceEntrySnapshot::query()
            ->where('race_entry_id', $entry->id)
            ->where('is_current', true)
            ->where('snapshot_hash', '!=', $hash)
            ->update([
                'is_current' => false,
                'effective_to' => $observedAt,
                'updated_at' => new DateTimeImmutable('now'),
            ]);

        $snapshot = RaceEntrySnapshot::query()->firstOrCreate(
            ['race_entry_id' => $entry->id, 'snapshot_hash' => $hash],
            [
                'race_id' => $race->id,
                'player_id' => $entry->player_id,
                'bike_number' => $entry->bike_number,
                'frame_number' => $entry->frame_number,
                'grade' => $entry->grade,
                'race_score_raw_text' => $score->rawText,
                'race_score' => $score->value,
                'race_score_validation_status' => $score->status->value,
                'race_score_anomaly_status' => 'NOT_CHECKED',
                'snapshot_type' => self::SNAPSHOT_TYPE,
                'input_snapshot_type' => self::INPUT_SNAPSHOT_TYPE,
                'first_observed_at' => $observedAt,
                'last_observed_at' => $observedAt,
                'effective_from' => null,
                'effective_to' => null,
                'is_current' => true,
                'is_complete' => $score->status === RaceScoreValidationStatus::Valid,
                'parser_version' => null,
            ],
        );
        if (! $snapshot->wasRecentlyCreated) {
            $snapshot->forceFill([
                'last_observed_at' => max($snapshot->last_observed_at, $observedAt),
                'effective_to' => null,
                'is_current' => true,
            ])->save();
        }

        $snapshotSource = RaceEntrySnapshotSource::query()->firstOrCreate(
            [
                'race_entry_snapshot_id' => $snapshot->id,
                'source_role' => 'LEGACY_RACE_CARD',
                'source_identity_key' => $sourceIdentityKey,
            ],
            [
                'scraping_fetch_log_id' => null,
                'contributed_fields' => ['frame_number', 'grade', 'race_score'],
                'source_page_type' => 'RACE_DETAIL',
                'source_race_context_key' => "race:{$race->id}",
                'context_match_method' => 'RACE_ENTRY_FOREIGN_KEY',
                'context_verification_status' => 'VERIFIED_LEGACY_RECONCILED',
                'historical_backfill_scope' => 'STATIC_RACE_CARD_FIELDS_ONLY',
                'eligible_fields' => ['race_score'],
                'source_reference_at' => $race->sales_close_at ?? $race->scheduled_start_at,
                'context_verified_at' => new DateTimeImmutable('now'),
                'context_evidence' => [
                    'race_id' => (int) $race->id,
                    'race_entry_id' => (int) $entry->id,
                    'source_link_status' => 'SOURCE_LINK_MISSING',
                ],
            ],
        );

        return $this->dto(
            (int) $snapshot->id,
            $race,
            $entry,
            $score,
            $hash,
            $observedAt,
            $sourceIdentityKey,
            $snapshotSource,
        );
    }

    private function dto(
        ?int $id,
        Race $race,
        RaceEntry $entry,
        NormalizedRaceScoreDto $score,
        string $hash,
        DateTimeImmutable $observedAt,
        string $sourceIdentityKey,
        ?RaceEntrySnapshotSource $source = null,
    ): RaceEntrySnapshotDto {
        $fetchLog = $source?->scraping_fetch_log_id === null ? null : $source->fetchLog()->first();
        $eligibleFields = $source?->eligible_fields ?? ['race_score'];
        $raceScoreEligible = in_array($source?->source_page_type ?? 'RACE_DETAIL', ['RACE_ENTRY_LIST', 'RACE_DETAIL'], true)
            && in_array('race_score', $eligibleFields, true)
            && ($source?->historical_backfill_scope ?? 'STATIC_RACE_CARD_FIELDS_ONLY') !== 'NOT_ELIGIBLE'
            && in_array(
                $source?->context_verification_status ?? 'VERIFIED_LEGACY_RECONCILED',
                ['VERIFIED_EXACT', 'VERIFIED_LEGACY_RECONCILED'],
                true,
            );

        return new RaceEntrySnapshotDto(
            id: $id,
            raceEntryId: (int) $entry->id,
            raceId: (int) $race->id,
            playerId: $entry->player_id === null ? null : (int) $entry->player_id,
            bikeNumber: (int) $entry->bike_number,
            frameNumber: $entry->frame_number === null ? null : (int) $entry->frame_number,
            grade: $entry->grade,
            raceScoreRawText: $score->rawText,
            raceScore: $score->value,
            validationStatus: $score->status,
            snapshotType: self::SNAPSHOT_TYPE,
            inputSnapshotType: self::INPUT_SNAPSHOT_TYPE,
            snapshotHash: $hash,
            observedAt: $observedAt,
            parserVersion: $fetchLog?->parser_version,
            sourceLinkMissing: $source?->scraping_fetch_log_id === null,
            raceScoreEligible: $raceScoreEligible,
            scrapingFetchLogId: $source?->scraping_fetch_log_id,
            sourceIdentityKey: $sourceIdentityKey,
            sourcePageType: $source?->source_page_type ?? 'RACE_DETAIL',
            sourceUrl: $fetchLog?->request_url,
            rawFilePath: $fetchLog?->raw_file_path,
            rawSha256: $fetchLog?->sha256,
        );
    }
}
