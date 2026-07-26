<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\Services;

use App\Domain\Keirin\Statistics\DTO\NormalizedRaceScoreDto;
use App\Domain\Keirin\Statistics\DTO\RaceEntrySnapshotDto;
use App\Domain\Keirin\Statistics\DTO\StatInputAsOfDto;
use App\Domain\Keirin\Statistics\Enums\RaceScoreValidationStatus;
use App\Domain\Keirin\Statistics\Enums\StatInputSnapshotType;
use App\Models\Race;
use App\Models\RaceEntry;
use App\Models\RaceEntrySnapshot;
use App\Models\RaceEntrySnapshotSource;
use DateTimeImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use JsonException;
use RuntimeException;

final class RaceEntrySnapshotService
{
    private const SNAPSHOT_TYPE = 'LEGACY_BACKFILL';

    /**
     * @return list<RaceEntrySnapshotDto>
     *
     * @throws JsonException
     */
    public function snapshotsForRace(
        Race $race,
        StatInputAsOfDto $inputAsOf,
        bool $persist,
    ): array {
        /** @var Collection<int,RaceEntry> $entries */
        $entries = $race->relationLoaded('entries')
            ? $race->entries
            : $race->entries()->orderBy('id')->get();
        $entries = $entries->sortBy('id')->values();

        $build = fn (): array => $entries
            ->map(fn (RaceEntry $entry): RaceEntrySnapshotDto => $this->snapshot(
                $race,
                $entry,
                $inputAsOf,
                $persist,
            ))
            ->all();

        return $persist ? DB::transaction($build) : $build();
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
    private function snapshot(
        Race $race,
        RaceEntry $entry,
        StatInputAsOfDto $inputAsOf,
        bool $persist,
    ): RaceEntrySnapshotDto {
        if ($persist) {
            $entry = RaceEntry::query()
                ->whereKey($entry->id)
                ->lockForUpdate()
                ->firstOrFail();
        }

        $current = RaceEntrySnapshot::query()
            ->where('race_entry_id', $entry->id)
            ->where('is_current', true)
            ->when($persist, fn ($query) => $query->lockForUpdate())
            ->first();
        $sourceTemplate = $this->sourceTemplate($race, $entry, $current);
        $observedAt = $entry->fetched_at;
        if (! $observedAt instanceof DateTimeImmutable) {
            throw new RuntimeException("Race entry {$entry->id} fetched_at was unavailable.");
        }

        $inputSnapshotType = $this->inputSnapshotType($sourceTemplate, $observedAt, $inputAsOf);
        $rawScore = $entry->race_score;
        $score = $this->normalizeRaceScore($rawScore);
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
            'input_snapshot_type' => $inputSnapshotType->value,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
        $sourceIdentityKey = "legacy-race-entry:{$entry->id}:{$hash}";

        if (! $persist) {
            return $this->dto(
                null,
                $race,
                $entry,
                $score,
                $inputSnapshotType,
                $hash,
                $observedAt,
                $sourceIdentityKey,
                $sourceTemplate,
            );
        }

        if ($current instanceof RaceEntrySnapshot && $current->snapshot_hash !== $hash) {
            $current->forceFill([
                'is_current' => false,
                'effective_to' => $observedAt,
            ])->save();
        }

        $snapshot = RaceEntrySnapshot::query()
            ->where('race_entry_id', $entry->id)
            ->where('snapshot_hash', $hash)
            ->lockForUpdate()
            ->first();
        if (! $snapshot instanceof RaceEntrySnapshot) {
            $snapshot = RaceEntrySnapshot::query()->create([
                'race_entry_id' => $entry->id,
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
                'input_snapshot_type' => $inputSnapshotType->value,
                'snapshot_hash' => $hash,
                'first_observed_at' => $observedAt,
                'last_observed_at' => $observedAt,
                'effective_from' => null,
                'effective_to' => null,
                'is_current' => true,
                'is_complete' => $score->status === RaceScoreValidationStatus::Valid,
                'parser_version' => null,
            ]);
        } else {
            $lastObservedAt = $snapshot->last_observed_at;
            $snapshot->forceFill([
                'last_observed_at' => ! $lastObservedAt instanceof DateTimeImmutable || $observedAt > $lastObservedAt
                    ? $observedAt
                    : $lastObservedAt,
                'effective_to' => null,
                'is_current' => true,
            ])->save();
        }

        $snapshotSource = RaceEntrySnapshotSource::query()->firstOrCreate(
            [
                'race_entry_snapshot_id' => $snapshot->id,
                'source_role' => $sourceTemplate['source_role'],
                'source_identity_key' => $sourceIdentityKey,
            ],
            [
                'scraping_fetch_log_id' => $sourceTemplate['scraping_fetch_log_id'],
                'contributed_fields' => $sourceTemplate['contributed_fields'],
                'source_page_type' => $sourceTemplate['source_page_type'],
                'source_race_context_key' => $sourceTemplate['source_race_context_key'],
                'context_match_method' => $sourceTemplate['context_match_method'],
                'context_verification_status' => $sourceTemplate['context_verification_status'],
                'historical_backfill_scope' => $sourceTemplate['historical_backfill_scope'],
                'eligible_fields' => $sourceTemplate['eligible_fields'],
                'source_reference_at' => $inputAsOf->value,
                'context_verified_at' => $sourceTemplate['context_verified_at'],
                'context_evidence' => $sourceTemplate['context_evidence'],
            ],
        );

        return $this->dto(
            (int) $snapshot->id,
            $race,
            $entry,
            $score,
            $inputSnapshotType,
            $hash,
            $observedAt,
            $sourceIdentityKey,
            $this->sourceTemplateFromModel($snapshotSource),
            $snapshotSource,
        );
    }

    /**
     * @param  array<string,mixed>  $source
     */
    private function inputSnapshotType(
        array $source,
        DateTimeImmutable $observedAt,
        StatInputAsOfDto $inputAsOf,
    ): StatInputSnapshotType {
        if ($source['source_page_type'] === 'PLAYER_PROFILE') {
            return StatInputSnapshotType::CurrentPlayerProfile;
        }
        if ($inputAsOf->value === null) {
            return StatInputSnapshotType::UnknownSourceTiming;
        }
        if ($observedAt <= $inputAsOf->value) {
            return StatInputSnapshotType::LivePreRaceCard;
        }
        if ($this->isEligibleRaceCardSource($source)) {
            return StatInputSnapshotType::HistoricalRaceCardBackfill;
        }

        return StatInputSnapshotType::UnknownSourceTiming;
    }

    /**
     * @return array<string,mixed>
     */
    private function sourceTemplate(
        Race $race,
        RaceEntry $entry,
        ?RaceEntrySnapshot $current,
    ): array {
        if ($current instanceof RaceEntrySnapshot) {
            $source = $current->sources()
                ->orderBy('id')
                ->get()
                ->first(static fn (RaceEntrySnapshotSource $candidate): bool => in_array(
                    'race_score',
                    $candidate->contributed_fields ?? [],
                    true,
                ));
            if ($source instanceof RaceEntrySnapshotSource) {
                return $this->sourceTemplateFromModel($source);
            }
        }

        return [
            'source_role' => 'LEGACY_RACE_CARD',
            'scraping_fetch_log_id' => null,
            'contributed_fields' => ['frame_number', 'grade', 'race_score'],
            'source_page_type' => 'RACE_DETAIL',
            'source_race_context_key' => "race:{$race->id}",
            'context_match_method' => 'RACE_ENTRY_FOREIGN_KEY',
            'context_verification_status' => 'VERIFIED_LEGACY_RECONCILED',
            'historical_backfill_scope' => 'STATIC_RACE_CARD_FIELDS_ONLY',
            'eligible_fields' => ['race_score'],
            'context_verified_at' => new DateTimeImmutable('now'),
            'context_evidence' => [
                'race_id' => (int) $race->id,
                'race_entry_id' => (int) $entry->id,
                'source_link_status' => 'SOURCE_LINK_MISSING',
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function sourceTemplateFromModel(RaceEntrySnapshotSource $source): array
    {
        return [
            'source_role' => $source->source_role,
            'scraping_fetch_log_id' => $source->scraping_fetch_log_id,
            'contributed_fields' => $source->contributed_fields ?? [],
            'source_page_type' => $source->source_page_type,
            'source_race_context_key' => $source->source_race_context_key,
            'context_match_method' => $source->context_match_method,
            'context_verification_status' => $source->context_verification_status,
            'historical_backfill_scope' => $source->historical_backfill_scope,
            'eligible_fields' => $source->eligible_fields ?? [],
            'context_verified_at' => $source->context_verified_at,
            'context_evidence' => $source->context_evidence,
        ];
    }

    /**
     * @param  array<string,mixed>  $source
     */
    private function isEligibleRaceCardSource(array $source): bool
    {
        return in_array($source['source_page_type'], ['RACE_ENTRY_LIST', 'RACE_DETAIL'], true)
            && in_array('race_score', $source['eligible_fields'], true)
            && in_array(
                $source['historical_backfill_scope'],
                ['ALL_CONTRIBUTED_FIELDS', 'STATIC_RACE_CARD_FIELDS_ONLY'],
                true,
            )
            && in_array(
                $source['context_verification_status'],
                ['VERIFIED_EXACT', 'VERIFIED_LEGACY_RECONCILED'],
                true,
            );
    }

    /**
     * @param  array<string,mixed>  $sourceTemplate
     */
    private function dto(
        ?int $id,
        Race $race,
        RaceEntry $entry,
        NormalizedRaceScoreDto $score,
        StatInputSnapshotType $inputSnapshotType,
        string $hash,
        DateTimeImmutable $observedAt,
        string $sourceIdentityKey,
        array $sourceTemplate,
        ?RaceEntrySnapshotSource $source = null,
    ): RaceEntrySnapshotDto {
        $fetchLog = $source?->scraping_fetch_log_id === null ? null : $source->fetchLog()->first();
        $raceScoreEligible = in_array($inputSnapshotType, [
            StatInputSnapshotType::LivePreRaceCard,
            StatInputSnapshotType::HistoricalRaceCardBackfill,
        ], true) && $this->isEligibleRaceCardSource($sourceTemplate);

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
            inputSnapshotType: $inputSnapshotType->value,
            snapshotHash: $hash,
            observedAt: $observedAt,
            parserVersion: $fetchLog?->parser_version,
            sourceLinkMissing: $sourceTemplate['scraping_fetch_log_id'] === null,
            raceScoreEligible: $raceScoreEligible,
            scrapingFetchLogId: $sourceTemplate['scraping_fetch_log_id'],
            sourceIdentityKey: $sourceIdentityKey,
            sourcePageType: $sourceTemplate['source_page_type'],
            sourceUrl: $fetchLog?->request_url,
            rawFilePath: $fetchLog?->raw_file_path,
            rawSha256: $fetchLog?->sha256,
        );
    }
}
