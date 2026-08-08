<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\Calculators;

use App\Domain\Keirin\Statistics\Contracts\Batch03Calculator;
use App\Domain\Keirin\Statistics\DTO\Batch03BuildOptionsDto;
use App\Domain\Keirin\Statistics\DTO\Batch03FeatureResultDto;
use App\Domain\Keirin\Statistics\DTO\Batch03HistoricalRaceDto;
use App\Domain\Keirin\Statistics\DTO\Batch03TargetEntryDto;
use App\Domain\Keirin\Statistics\Enums\Batch03Stat;
use App\Domain\Keirin\Statistics\Enums\RaceStage;
use App\Domain\Keirin\Statistics\Enums\StatisticFeatureResultStatus;
use App\Domain\Keirin\Statistics\Support\Batch03CalculatorSupport;
use App\Domain\Keirin\Statistics\Support\StatisticalMath;

class Stat33Calculator implements Batch03Calculator
{
    public function __construct(
        private readonly Batch03CalculatorSupport $support,
        private readonly StatisticalMath $math,
    ) {}

    public function stat(): Batch03Stat
    {
        return Batch03Stat::Stat33;
    }

    public function calculate(Batch03TargetEntryDto $target, array $histories, Batch03BuildOptionsDto $options, string $batchExecutionUuid): Batch03FeatureResultDto
    {
        $context = $this->support->context($target, $histories, $options, $batchExecutionUuid, $this->stat());
        $currentMeeting = $target->raceMeetingId !== null ? array_values(array_filter(
            $context->histories,
            fn (Batch03HistoricalRaceDto $event): bool => $event->raceMeetingId === $target->raceMeetingId && $event->started(),
        )) : [];
        $previous = $currentMeeting !== [] ? $currentMeeting[array_key_last($currentMeeting)] : null;
        $matching = [];
        if ($previous instanceof Batch03HistoricalRaceDto) {
            foreach ($this->pastMeetingTransitions($context->histories, $target->raceMeetingId) as $transition) {
                if ($transition['previous']->normalizedStage === $previous->normalizedStage
                    && $transition['next']->normalizedStage === $target->normalizedStage) {
                    $matching[] = $transition;
                }
            }
        }
        $status = match (true) {
            $target->playerId === null, $target->raceMeetingId === null, $target->normalizedStage === RaceStage::Unknown => StatisticFeatureResultStatus::MissingInput,
            ! $previous instanceof Batch03HistoricalRaceDto => StatisticFeatureResultStatus::NotApplicable,
            ! $previous->normalFinish() => StatisticFeatureResultStatus::Partial,
            $previous->normalizedStage === RaceStage::Unknown => StatisticFeatureResultStatus::MissingInput,
            $matching === [] => StatisticFeatureResultStatus::NoHistory,
            default => StatisticFeatureResultStatus::Valid,
        };
        $reasons = match (true) {
            $target->playerId === null => ['PLAYER_ID_UNRESOLVED'],
            $target->raceMeetingId === null => ['TARGET_MEETING_MISSING'],
            $target->normalizedStage === RaceStage::Unknown => ['UNKNOWN_CURRENT_STAGE'],
            ! $previous instanceof Batch03HistoricalRaceDto => [],
            ! $previous->normalFinish() => ['ABNORMAL_PREVIOUS_RESULT'],
            $previous->normalizedStage === RaceStage::Unknown => ['UNKNOWN_PREVIOUS_STAGE'],
            $matching === [] => ['NO_OBSERVED_TRANSITION_HISTORY'],
            default => [],
        };
        $nextEvents = array_map(fn (array $transition): Batch03HistoricalRaceDto => $transition['next'], $matching);
        $exactRank = $previous?->rank !== null ? array_values(array_filter(
            $matching,
            fn (array $transition): bool => $transition['previous']->rank === $previous->rank,
        )) : [];

        return $this->support->result(
            $target,
            $options,
            $context,
            $this->stat(),
            [
                'CURRENT_MEETING_CONTEXT' => [
                    'previous_race_id' => $previous?->raceId,
                    'previous_race_entry_id' => $previous?->raceEntryId,
                    'previous_day_number' => $previous?->dayNumber,
                    'previous_stage' => $previous?->normalizedStage->value,
                    'previous_rank' => $previous?->rank,
                    'previous_finish_percentile' => $previous?->finishStrengthPercentile,
                    'previous_result_state' => $previous?->resultState->value,
                    'current_stage' => $target->normalizedStage->value,
                ],
                'MATCHING_TRANSITION_HISTORY' => [
                    ...$this->transitionMetrics($matching),
                    'NEXT_PERFORMANCE' => $this->support->performance($nextEvents),
                ],
                'PREVIOUS_EXACT_RANK_HISTORY' => [
                    'previous_rank' => $previous?->rank,
                    ...$this->transitionMetrics($exactRank),
                ],
            ],
            $status,
            $reasons,
            ['ADVANCEMENT_RULE', 'QUALIFICATION_CUTOFF', 'POINTS_RULE', 'TIEBREAK_RULE', 'SUPPLEMENTAL_ENTRY_CLASSIFICATION'],
            statusReason: $status === StatisticFeatureResultStatus::NotApplicable ? 'MEETING_FIRST_START' : null,
        );
    }

    /**
     * @param  list<Batch03HistoricalRaceDto>  $events
     * @return list<array{previous:Batch03HistoricalRaceDto,next:Batch03HistoricalRaceDto}>
     */
    private function pastMeetingTransitions(array $events, ?int $targetMeetingId): array
    {
        $byMeeting = [];
        foreach ($events as $event) {
            if ($event->raceMeetingId === null || $event->raceMeetingId === $targetMeetingId || ! $event->started()) {
                continue;
            }
            $byMeeting[$event->raceMeetingId][] = $event;
        }
        $transitions = [];
        foreach ($byMeeting as $meetingEvents) {
            usort($meetingEvents, fn (Batch03HistoricalRaceDto $left, Batch03HistoricalRaceDto $right): int => [$left->scheduledStartAt, $left->raceId] <=> [$right->scheduledStartAt, $right->raceId]);
            for ($index = 1; $index < count($meetingEvents); $index++) {
                $previous = $meetingEvents[$index - 1];
                $next = $meetingEvents[$index];
                if ($previous->normalFinish() && $next->normalFinish()) {
                    $transitions[] = ['previous' => $previous, 'next' => $next];
                }
            }
        }

        return $transitions;
    }

    /** @param list<array{previous:Batch03HistoricalRaceDto,next:Batch03HistoricalRaceDto}> $transitions */
    private function transitionMetrics(array $transitions): array
    {
        $count = count($transitions);
        $rankChanges = [];
        $improved = $worsened = $same = $previousTop3 = $previousTop3ToTop3 = $previousOutside = $previousOutsideToTop3 = 0;
        $nextResiduals = [];
        foreach ($transitions as $transition) {
            $previousRank = $transition['previous']->rank;
            $nextRank = $transition['next']->rank;
            if ($previousRank !== null && $nextRank !== null) {
                $change = $previousRank - $nextRank;
                $rankChanges[] = $change;
                $change > 0 ? $improved++ : ($change < 0 ? $worsened++ : $same++);
                if ($previousRank <= 3) {
                    $previousTop3++;
                    $previousTop3ToTop3 += $nextRank <= 3 ? 1 : 0;
                } else {
                    $previousOutside++;
                    $previousOutsideToTop3 += $nextRank <= 3 ? 1 : 0;
                }
            }
            if ($transition['next']->scoreExpectationResidual !== null) {
                $nextResiduals[] = $transition['next']->scoreExpectationResidual;
            }
        }

        return [
            'transition_sample_count' => $count,
            'mean_rank_change' => $this->math->mean($rankChanges),
            'improved_rank_count' => $improved,
            'improved_rank_rate' => $count > 0 ? $improved / $count : null,
            'worsened_rank_count' => $worsened,
            'worsened_rank_rate' => $count > 0 ? $worsened / $count : null,
            'same_rank_count' => $same,
            'same_rank_rate' => $count > 0 ? $same / $count : null,
            'mean_next_residual' => $this->math->mean($nextResiduals),
            'previous_top3_count' => $previousTop3,
            'previous_top3_to_next_top3_count' => $previousTop3ToTop3,
            'previous_top3_to_next_top3_rate' => $previousTop3 > 0 ? $previousTop3ToTop3 / $previousTop3 : null,
            'previous_outside_top3_count' => $previousOutside,
            'previous_outside_to_next_top3_count' => $previousOutsideToTop3,
            'previous_outside_to_next_top3_rate' => $previousOutside > 0 ? $previousOutsideToTop3 / $previousOutside : null,
        ];
    }
}
