<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\Support;

use App\Domain\Keirin\Statistics\DTO\Batch03BuildOptionsDto;
use App\Domain\Keirin\Statistics\DTO\Batch03FeatureResultDto;
use App\Domain\Keirin\Statistics\DTO\Batch03HistoricalRaceDto;
use App\Domain\Keirin\Statistics\DTO\Batch03HistoryContextDto;
use App\Domain\Keirin\Statistics\DTO\Batch03TargetEntryDto;
use App\Domain\Keirin\Statistics\Enums\Batch03Stat;
use App\Domain\Keirin\Statistics\Enums\StatisticFeatureResultStatus;
use App\Domain\Keirin\Statistics\Enums\StatisticQualityStatus;
use DateTimeImmutable;
use DateTimeZone;

class Batch03CalculatorSupport
{
    private const TIMEZONE = 'Asia/Tokyo';

    public function __construct(
        private readonly DeterministicJsonHasher $hasher,
        private readonly StatisticalMath $math,
    ) {}

    /** @param list<Batch03HistoricalRaceDto> $histories */
    public function context(
        Batch03TargetEntryDto $target,
        array $histories,
        Batch03BuildOptionsDto $options,
        string $batchExecutionUuid,
        Batch03Stat $stat,
    ): Batch03HistoryContextDto {
        $histories = array_values(array_filter(
            $histories,
            fn (Batch03HistoricalRaceDto $history): bool => $history->raceId !== $target->raceId
                && $history->scheduledStartAt < $target->inputAsOf
                && $history->scheduledStartAt >= $options->historyFrom,
        ));
        usort($histories, $this->chronological(...));
        $targetContextHash = $this->hasher->hash([
            'target_race_id' => $target->raceId,
            'target_race_entry_id' => $target->raceEntryId,
            'target_player_id' => $target->playerId,
            'target_bike_number' => $target->bikeNumber,
            'target_input_as_of' => $this->timestamp($target->inputAsOf),
            'target_scheduled_start_at' => $this->timestamp($target->scheduledStartAt),
            'target_racetrack_id' => $target->racetrackId,
            'target_race_meeting_id' => $target->raceMeetingId,
            'target_day_number' => $target->dayNumber,
            'target_meeting_duration_days' => $target->meetingDurationDays,
            'target_meeting_grade' => $target->meetingGrade,
            'target_meeting_day_kind' => $target->meetingDayKind,
            'target_raw_race_type' => $target->rawRaceType,
            'target_raw_race_name' => $target->rawRaceName,
            'target_normalized_stage' => $target->normalizedStage->value,
            'stage_normalizer_version' => RaceStageNormalizer::VERSION,
            'stat01_input_hash' => $target->stat01InputHash,
        ]);
        $historyInputHash = $this->hasher->hash([
            'history_from' => $options->historyFrom->format('Y-m-d'),
            'histories' => array_map(fn (Batch03HistoricalRaceDto $history): array => [
                'historical_race_id' => $history->raceId,
                'historical_race_entry_id' => $history->raceEntryId,
                'scheduled_start_at' => $this->timestamp($history->scheduledStartAt),
                'racetrack_id' => $history->racetrackId,
                'race_meeting_id' => $history->raceMeetingId,
                'day_number' => $history->dayNumber,
                'meeting_duration_days' => $history->meetingDurationDays,
                'meeting_grade' => $history->meetingGrade,
                'meeting_day_kind' => $history->meetingDayKind,
                'raw_race_type' => $history->rawRaceType,
                'raw_race_name' => $history->rawRaceName,
                'normalized_stage' => $history->normalizedStage->value,
                'entrant_count' => $history->entrantCount,
                'normalized_result_state' => $history->resultState->value,
                'tied' => $history->tied,
                'rank' => $history->rank,
                'historical_race_score' => $history->raceScore,
                'finish_strength_percentile' => $history->finishStrengthPercentile,
                'score_expectation_residual' => $history->scoreExpectationResidual,
                'historical_score_context_hash' => $history->historicalScoreContextHash,
                'race_entry_fetched_at' => $this->timestamp($history->raceEntryFetchedAt),
                'race_result_fetched_at' => $this->timestamp($history->raceResultFetchedAt),
                'result_confirmed_at' => $this->timestamp($history->resultConfirmedAt),
                'stage_normalizer_version' => RaceStageNormalizer::VERSION,
            ], $histories),
        ]);
        $sourceDates = [];
        foreach ($histories as $history) {
            $sourceDates[] = $history->raceEntryFetchedAt;
            $sourceDates[] = $history->raceResultFetchedAt;
        }
        usort($sourceDates, fn (DateTimeImmutable $left, DateTimeImmutable $right): int => $left <=> $right);

        return new Batch03HistoryContextDto(
            histories: $histories,
            targetContextHash: $targetContextHash,
            historyInputHash: $historyInputHash,
            evidence: [
                'batch_execution_uuid' => $batchExecutionUuid,
                'stat01_run_id' => $options->stat01RunId,
                'stat01_input_hash' => $target->stat01InputHash,
                'target_context_hash' => $targetContextHash,
                'history_input_hash' => $historyInputHash,
                'history_from' => $options->historyFrom->format('Y-m-d'),
                'history_result_mode' => 'BACKFILLED_FINAL_RESULT',
                'history_event_count' => count($histories),
                'history_app_confirmed_observation_known_count' => count(array_filter(
                    $histories,
                    fn (Batch03HistoricalRaceDto $history): bool => $history->resultConfirmedAt !== null,
                )),
                'history_app_confirmed_observation_unknown_count' => count(array_filter(
                    $histories,
                    fn (Batch03HistoricalRaceDto $history): bool => $history->resultConfirmedAt === null,
                )),
                'target_input_as_of' => $this->timestamp($target->inputAsOf),
                'target_scheduled_start_at' => $this->timestamp($target->scheduledStartAt),
                'target_normalized_stage' => $target->normalizedStage->value,
                'stage_normalizer_version' => RaceStageNormalizer::VERSION,
                'history_min_at' => isset($histories[0]) ? $this->timestamp($histories[0]->scheduledStartAt) : null,
                'history_max_at' => $histories !== [] ? $this->timestamp($histories[array_key_last($histories)]->scheduledStartAt) : null,
                'source_max_fetched_at' => $sourceDates !== [] ? $this->timestamp($sourceDates[array_key_last($sourceDates)]) : null,
                'calculation_version' => $stat->calculationVersion(),
                'quality_reasons' => [],
                'unavailable_components' => [],
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $features
     * @param  list<string>  $qualityReasons
     * @param  list<string>  $unavailableComponents
     * @param  array<string, mixed>  $additionalEvidence
     */
    public function result(
        Batch03TargetEntryDto $target,
        Batch03BuildOptionsDto $options,
        Batch03HistoryContextDto $context,
        Batch03Stat $stat,
        array $features,
        StatisticFeatureResultStatus $status,
        array $qualityReasons = [],
        array $unavailableComponents = [],
        ?string $statusReason = null,
        array $additionalEvidence = [],
    ): Batch03FeatureResultDto {
        $qualityReasons = array_values(array_unique($qualityReasons));
        $qualityStatus = match ($status) {
            StatisticFeatureResultStatus::Partial, StatisticFeatureResultStatus::PartialHistory => StatisticQualityStatus::Partial,
            StatisticFeatureResultStatus::Valid, StatisticFeatureResultStatus::NotApplicable => $qualityReasons === []
                ? StatisticQualityStatus::Full
                : StatisticQualityStatus::Degraded,
            default => StatisticQualityStatus::Degraded,
        };
        $evidence = array_replace($context->evidence, $additionalEvidence);
        $evidence['quality_reasons'] = $qualityReasons;
        $evidence['unavailable_components'] = array_values(array_unique($unavailableComponents));
        $evidence['status_reason'] = $statusReason;
        $inputHash = $this->hasher->hash([
            'stat_code' => $stat->value,
            'calculation_version' => $stat->calculationVersion(),
            'target_context_hash' => $context->targetContextHash,
            'history_from' => $options->historyFrom->format('Y-m-d'),
            'history_input_hash' => $context->historyInputHash,
        ]);

        return new Batch03FeatureResultDto($target, $status, $qualityStatus, $features, $evidence, $inputHash);
    }

    /** @param list<Batch03HistoricalRaceDto> $events */
    public function performance(array $events): array
    {
        $events = array_values(array_filter($events, fn (Batch03HistoricalRaceDto $event): bool => $event->normalFinish()));
        $count = count($events);
        $ranks = array_values(array_filter(array_map(fn (Batch03HistoricalRaceDto $event): ?int => $event->rank, $events), fn (?int $rank): bool => $rank !== null));
        $percentiles = array_values(array_filter(array_map(fn (Batch03HistoricalRaceDto $event): ?float => $event->finishStrengthPercentile, $events), fn (?float $value): bool => $value !== null));
        $residuals = array_values(array_filter(array_map(fn (Batch03HistoricalRaceDto $event): ?float => $event->scoreExpectationResidual, $events), fn (?float $value): bool => $value !== null));
        $wins = count(array_filter($events, fn (Batch03HistoricalRaceDto $event): bool => $event->rank === 1));
        $top2 = count(array_filter($events, fn (Batch03HistoricalRaceDto $event): bool => $event->rank !== null && $event->rank <= 2));
        $top3 = count(array_filter($events, fn (Batch03HistoricalRaceDto $event): bool => $event->rank !== null && $event->rank <= 3));

        return [
            'sample_count' => $count,
            'win_count' => $wins,
            'win_rate' => $count > 0 ? $wins / $count : null,
            'top2_count' => $top2,
            'top2_rate' => $count > 0 ? $top2 / $count : null,
            'top3_count' => $top3,
            'top3_rate' => $count > 0 ? $top3 / $count : null,
            'mean_rank' => $this->math->mean($ranks),
            'mean_finish_strength_percentile' => $this->math->mean($percentiles),
            'residual_sample_count' => count($residuals),
            'mean_score_expectation_residual' => $this->math->mean($residuals),
        ];
    }

    /** @param list<Batch03HistoricalRaceDto> $events */
    public function window(array $events, Batch03TargetEntryDto $target, DateTimeImmutable $historyFrom, int $days): array
    {
        $start = $target->inputAsOf->modify("-{$days} days");
        $events = array_values(array_filter($events, fn (Batch03HistoricalRaceDto $event): bool => $event->scheduledStartAt >= $start));

        return [
            'window_start_at' => $this->timestamp($start),
            'history_start_at' => isset($events[0]) ? $this->timestamp($events[0]->scheduledStartAt) : null,
            'history_end_at' => $events !== [] ? $this->timestamp($events[array_key_last($events)]->scheduledStartAt) : null,
            'window_complete' => $start >= $historyFrom,
            ...$this->performance($events),
        ];
    }

    /** @param array<string, mixed> $matching @param array<string, mixed> $baseline */
    public function delta(array $matching, array $baseline): array
    {
        return [
            'win_rate' => $this->difference($matching['win_rate'], $baseline['win_rate']),
            'top2_rate' => $this->difference($matching['top2_rate'], $baseline['top2_rate']),
            'top3_rate' => $this->difference($matching['top3_rate'], $baseline['top3_rate']),
            'mean_finish_strength_percentile' => $this->difference($matching['mean_finish_strength_percentile'], $baseline['mean_finish_strength_percentile']),
            'mean_score_expectation_residual' => $this->difference($matching['mean_score_expectation_residual'], $baseline['mean_score_expectation_residual']),
        ];
    }

    public function local(DateTimeImmutable $date): DateTimeImmutable
    {
        return $date->setTimezone(new DateTimeZone(self::TIMEZONE));
    }

    public function timestamp(?DateTimeImmutable $date): ?string
    {
        return $date?->format('Y-m-d\TH:i:s.uP');
    }

    private function chronological(Batch03HistoricalRaceDto $left, Batch03HistoricalRaceDto $right): int
    {
        return [$left->scheduledStartAt, $left->raceId, $left->raceEntryId]
            <=> [$right->scheduledStartAt, $right->raceId, $right->raceEntryId];
    }

    private function difference(?float $left, ?float $right): ?float
    {
        return $left !== null && $right !== null ? $left - $right : null;
    }
}
