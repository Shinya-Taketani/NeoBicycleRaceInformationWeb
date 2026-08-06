<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\Support;

use App\Domain\Keirin\Statistics\DTO\Batch02BuildOptionsDto;
use App\Domain\Keirin\Statistics\DTO\Batch02FeatureResultDto;
use App\Domain\Keirin\Statistics\DTO\Batch02HistoryContextDto;
use App\Domain\Keirin\Statistics\DTO\Batch02TargetEntryDto;
use App\Domain\Keirin\Statistics\DTO\HistoricalRaceDto;
use App\Domain\Keirin\Statistics\Enums\Batch02Stat;
use App\Domain\Keirin\Statistics\Enums\HistoricalResultState;
use App\Domain\Keirin\Statistics\Enums\StatisticFeatureResultStatus;
use App\Domain\Keirin\Statistics\Enums\StatisticQualityStatus;
use DateTimeImmutable;

class Batch02CalculatorSupport
{
    public function __construct(private readonly DeterministicJsonHasher $hasher) {}

    /** @param list<HistoricalRaceDto> $histories */
    public function context(
        Batch02TargetEntryDto $target,
        array $histories,
        Batch02BuildOptionsDto $options,
        string $batchExecutionUuid,
        Batch02Stat $stat,
    ): Batch02HistoryContextDto {
        $histories = array_values(array_filter(
            $histories,
            fn (HistoricalRaceDto $history): bool => $history->raceId !== $target->raceId
                && $history->scheduledStartAt < $target->inputAsOf
                && $history->scheduledStartAt >= $options->historyFrom,
        ));
        usort($histories, $this->chronological(...));

        $preMeeting = [];
        $inMeeting = [];
        $qualityReasons = [];
        if ($target->targetMeetingId === null) {
            $preMeeting = $histories;
            $qualityReasons[] = 'MEETING_CONTEXT_MISSING';
        } else {
            foreach ($histories as $history) {
                if ($history->raceMeetingId === $target->targetMeetingId) {
                    $inMeeting[] = $history;
                } else {
                    $preMeeting[] = $history;
                    if ($history->raceMeetingId === null) {
                        $qualityReasons[] = 'HISTORY_MEETING_CONTEXT_MISSING';
                    }
                }
            }
        }
        if ($inMeeting !== []) {
            $qualityReasons[] = 'IN_MEETING_RESULT_CONFIRMATION_NOT_RECONSTRUCTED';
        }
        $qualityReasons = array_values(array_unique($qualityReasons));

        $historyInputHash = $this->hasher->hash([
            'history_from' => $options->historyFrom->format('Y-m-d'),
            'histories' => array_map(fn (HistoricalRaceDto $history): array => [
                'historical_race_id' => $history->raceId,
                'historical_race_entry_id' => $history->raceEntryId,
                'scheduled_start_at' => $this->timestamp($history->scheduledStartAt),
                'race_meeting_id' => $history->raceMeetingId,
                'racetrack_id' => $history->racetrackId,
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
            ], $histories),
        ]);

        $startedCount = count(array_filter($histories, fn (HistoricalRaceDto $history): bool => $history->started()));
        $normalCount = count(array_filter($histories, fn (HistoricalRaceDto $history): bool => $history->normalFinish()));
        $abnormalCount = count(array_filter($histories, fn (HistoricalRaceDto $history): bool => $history->abnormal()));
        $didNotStartCount = count(array_filter(
            $histories,
            fn (HistoricalRaceDto $history): bool => $history->resultState === HistoricalResultState::DidNotStart,
        ));
        $sourceDates = [];
        foreach ($histories as $history) {
            $sourceDates[] = $history->raceEntryFetchedAt;
            $sourceDates[] = $history->raceResultFetchedAt;
        }
        usort($sourceDates, fn (DateTimeImmutable $left, DateTimeImmutable $right): int => $left <=> $right);

        return new Batch02HistoryContextDto(
            histories: $histories,
            preMeeting: $preMeeting,
            inMeeting: $inMeeting,
            historyInputHash: $historyInputHash,
            evidence: [
                'batch_execution_uuid' => $batchExecutionUuid,
                'stat01_run_id' => $options->stat01RunId,
                'stat01_input_hash' => $target->stat01InputHash,
                'history_from' => $options->historyFrom->format('Y-m-d'),
                'history_input_hash' => $historyInputHash,
                'history_result_mode' => 'BACKFILLED_FINAL_RESULT',
                'history_event_count' => count($histories),
                'started_history_count' => $startedCount,
                'normal_finish_history_count' => $normalCount,
                'abnormal_history_count' => $abnormalCount,
                'did_not_start_history_count' => $didNotStartCount,
                'target_input_as_of' => $this->timestamp($target->inputAsOf),
                'target_meeting_id' => $target->targetMeetingId,
                'history_min_at' => isset($histories[0]) ? $this->timestamp($histories[0]->scheduledStartAt) : null,
                'history_max_at' => $histories !== [] ? $this->timestamp($histories[array_key_last($histories)]->scheduledStartAt) : null,
                'source_max_fetched_at' => $sourceDates !== [] ? $this->timestamp($sourceDates[array_key_last($sourceDates)]) : null,
                'calculation_version' => $stat->calculationVersion(),
                'quality_reasons' => $qualityReasons,
                'unavailable_components' => [],
            ],
            qualityReasons: $qualityReasons,
        );
    }

    /**
     * @param  array<string, mixed>  $features
     * @param  list<string>  $additionalQualityReasons
     * @param  list<string>  $unavailableComponents
     */
    public function result(
        Batch02TargetEntryDto $target,
        Batch02BuildOptionsDto $options,
        Batch02HistoryContextDto $context,
        Batch02Stat $stat,
        array $features,
        bool $complete,
        array $additionalQualityReasons = [],
        array $unavailableComponents = [],
    ): Batch02FeatureResultDto {
        $qualityReasons = array_values(array_unique([
            ...$context->qualityReasons,
            ...$additionalQualityReasons,
        ]));
        if ($target->playerId === null) {
            $status = StatisticFeatureResultStatus::MissingInput;
            $qualityStatus = StatisticQualityStatus::Degraded;
            $qualityReasons[] = 'PLAYER_ID_UNRESOLVED';
        } elseif ($context->histories === []) {
            $status = StatisticFeatureResultStatus::NoHistory;
            $qualityStatus = StatisticQualityStatus::Degraded;
            $qualityReasons[] = 'NO_HISTORY_BEFORE_TARGET';
        } elseif (! $complete) {
            $status = StatisticFeatureResultStatus::PartialHistory;
            $qualityStatus = StatisticQualityStatus::Partial;
            $qualityReasons[] = 'HISTORY_WINDOW_INCOMPLETE';
        } else {
            $status = StatisticFeatureResultStatus::Valid;
            $qualityStatus = $qualityReasons === []
                ? StatisticQualityStatus::Full
                : StatisticQualityStatus::Degraded;
        }
        $qualityReasons = array_values(array_unique($qualityReasons));
        $evidence = $context->evidence;
        $evidence['quality_reasons'] = $qualityReasons;
        $evidence['unavailable_components'] = $unavailableComponents;
        $inputHash = $this->hasher->hash([
            'stat_code' => $stat->value,
            'calculation_version' => $stat->calculationVersion(),
            'stat01_input_hash' => $target->stat01InputHash,
            'history_from' => $options->historyFrom->format('Y-m-d'),
            'history_input_hash' => $context->historyInputHash,
        ]);

        return new Batch02FeatureResultDto(
            target: $target,
            status: $status,
            qualityStatus: $qualityStatus,
            features: $features,
            evidence: $evidence,
            inputHash: $inputHash,
        );
    }

    /**
     * @param  list<HistoricalRaceDto>  $events
     * @return array{events: list<HistoricalRaceDto>, metadata: array<string, mixed>}
     */
    public function countWindow(array $events, int $requested): array
    {
        $window = array_slice($events, -$requested);

        return [
            'events' => $window,
            'metadata' => $this->windowMetadata($window, count($window) >= $requested),
        ];
    }

    /**
     * @param  list<HistoricalRaceDto>  $events
     * @return array{events: list<HistoricalRaceDto>, metadata: array<string, mixed>}
     */
    public function dayWindow(
        array $events,
        Batch02TargetEntryDto $target,
        DateTimeImmutable $historyFrom,
        int $days,
    ): array {
        $windowStart = $target->inputAsOf->modify("-{$days} days");
        $window = array_values(array_filter(
            $events,
            fn (HistoricalRaceDto $history): bool => $history->scheduledStartAt >= $windowStart,
        ));

        return [
            'events' => $window,
            'metadata' => [
                ...$this->windowMetadata($window, $windowStart >= $historyFrom),
                'window_start_at' => $this->timestamp($windowStart),
            ],
        ];
    }

    /** @param list<HistoricalRaceDto> $events */
    private function windowMetadata(array $events, bool $complete): array
    {
        return [
            'sample_count' => count($events),
            'history_start_at' => isset($events[0]) ? $this->timestamp($events[0]->scheduledStartAt) : null,
            'history_end_at' => $events !== [] ? $this->timestamp($events[array_key_last($events)]->scheduledStartAt) : null,
            'window_complete' => $complete,
        ];
    }

    private function chronological(HistoricalRaceDto $left, HistoricalRaceDto $right): int
    {
        return [$left->scheduledStartAt->format('U.u'), $left->raceId, $left->raceEntryId]
            <=> [$right->scheduledStartAt->format('U.u'), $right->raceId, $right->raceEntryId];
    }

    private function timestamp(DateTimeImmutable $value): string
    {
        return $value->format('Y-m-d\TH:i:s.uP');
    }
}
