<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\Calculators;

use App\Domain\Keirin\Statistics\Contracts\Batch04Calculator;
use App\Domain\Keirin\Statistics\DTO\Batch04BuildOptionsDto;
use App\Domain\Keirin\Statistics\DTO\Batch04FeatureResultDto;
use App\Domain\Keirin\Statistics\DTO\Batch04HeadToHeadEventDto;
use App\Domain\Keirin\Statistics\DTO\Batch04PositionHistoryContextDto;
use App\Domain\Keirin\Statistics\DTO\Batch04RaceInputDto;
use App\Domain\Keirin\Statistics\DTO\Batch04TargetEntryDto;
use App\Domain\Keirin\Statistics\Enums\Batch04Stat;
use App\Domain\Keirin\Statistics\Enums\HistoricalResultState;
use App\Domain\Keirin\Statistics\Enums\StatisticFeatureResultStatus;
use App\Domain\Keirin\Statistics\Support\Batch04CalculatorSupport;

class Stat42Calculator implements Batch04Calculator
{
    public function __construct(private readonly Batch04CalculatorSupport $support) {}

    public function stat(): Batch04Stat
    {
        return Batch04Stat::Stat42;
    }

    public function calculate(
        Batch04TargetEntryDto $target,
        Batch04RaceInputDto $race,
        Batch04PositionHistoryContextDto $positionHistory,
        array $pairHistories,
        Batch04BuildOptionsDto $options,
        string $batchExecutionUuid,
    ): Batch04FeatureResultDto {
        $coentrants = array_values(array_filter(
            $race->entries,
            fn (Batch04TargetEntryDto $entry): bool => $entry->raceEntryId !== $target->raceEntryId,
        ));
        usort($coentrants, fn (Batch04TargetEntryDto $left, Batch04TargetEntryDto $right): int => [
            $left->bikeNumber ?? PHP_INT_MAX,
            $left->raceEntryId,
        ] <=> [
            $right->bikeNumber ?? PHP_INT_MAX,
            $right->raceEntryId,
        ]);
        $resolved = array_values(array_filter($coentrants, fn (Batch04TargetEntryDto $entry): bool => $entry->playerId !== null));
        $unresolvedCount = count($coentrants) - count($resolved);
        $playerCounts = [];
        foreach ($race->entries as $entry) {
            if ($entry->playerId !== null) {
                $playerCounts[$entry->playerId] = ($playerCounts[$entry->playerId] ?? 0) + 1;
            }
        }
        $identityConflict = count(array_filter($playerCounts, fn (int $count): bool => $count > 1)) > 0;
        $pairDetails = [];
        $directRaceIds = [];
        $sourceMaxFetchedAt = null;
        foreach ($resolved as $opponent) {
            if ($target->playerId === null || $opponent->playerId === $target->playerId) {
                continue;
            }
            $pairKey = $this->pairKey($target->playerId, $opponent->playerId);
            $events = array_values(array_filter(
                $pairHistories[$pairKey] ?? [],
                fn (Batch04HeadToHeadEventDto $event): bool => $event->scheduledStartAt < $target->inputAsOf
                    && $event->raceId !== $target->raceId,
            ));
            usort($events, fn (Batch04HeadToHeadEventDto $left, Batch04HeadToHeadEventDto $right): int => [
                $left->scheduledStartAt,
                $left->raceId,
                $left->firstRaceEntryId,
                $left->secondRaceEntryId,
            ] <=> [
                $right->scheduledStartAt,
                $right->raceId,
                $right->firstRaceEntryId,
                $right->secondRaceEntryId,
            ]);
            $detail = $this->pairMetrics($target->playerId, $opponent, $events);
            $pairDetails[] = $detail;
            foreach ($detail['direct_source_race_ids'] as $raceId) {
                $directRaceIds[$raceId] = true;
            }
            $sourceMaxFetchedAt = max($sourceMaxFetchedAt ?? '', (string) ($detail['source_max_fetched_at'] ?? ''));
        }
        $withDirect = count(array_filter($pairDetails, fn (array $detail): bool => $detail['DIRECT_HISTORY']['direct_meeting_count'] > 0));
        $withNormal = count(array_filter($pairDetails, fn (array $detail): bool => $detail['DIRECT_HISTORY']['normal_direct_meeting_count'] > 0));
        $onlyAbnormal = count(array_filter($pairDetails, fn (array $detail): bool => $detail['DIRECT_HISTORY']['direct_meeting_count'] > 0
            && $detail['DIRECT_HISTORY']['normal_direct_meeting_count'] === 0));
        $sumDirect = array_sum(array_map(fn (array $detail): int => $detail['DIRECT_HISTORY']['direct_meeting_count'], $pairDetails));
        $sumNormal = array_sum(array_map(fn (array $detail): int => $detail['DIRECT_HISTORY']['normal_direct_meeting_count'], $pairDetails));
        $sumResidual = array_sum(array_map(fn (array $detail): int => $detail['DIRECT_HISTORY']['relative_expectation_residual_sample_count'], $pairDetails));
        $status = match (true) {
            $target->playerId === null => StatisticFeatureResultStatus::MissingInput,
            $identityConflict => StatisticFeatureResultStatus::InvalidInput,
            $resolved === [] => StatisticFeatureResultStatus::MissingInput,
            $withDirect === 0 => StatisticFeatureResultStatus::NoHistory,
            $sumNormal === 0 => StatisticFeatureResultStatus::Partial,
            default => StatisticFeatureResultStatus::Valid,
        };
        $statusReason = match (true) {
            $target->playerId === null => 'PLAYER_ID_UNRESOLVED',
            $identityConflict => 'PLAYER_ID_CONFLICT',
            $resolved === [] => 'NO_RESOLVED_CURRENT_COENTRANT',
            $withDirect === 0 => 'NO_HEAD_TO_HEAD_HISTORY',
            $sumNormal === 0 => 'NO_NORMAL_HEAD_TO_HEAD',
            default => null,
        };
        $qualityReasons = [];
        if ($unresolvedCount > 0) {
            $qualityReasons[] = 'UNRESOLVED_CURRENT_COENTRANTS';
        }
        if ($sumResidual < $sumNormal) {
            $qualityReasons[] = 'MISSING_HISTORICAL_SCORE_CONTEXT_FOR_RESIDUAL';
        }
        $historyHashes = array_map(fn (array $detail): array => [
            'pair_key' => $detail['pair_key'],
            'pair_history_event_count' => $detail['pair_history_event_count'],
            'pair_history_input_hash' => $detail['DIRECT_HISTORY']['pair_history_input_hash'],
        ], $pairDetails);
        $historyInputHash = $this->support->hash(['pair_histories' => $historyHashes]);
        $targetContextHash = $this->support->targetContextHash($target, $race, true);
        $publicPairDetails = array_map(function (array $detail): array {
            unset($detail['direct_source_race_ids'], $detail['source_max_fetched_at'], $detail['pair_history_event_count']);

            return $detail;
        }, $pairDetails);

        return $this->support->result(
            target: $target,
            options: $options,
            stat: $this->stat(),
            targetContextHash: $targetContextHash,
            historyInputHash: $historyInputHash,
            features: [
                'CURRENT_FIELD_CONTEXT' => [
                    'coentrant_count' => count($coentrants),
                    'resolved_coentrant_count' => count($resolved),
                    'unresolved_coentrant_count' => $unresolvedCount,
                    'relation_scope' => 'ALL_CURRENT_COENTRANTS',
                    'coentrants' => array_map(fn (Batch04TargetEntryDto $entry): array => [
                        'race_entry_id' => $entry->raceEntryId,
                        'player_id' => $entry->playerId,
                        'bike_number' => $entry->bikeNumber,
                        'frame_number' => $entry->frameNumber,
                        'stat01_input_hash' => $entry->stat01InputHash,
                        'stat01_race_score' => $entry->stat01RaceScore,
                        'stat01_rank' => $entry->stat01Rank,
                        'stat01_strength_percentile' => $entry->stat01StrengthPercentile,
                    ], $coentrants),
                ],
                'HEAD_TO_HEAD_BY_COENTRANT' => $publicPairDetails,
                'HEAD_TO_HEAD_SUMMARY' => [
                    'coentrant_count' => count($coentrants),
                    'opponents_with_direct_history_count' => $withDirect,
                    'opponents_without_direct_history_count' => count($resolved) - $withDirect,
                    'opponents_with_normal_history_count' => $withNormal,
                    'opponents_only_abnormal_history_count' => $onlyAbnormal,
                    'sum_pair_direct_meeting_count' => $sumDirect,
                    'unique_direct_source_race_count' => count($directRaceIds),
                ],
                'MATCHUP_ADJUSTMENT' => null,
            ],
            evidence: [
                'batch_execution_uuid' => $batchExecutionUuid,
                'stat01_run_id' => $options->stat01RunId,
                'stat01_input_hash' => $target->stat01InputHash,
                'relation_scope' => 'ALL_CURRENT_COENTRANTS',
                'pair_history_buckets' => $historyHashes,
                'source_max_fetched_at' => $sourceMaxFetchedAt !== '' ? $sourceMaxFetchedAt : null,
                'same_race_pair_dependency' => 'PAIR_SUM_AND_UNIQUE_SOURCE_RACE_COUNT_REPORTED_SEPARATELY',
                'transitivity' => 'NOT_APPLIED',
            ],
            status: $status,
            qualityReasons: $qualityReasons,
            unavailableComponents: [
                'CURRENT_LINE_RELATION',
                'HISTORICAL_LINE_RELATION',
                'HISTORICAL_ROLE_CONTEXT',
                'MAJOR_OPPONENT_SELECTION_POLICY',
                'EXPECTED_AHEAD_PROBABILITY_MODEL',
                'TIME_DECAY_POLICY',
                'CONDITION_SIMILARITY_MODEL',
                'STAT40_LINE_STRENGTH',
                'STAT14_COMPETITION_CONTEXT',
                'TRACK_STRUCTURE',
                'RACE_DISTANCE',
                'TACTIC_CONTEXT',
            ],
            statusReason: $statusReason,
        );
    }

    /**
     * @param  list<Batch04HeadToHeadEventDto>  $events
     * @return array<string, mixed>
     */
    private function pairMetrics(int $subjectPlayerId, Batch04TargetEntryDto $opponent, array $events): array
    {
        $directCount = $normalCount = $subjectAhead = $opponentAhead = $tied = 0;
        $rankDifferences = [];
        $finishDifferences = [];
        $relativeResiduals = [];
        $directRaceIds = [];
        $lastDirectAt = null;
        $sourceMaxFetchedAt = null;
        $observedBefore = $observedAfter = $observationUnknown = 0;
        $eventHashes = [];
        foreach ($events as $event) {
            $eventHashes[] = $this->support->pairEventHash($event);
            if ($event->resultConfirmedAt === null) {
                $observationUnknown++;
            } elseif ($event->resultConfirmedAt <= $opponent->inputAsOf) {
                $observedBefore++;
            } else {
                $observedAfter++;
            }
            $sourceMaxFetchedAt = max(
                $sourceMaxFetchedAt ?? '',
                $this->support->timestamp($event->firstRaceResultFetchedAt) ?? '',
                $this->support->timestamp($event->secondRaceResultFetchedAt) ?? '',
            );
            $subjectIsFirst = $event->firstPlayerId === $subjectPlayerId;
            $subjectState = $subjectIsFirst ? $event->firstResultState : $event->secondResultState;
            $opponentState = $subjectIsFirst ? $event->secondResultState : $event->firstResultState;
            if (! $subjectState->started() || ! $opponentState->started()) {
                continue;
            }
            $directCount++;
            $directRaceIds[$event->raceId] = true;
            $lastDirectAt = $event->scheduledStartAt;
            if ($subjectState !== HistoricalResultState::NormalFinish || $opponentState !== HistoricalResultState::NormalFinish) {
                continue;
            }
            $normalCount++;
            $subjectRank = $subjectIsFirst ? $event->firstRank : $event->secondRank;
            $opponentRank = $subjectIsFirst ? $event->secondRank : $event->firstRank;
            $subjectFinish = $subjectIsFirst ? $event->firstFinishPercentile : $event->secondFinishPercentile;
            $opponentFinish = $subjectIsFirst ? $event->secondFinishPercentile : $event->firstFinishPercentile;
            $subjectScore = $subjectIsFirst ? $event->firstScorePercentile : $event->secondScorePercentile;
            $opponentScore = $subjectIsFirst ? $event->secondScorePercentile : $event->firstScorePercentile;
            if ($subjectRank !== null && $opponentRank !== null) {
                $difference = $opponentRank - $subjectRank;
                $rankDifferences[] = $difference;
                if ($difference > 0) {
                    $subjectAhead++;
                } elseif ($difference < 0) {
                    $opponentAhead++;
                } else {
                    $tied++;
                }
            }
            if ($subjectFinish !== null && $opponentFinish !== null) {
                $finishDifference = $subjectFinish - $opponentFinish;
                $finishDifferences[] = $finishDifference;
                if ($subjectScore !== null && $opponentScore !== null) {
                    $relativeResiduals[] = $finishDifference - ($subjectScore - $opponentScore);
                }
            }
        }
        $pairKey = $this->pairKey($subjectPlayerId, (int) $opponent->playerId);

        return [
            'pair_key' => $pairKey,
            'subject_player_id' => $subjectPlayerId,
            'opponent_player_id' => $opponent->playerId,
            'opponent_race_entry_id' => $opponent->raceEntryId,
            'opponent_bike_number' => $opponent->bikeNumber,
            'opponent_frame_number' => $opponent->frameNumber,
            'pair_history_event_count' => count($events),
            'DIRECT_HISTORY' => [
                'direct_meeting_count' => $directCount,
                'normal_direct_meeting_count' => $normalCount,
                'abnormal_direct_meeting_count' => $directCount - $normalCount,
                'subject_ahead_count' => $subjectAhead,
                'opponent_ahead_count' => $opponentAhead,
                'tied_count' => $tied,
                'subject_ahead_rate' => $normalCount > 0 ? $subjectAhead / $normalCount : null,
                'opponent_ahead_rate' => $normalCount > 0 ? $opponentAhead / $normalCount : null,
                'tied_rate' => $normalCount > 0 ? $tied / $normalCount : null,
                'mean_relative_rank_difference' => $this->support->mean($rankDifferences),
                'mean_finish_strength_percentile_difference' => $this->support->mean($finishDifferences),
                'relative_expectation_residual_sample_count' => count($relativeResiduals),
                'mean_relative_expectation_residual' => $this->support->mean($relativeResiduals),
                'last_direct_meeting_at' => $this->support->timestamp($lastDirectAt),
                'pair_history_input_hash' => $this->support->hash(['pair_key' => $pairKey, 'event_hashes' => $eventHashes]),
                'app_observed_before_input_event_count' => $observedBefore,
                'app_observed_after_input_event_count' => $observedAfter,
                'app_observation_unknown_event_count' => $observationUnknown,
            ],
            'direct_source_race_ids' => array_map('intval', array_keys($directRaceIds)),
            'source_max_fetched_at' => $sourceMaxFetchedAt,
        ];
    }

    private function pairKey(int $firstPlayerId, int $secondPlayerId): string
    {
        return min($firstPlayerId, $secondPlayerId).':'.max($firstPlayerId, $secondPlayerId);
    }
}
