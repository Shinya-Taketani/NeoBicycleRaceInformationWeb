<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\Support;

use App\Domain\Keirin\Statistics\DTO\Batch04BuildOptionsDto;
use App\Domain\Keirin\Statistics\DTO\Batch04FeatureResultDto;
use App\Domain\Keirin\Statistics\DTO\Batch04HeadToHeadEventDto;
use App\Domain\Keirin\Statistics\DTO\Batch04RaceInputDto;
use App\Domain\Keirin\Statistics\DTO\Batch04TargetEntryDto;
use App\Domain\Keirin\Statistics\Enums\Batch04Stat;
use App\Domain\Keirin\Statistics\Enums\StatisticFeatureResultStatus;
use App\Domain\Keirin\Statistics\Enums\StatisticQualityStatus;
use DateTimeImmutable;
use UnexpectedValueException;

class Batch04CalculatorSupport
{
    public function __construct(
        private readonly DeterministicJsonHasher $hasher,
        private readonly StatisticalMath $math,
    ) {}

    public function targetContextHash(
        Batch04TargetEntryDto $target,
        Batch04RaceInputDto $race,
        bool $includeCoentrants,
    ): string {
        $context = [
            'target_race_id' => $target->raceId,
            'target_race_entry_id' => $target->raceEntryId,
            'target_player_id' => $target->playerId,
            'target_bike_number' => $target->bikeNumber,
            'target_frame_number' => $target->frameNumber,
            'target_declared_entrant_count' => $target->declaredEntrantCount,
            'target_actual_entry_count' => $target->actualEntryCount,
            'target_racetrack_id' => $target->racetrackId,
            'target_input_as_of' => $this->timestamp($target->inputAsOf),
            'target_scheduled_start_at' => $this->timestamp($target->scheduledStartAt),
            'target_stat01_input_hash' => $target->stat01InputHash,
            'target_stat01_race_score' => $target->stat01RaceScore,
            'target_stat01_rank' => $target->stat01Rank,
            'target_stat01_strength_percentile' => $target->stat01StrengthPercentile,
            'participating_bike_numbers' => $target->participatingBikeNumbers,
        ];
        if ($includeCoentrants) {
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
            $context['current_coentrants'] = array_map(fn (Batch04TargetEntryDto $entry): array => [
                'race_entry_id' => $entry->raceEntryId,
                'player_id' => $entry->playerId,
                'bike_number' => $entry->bikeNumber,
                'frame_number' => $entry->frameNumber,
                'stat01_input_hash' => $entry->stat01InputHash,
                'stat01_race_score' => $entry->stat01RaceScore,
                'stat01_rank' => $entry->stat01Rank,
                'stat01_strength_percentile' => $entry->stat01StrengthPercentile,
            ], $coentrants);
        }

        return $this->hasher->hash($context);
    }

    /**
     * @param  array<string, mixed>  $features
     * @param  array<string, mixed>  $evidence
     * @param  list<string>  $qualityReasons
     * @param  list<string>  $unavailableComponents
     */
    public function result(
        Batch04TargetEntryDto $target,
        Batch04BuildOptionsDto $options,
        Batch04Stat $stat,
        string $targetContextHash,
        string $historyInputHash,
        array $features,
        array $evidence,
        StatisticFeatureResultStatus $status,
        array $qualityReasons = [],
        array $unavailableComponents = [],
        ?string $statusReason = null,
    ): Batch04FeatureResultDto {
        $qualityReasons = array_values(array_unique($qualityReasons));
        $qualityStatus = match ($status) {
            StatisticFeatureResultStatus::Partial, StatisticFeatureResultStatus::PartialHistory => StatisticQualityStatus::Partial,
            StatisticFeatureResultStatus::Valid, StatisticFeatureResultStatus::NotApplicable => $qualityReasons === []
                ? StatisticQualityStatus::Full
                : StatisticQualityStatus::Degraded,
            default => StatisticQualityStatus::Degraded,
        };
        $evidence = array_replace($evidence, [
            'target_context_hash' => $targetContextHash,
            'history_input_hash' => $historyInputHash,
            'history_from' => $options->historyFrom->format('Y-m-d'),
            'history_result_mode' => 'BACKFILLED_FINAL_RESULT',
            'quality_reasons' => $qualityReasons,
            'unavailable_components' => array_values(array_unique($unavailableComponents)),
            'status_reason' => $statusReason,
            'calculation_version' => $stat->calculationVersion(),
        ]);
        $inputHash = $this->hasher->hash([
            'stat_code' => $stat->value,
            'calculation_version' => $stat->calculationVersion(),
            'target_context_hash' => $targetContextHash,
            'history_from' => $options->historyFrom->format('Y-m-d'),
            'history_input_hash' => $historyInputHash,
        ]);

        return new Batch04FeatureResultDto($target, $status, $qualityStatus, $features, $evidence, $inputHash);
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

    /**
     * @param  list<array{race_entry_id:int,player_id:?int,bike_number:int,grade:?string,race_score:?string}>  $entries
     * @return array{hash:string,percentiles:array<int,?float>}
     */
    public function scoreContext(int $raceId, int $entrantCount, array $entries): array
    {
        $hash = $this->hasher->hash([
            'race_id' => $raceId,
            'entrant_count' => $entrantCount,
            'entries' => $entries,
        ]);
        $complete = count($entries) === $entrantCount;
        $scores = [];
        foreach ($entries as $entry) {
            $score = $entry['race_score'];
            if ($score === null || (float) $score <= 0) {
                $complete = false;
            } else {
                $scores[$entry['race_entry_id']] = (float) $score;
            }
        }
        $percentiles = array_fill_keys(array_column($entries, 'race_entry_id'), null);
        if ($complete && count($scores) > 1) {
            foreach ($scores as $raceEntryId => $targetScore) {
                $rank = 1 + count(array_filter($scores, fn (float $score): bool => $score > $targetScore));
                $percentiles[$raceEntryId] = (count($scores) - $rank) / (count($scores) - 1);
            }
        }

        return ['hash' => $hash, 'percentiles' => $percentiles];
    }

    public function pairEventHash(Batch04HeadToHeadEventDto $event): string
    {
        return $this->hasher->hash([
            'race_id' => $event->raceId,
            'scheduled_start_at' => $this->timestamp($event->scheduledStartAt),
            'entrant_count' => $event->entrantCount,
            'racetrack_id' => $event->racetrackId,
            'first_player_id' => $event->firstPlayerId,
            'second_player_id' => $event->secondPlayerId,
            'first_race_entry_id' => $event->firstRaceEntryId,
            'second_race_entry_id' => $event->secondRaceEntryId,
            'first_bike_number' => $event->firstBikeNumber,
            'second_bike_number' => $event->secondBikeNumber,
            'first_frame_number' => $event->firstFrameNumber,
            'second_frame_number' => $event->secondFrameNumber,
            'first_result_state' => $event->firstResultState->value,
            'second_result_state' => $event->secondResultState->value,
            'first_tied' => $event->firstTied,
            'second_tied' => $event->secondTied,
            'first_rank' => $event->firstRank,
            'second_rank' => $event->secondRank,
            'first_finish_percentile' => $event->firstFinishPercentile,
            'second_finish_percentile' => $event->secondFinishPercentile,
            'first_race_score' => $event->firstRaceScore,
            'second_race_score' => $event->secondRaceScore,
            'first_score_percentile' => $event->firstScorePercentile,
            'second_score_percentile' => $event->secondScorePercentile,
            'historical_score_context_hash' => $event->historicalScoreContextHash,
            'first_race_entry_fetched_at' => $this->timestamp($event->firstRaceEntryFetchedAt),
            'second_race_entry_fetched_at' => $this->timestamp($event->secondRaceEntryFetchedAt),
            'first_race_result_fetched_at' => $this->timestamp($event->firstRaceResultFetchedAt),
            'second_race_result_fetched_at' => $this->timestamp($event->secondRaceResultFetchedAt),
            'result_confirmed_at' => $this->timestamp($event->resultConfirmedAt),
        ]);
    }

    public function hash(array $input): string
    {
        return $this->hasher->hash($input);
    }

    public function mean(array $values): ?float
    {
        return $this->math->mean($values);
    }

    public function timestamp(?DateTimeImmutable $date): ?string
    {
        return $date?->format('Y-m-d\TH:i:s.uP');
    }

    public function raceScore(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (! is_numeric($value)) {
            throw new UnexpectedValueException('Historical race_score was not numeric.');
        }

        return number_format((float) $value, 2, '.', '');
    }

    private function difference(?float $left, ?float $right): ?float
    {
        return $left !== null && $right !== null ? $left - $right : null;
    }
}
