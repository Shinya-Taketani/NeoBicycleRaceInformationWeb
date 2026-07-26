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
use App\Models\RaceEntrySnapshotOccurrence;
use App\Models\RaceEntrySnapshotSource;
use DateTimeImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use JsonException;
use RuntimeException;

final class RaceEntrySnapshotService
{
    private const SNAPSHOT_TYPE = 'LEGACY_BACKFILL';

    public function __construct(
        private readonly RaceEntrySnapshotSourceFingerprint $sourceFingerprint,
    ) {}

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

        $currentOccurrence = RaceEntrySnapshotOccurrence::query()
            ->where('race_entry_id', $entry->id)
            ->where('is_current', true)
            ->with(['snapshot', 'sourceState.fetchLog'])
            ->when($persist, fn ($query) => $query->lockForUpdate())
            ->first();
        $currentSnapshot = $currentOccurrence?->snapshot;
        $sourceTemplate = $this->sourceTemplate(
            $race,
            $entry,
            $currentSnapshot,
            $currentOccurrence?->sourceState,
        );
        $scoreObservedAt = $entry->race_score_fetched_at;
        $scoreObservedAt = $scoreObservedAt instanceof DateTimeImmutable ? $scoreObservedAt : null;
        $stateObservedAt = $entry->fetched_at;
        $stateObservedAt = $stateObservedAt instanceof DateTimeImmutable ? $stateObservedAt : null;

        $inputSnapshotType = $this->inputSnapshotType($sourceTemplate, $scoreObservedAt, $inputAsOf);
        $rawScore = $entry->race_score;
        $score = $this->normalizeRaceScore($rawScore);
        $hash = hash('sha256', json_encode([
            'race_entry_id' => (int) $entry->id,
            'race_id' => (int) $race->id,
            'player_id' => $entry->player_id === null ? null : (int) $entry->player_id,
            'external_player_id' => $entry->external_player_id,
            'bike_number' => (int) $entry->bike_number,
            'frame_number' => $entry->frame_number === null ? null : (int) $entry->frame_number,
            'grade' => $entry->grade,
            'race_score_raw_text' => $score->rawText,
            'race_score' => $score->value,
            'race_score_validation_status' => $score->status->value,
            'snapshot_type' => self::SNAPSHOT_TYPE,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
        $sourceLinkMissing = $sourceTemplate['scraping_fetch_log_id'] === null;
        $raceScoreEligible = in_array($inputSnapshotType, [
            StatInputSnapshotType::LivePreRaceCard,
            StatInputSnapshotType::HistoricalRaceCardBackfill,
        ], true) && $this->isEligibleRaceCardSource($sourceTemplate);
        $sourceFingerprint = $this->sourceFingerprint->calculate(
            $sourceTemplate,
            $sourceLinkMissing,
            $raceScoreEligible,
        );

        if (! $persist) {
            return $this->dto(
                null,
                null,
                null,
                $race,
                $entry,
                $score,
                $inputSnapshotType,
                $hash,
                $scoreObservedAt,
                "pending-race-entry-source:{$entry->id}:{$sourceFingerprint}",
                $sourceFingerprint,
                $sourceTemplate,
            );
        }

        $contentChanged = ! $currentSnapshot instanceof RaceEntrySnapshot
            || $currentSnapshot->snapshot_hash !== $hash;
        if ($contentChanged && $currentOccurrence instanceof RaceEntrySnapshotOccurrence) {
            $this->assertStateObservationIsMonotonic($entry, $currentOccurrence, $stateObservedAt);
            $currentOccurrence->forceFill([
                'is_current' => false,
                'effective_to' => $stateObservedAt,
            ])->save();
        }
        if ($contentChanged && ! $stateObservedAt instanceof DateTimeImmutable) {
            throw new RuntimeException(
                "Race entry {$entry->id} state observation time was unavailable while starting a snapshot occurrence.",
            );
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
                'external_player_id' => $entry->external_player_id,
                'bike_number' => $entry->bike_number,
                'frame_number' => $entry->frame_number,
                'grade' => $entry->grade,
                'race_score_raw_text' => $score->rawText,
                'race_score' => $score->value,
                'race_score_validation_status' => $score->status->value,
                'race_score_anomaly_status' => 'NOT_CHECKED',
                'snapshot_type' => self::SNAPSHOT_TYPE,
                'snapshot_hash' => $hash,
                'first_observed_at' => $scoreObservedAt,
                'last_observed_at' => $scoreObservedAt,
                'is_complete' => $score->status === RaceScoreValidationStatus::Valid,
                'parser_version' => null,
            ]);
        } else {
            $lastObservedAt = $snapshot->last_observed_at;
            $snapshot->forceFill([
                'last_observed_at' => $scoreObservedAt instanceof DateTimeImmutable
                    && (! $lastObservedAt instanceof DateTimeImmutable || $scoreObservedAt > $lastObservedAt)
                    ? $scoreObservedAt
                    : $lastObservedAt,
            ])->save();
        }

        $sourceIdentityKey = "race-entry-source:{$snapshot->id}:{$sourceFingerprint}";
        $snapshotSource = RaceEntrySnapshotSource::query()->firstOrCreate(
            [
                'race_entry_snapshot_id' => $snapshot->id,
                'source_role' => $sourceTemplate['source_role'],
                'source_fingerprint' => $sourceFingerprint,
            ],
            [
                'race_id' => $race->id,
                'race_entry_id' => $entry->id,
                'scraping_fetch_log_id' => $sourceTemplate['scraping_fetch_log_id'],
                'source_identity_key' => $sourceIdentityKey,
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

        $occurrence = $contentChanged
            ? RaceEntrySnapshotOccurrence::query()->create([
                'race_id' => $race->id,
                'race_entry_id' => $entry->id,
                'race_entry_snapshot_id' => $snapshot->id,
                'race_entry_snapshot_source_id' => $snapshotSource->id,
                'effective_from' => $stateObservedAt,
                'effective_to' => null,
                'is_current' => true,
                'state_observed_at' => $stateObservedAt,
            ])
            : $currentOccurrence;
        if (! $occurrence instanceof RaceEntrySnapshotOccurrence) {
            throw new RuntimeException(
                "Race entry {$entry->id} current snapshot occurrence was unavailable.",
            );
        }
        if ((int) $occurrence->race_entry_snapshot_source_id !== (int) $snapshotSource->id) {
            $occurrence->forceFill([
                'race_entry_snapshot_source_id' => $snapshotSource->id,
            ])->save();
        }

        return $this->dto(
            (int) $snapshot->id,
            (int) $occurrence->id,
            (int) $snapshotSource->id,
            $race,
            $entry,
            $score,
            $inputSnapshotType,
            $hash,
            $scoreObservedAt,
            $sourceIdentityKey,
            $snapshotSource->source_fingerprint,
            $this->sourceTemplateFromModel($snapshotSource),
        );
    }

    /**
     * @param  array<string,mixed>  $source
     */
    private function inputSnapshotType(
        array $source,
        ?DateTimeImmutable $observedAt,
        StatInputAsOfDto $inputAsOf,
    ): StatInputSnapshotType {
        if ($source['source_page_type'] === 'PLAYER_PROFILE') {
            return StatInputSnapshotType::CurrentPlayerProfile;
        }
        if ($inputAsOf->value === null || ! $observedAt instanceof DateTimeImmutable) {
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
        ?RaceEntrySnapshotSource $currentSource,
    ): array {
        if ($current instanceof RaceEntrySnapshot
            && $current->external_player_id === $entry->external_player_id
            && $currentSource instanceof RaceEntrySnapshotSource) {
            return $this->sourceTemplateFromModel($currentSource);
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
            'source_fetched_at' => null,
            'parser_version' => null,
            'source_url' => null,
            'raw_file_path' => null,
            'raw_sha256' => null,
            'context_evidence' => [
                'race_id' => (int) $race->id,
                'race_entry_id' => (int) $entry->id,
                'external_player_id' => $entry->external_player_id,
                'source_link_status' => 'SOURCE_LINK_MISSING',
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function sourceTemplateFromModel(RaceEntrySnapshotSource $source): array
    {
        $fetchLog = $source->scraping_fetch_log_id === null
            ? null
            : $source->fetchLog()->first();

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
            'source_fetched_at' => $fetchLog?->fetched_at,
            'parser_version' => $fetchLog?->parser_version,
            'source_url' => $fetchLog?->request_url,
            'raw_file_path' => $fetchLog?->raw_file_path,
            'raw_sha256' => $fetchLog?->sha256,
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
        ?int $occurrenceId,
        ?int $sourceStateId,
        Race $race,
        RaceEntry $entry,
        NormalizedRaceScoreDto $score,
        StatInputSnapshotType $inputSnapshotType,
        string $hash,
        ?DateTimeImmutable $observedAt,
        string $sourceIdentityKey,
        string $sourceFingerprint,
        array $sourceTemplate,
    ): RaceEntrySnapshotDto {
        $sourceLinkMissing = $sourceTemplate['scraping_fetch_log_id'] === null;
        $raceScoreEligible = in_array($inputSnapshotType, [
            StatInputSnapshotType::LivePreRaceCard,
            StatInputSnapshotType::HistoricalRaceCardBackfill,
        ], true) && $this->isEligibleRaceCardSource($sourceTemplate);
        return new RaceEntrySnapshotDto(
            id: $id,
            occurrenceId: $occurrenceId,
            sourceStateId: $sourceStateId,
            raceEntryId: (int) $entry->id,
            raceId: (int) $race->id,
            playerId: $entry->player_id === null ? null : (int) $entry->player_id,
            externalPlayerId: $entry->external_player_id,
            bikeNumber: (int) $entry->bike_number,
            frameNumber: $entry->frame_number === null ? null : (int) $entry->frame_number,
            grade: $entry->grade,
            raceScoreRawText: $score->rawText,
            raceScore: $score->value,
            validationStatus: $score->status,
            snapshotType: self::SNAPSHOT_TYPE,
            inputSnapshotType: $inputSnapshotType->value,
            snapshotHash: $hash,
            sourceFingerprint: $sourceFingerprint,
            observedAt: $observedAt,
            parserVersion: $sourceTemplate['parser_version'],
            sourceLinkMissing: $sourceLinkMissing,
            raceScoreEligible: $raceScoreEligible,
            scrapingFetchLogId: $sourceTemplate['scraping_fetch_log_id'],
            sourceIdentityKey: $sourceIdentityKey,
            sourcePageType: $sourceTemplate['source_page_type'],
            sourceUrl: $sourceTemplate['source_url'],
            rawFilePath: $sourceTemplate['raw_file_path'],
            rawSha256: $sourceTemplate['raw_sha256'],
        );
    }

    private function assertStateObservationIsMonotonic(
        RaceEntry $entry,
        RaceEntrySnapshotOccurrence $current,
        ?DateTimeImmutable $stateObservedAt,
    ): void {
        if (! $stateObservedAt instanceof DateTimeImmutable) {
            throw new RuntimeException(
                "Race entry {$entry->id} state observation time was unavailable while closing snapshot occurrence {$current->id}.",
            );
        }

        $boundaries = array_filter([
            $current->effective_from,
            $current->state_observed_at,
            $current->snapshot?->first_observed_at,
            $current->snapshot?->last_observed_at,
            RaceEntrySnapshotOccurrence::query()
                ->where('race_entry_id', $entry->id)
                ->where('is_current', false)
                ->whereNotNull('effective_to')
                ->orderByDesc('effective_to')
                ->value('effective_to'),
        ]);
        foreach ($boundaries as $boundary) {
            $boundary = $boundary instanceof DateTimeImmutable
                ? $boundary
                : new DateTimeImmutable((string) $boundary);
            if ($stateObservedAt < $boundary) {
                throw new RuntimeException(
                    "Race entry {$entry->id} state observation time {$stateObservedAt->format(DATE_ATOM)} "
                    ."preceded audited snapshot history {$boundary->format(DATE_ATOM)}.",
                );
            }
        }
    }
}
