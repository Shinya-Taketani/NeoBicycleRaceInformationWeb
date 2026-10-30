<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\Calculators;

use App\Domain\Keirin\Statistics\DTO\Batch02BuildOptionsDto;
use App\Domain\Keirin\Statistics\DTO\Batch02FeatureResultDto;
use App\Domain\Keirin\Statistics\DTO\Batch02TargetEntryDto;
use App\Domain\Keirin\Statistics\DTO\SimilarityConditionsDto;
use App\Domain\Keirin\Statistics\Enums\HistoricalResultState;
use App\Domain\Keirin\Statistics\Enums\StatisticFeatureResultStatus as Status;
use App\Domain\Keirin\Statistics\Enums\StatisticQualityStatus;
use App\Domain\Keirin\Statistics\Support\AcquiredHistorySupport;
use App\Domain\Keirin\Statistics\Support\DeterministicJsonHasher;
use App\Domain\Keirin\Statistics\Support\SimilarConditionsHashInput;
use App\Domain\Keirin\Statistics\Support\SixConditionSimilarity;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

final class SimilarConditionsCalculator
{
    public const VERSIONS = ['STAT-16' => 'STAT-16-six-condition-performance-v3', 'STAT-27' => 'STAT-27-six-condition-experience-v3'];

    public function __construct(private readonly AcquiredHistorySupport $history, private readonly SixConditionSimilarity $similarity,
        private readonly DeterministicJsonHasher $hasher) {}

    /** @param array<int, SimilarityConditionsDto> $conditions */
    public function calculate(Batch02TargetEntryDto $target, array $histories, Batch02BuildOptionsDto $options, array $conditions): array
    {
        $context = $this->history->prepare($target, $histories, $options);
        $targetConditions = $conditions[$target->raceEntryId] ?? throw new RuntimeException('Target similarity conditions missing.');
        if (($targetConditions->sources['entry_id'] ?? null) !== $target->raceEntryId
            || ($targetConditions->sources['race_id'] ?? null) !== $target->raceId
            || ($targetConditions->sources['meeting_id'] ?? null) !== $target->targetMeetingId) {
            throw new RuntimeException('Target similarity source binding mismatch.');
        }
        $meeting = $targetConditions->sources['meeting_id'] ?? null;
        $meetingStart = $targetConditions->sources['meeting_start'] ?? null;
        $inputAsOf = AcquiredHistorySupport::jst($target->inputAsOf);
        $cutoff = $meetingStart === null ? $inputAsOf
            : min($inputAsOf, new DateTimeImmutable($meetingStart.'T00:00:00', new DateTimeZone('Asia/Tokyo')));
        $references = [];
        $exclusions = $performanceExclusions = [];
        $eligible = $comparable = $positive = $performanceCount = 0;
        $sum = $sum2 = $coverage = $similarities = $performanceWeight = $weightedRank = 0.0;
        $wins = [1 => 0.0, 2 => 0.0, 3 => 0.0];
        $maxSimilarity = null;
        $minAt = $maxAt = null;
        foreach ($context['events'] as $e) {
            $condition = $conditions[$e->raceEntryId] ?? null;
            if ($condition !== null && (($condition->sources['entry_id'] ?? null) !== $e->raceEntryId
                || ($condition->sources['race_id'] ?? null) !== $e->raceId
                || ($condition->sources['meeting_id'] ?? null) !== $e->raceMeetingId)) {
                throw new RuntimeException('Historical similarity source binding mismatch.');
            }
            $reason = match (true) {
                $meeting === null || ! ($targetConditions->sources['meeting_context_verified'] ?? true) => 'TARGET_MEETING_UNVERIFIED',
                $e->raceMeetingId === null || ($condition !== null && ! ($condition->sources['meeting_context_verified'] ?? true)) => 'HISTORY_MEETING_UNVERIFIED',
                $e->raceMeetingId === $meeting => 'SAME_MEETING',
                $e->scheduledStartAt >= $cutoff => 'AT_OR_AFTER_MEETING_CUTOFF',
                ! $e->started() => 'DID_NOT_START_OR_WITHDRAWN',
                $e->resultState === HistoricalResultState::UnknownAbnormal => 'PARTICIPATION_NOT_VERIFIED',
                default => null,
            };
            $comparison = $condition === null ? null : $this->similarity->compare($targetConditions, $condition);
            if ($reason === null) {
                $eligible++;
                $minAt = $minAt === null ? $e->scheduledStartAt : min($minAt, $e->scheduledStartAt);
                $maxAt = $maxAt === null ? $e->scheduledStartAt : max($maxAt, $e->scheduledStartAt);
                if ($comparison === null || $comparison['weight'] === null) {
                    $reason = $comparison === null ? 'HISTORY_CONDITIONS_MISSING' : 'INSUFFICIENT_COMPARABLE_CONDITIONS';
                } else {
                    $comparable++;
                    $w = $comparison['weight'];
                    $sum += $w;
                    $sum2 += $w * $w;
                    $coverage += $comparison['six_condition_coverage'];
                    $similarities += $comparison['similarity'];
                    $maxSimilarity = max($maxSimilarity ?? 0.0, $comparison['similarity']);
                    $positive += (int) ($w > 0);
                    if ($w > 0 && $e->normalFinish() && $e->rank !== null && $e->rank > 0 && $e->rank <= $e->entrantCount) {
                        $performanceCount++;
                        $performanceWeight += $w;
                        $weightedRank += $w * $e->rank;
                        foreach ([1, 2, 3] as $rank) {
                            $wins[$rank] += $e->rank === $rank ? $w : 0.0;
                        }
                    }
                }
            }
            if ($reason !== null) {
                $exclusions[$reason] = ($exclusions[$reason] ?? 0) + 1;
            }
            $performanceReason = match (true) {
                $reason !== null => $reason,
                $comparison['weight'] <= 0 => 'ZERO_WEIGHT',
                ! $e->normalFinish() => 'NOT_NORMAL_FINISH',
                $e->rank === null || $e->rank < 1 || $e->rank > $e->entrantCount => 'MISSING_OR_INVALID_NORMAL_RANK',
                default => null,
            };
            if ($performanceReason !== null) {
                $performanceExclusions[$performanceReason] = ($performanceExclusions[$performanceReason] ?? 0) + 1;
            }
            $references[] = ['race_id' => $e->raceId, 'entry_id' => $e->raceEntryId, 'player_id' => $e->playerId,
                'meeting_id' => $e->raceMeetingId, 'scheduled_start_at' => AcquiredHistorySupport::jst($e->scheduledStartAt)->format(DATE_ATOM),
                'result_state' => $e->resultState->value, 'rank' => $e->rank, 'tied' => $e->tied,
                'conditions' => $condition === null ? null : get_object_vars($condition), 'comparison' => $comparison, 'exclusion' => $reason,
                'performance_exclusion' => $performanceReason,
                'performance_eligible' => $reason === null && $comparison['weight'] > 0 && $e->normalFinish()
                    && $e->rank !== null && $e->rank > 0 && $e->rank <= $e->entrantCount];
        }
        ksort($exclusions);
        ksort($performanceExclusions);
        $state = match (true) {
            $context['events'] === [] => 'NO_REFERENCED_HISTORY', $eligible === 0 => 'NO_ELIGIBLE_STARTED_HISTORY',
            $comparable === 0 => 'CONDITIONS_NOT_COMPARABLE', $positive === 0 => 'COMPARABLE_ZERO_WEIGHT',
            default => 'COMPUTABLE_LIMITED_MVP',
        };
        $common = ['six_condition_version' => SixConditionSimilarity::VERSION, 'scope' => 'SIX_CONDITIONS_NOT_FULL_REQUIREMENT_COVERAGE',
            'history_state' => $state, 'eligible_started_history_count' => $eligible, 'comparable_history_count' => $comparable,
            'positive_weight_history_count' => $positive, 'mean_similarity' => $comparable === 0 ? null : $similarities / $comparable,
            'mean_six_condition_coverage' => $comparable === 0 ? null : $coverage / $comparable, 'max_similarity' => $maxSimilarity,
            'history_min_at' => $minAt === null ? null : AcquiredHistorySupport::jst($minAt)->format(DATE_ATOM),
            'history_max_at' => $maxAt === null ? null : AcquiredHistorySupport::jst($maxAt)->format(DATE_ATOM),
            'cutoff' => $cutoff->format(DATE_ATOM), 'history_from' => AcquiredHistorySupport::jst($options->historyFrom)->format(DATE_ATOM),
            'threshold' => null, 'top_k' => null, 'time_decay' => 'NONE', 'prediction_use' => 'NOT_AUTHORIZED', 'historical_as_of_available' => false];
        $experience = $common + ['weighted_experience' => $comparable === 0 ? null : $sum,
            'weight_squared_sum' => $comparable === 0 ? null : $sum2, 'effective_experience' => $sum2 > 0 ? $sum * $sum / $sum2 : null,
            'novelty_classification' => null, 'sufficiency_classification' => null];
        $performance = $common + ['performance_population' => 'NORMAL_FINISH_WITH_VALID_RANK_AND_POSITIVE_WEIGHT_TIES_INCLUDED',
            'performance_exclusions' => $performanceExclusions,
            'performance_history_count' => $performanceCount, 'performance_weight_sum' => $performanceWeight,
            'weighted_rank_sum' => $weightedRank, 'mean_rank' => $performanceWeight > 0 ? $weightedRank / $performanceWeight : null,
            'weighted_finish_counts' => $wins, 'rank_rates_among_normal_finishes' => array_map(fn ($w) => $performanceWeight > 0 ? $w / $performanceWeight : null, $wins)];
        $evidence = $context['evidence'] + ['target_conditions' => get_object_vars($targetConditions), 'history_references' => $references,
            'exclusions' => $exclusions, 'quality_reasons' => ['LIMITED_SIX_CONDITION_MVP', 'RETROSPECTIVE_ONLY'],
            'cutoff_policy' => 'MIN_STAT01_INPUT_AND_TARGET_MEETING_START_JST_EXCLUDING_SAME_MEETING'];
        $hash = $this->hasher->hash(['context_hash' => $context['hash'],
            'target_conditions' => SimilarConditionsHashInput::conditions(get_object_vars($targetConditions)),
            'references' => SimilarConditionsHashInput::references($references), 'formula' => SixConditionSimilarity::VERSION,
            'datetime_hash_contract' => SimilarConditionsHashInput::VERSION]);
        $result = [];
        foreach (['STAT-16' => $performance, 'STAT-27' => $experience] as $stat => $features) {
            $status = $target->playerId === null ? Status::MissingInput
                : ($context['events'] === [] ? Status::NoHistory : ($comparable === 0 ? Status::PartialHistory : Status::Partial));
            $result[$stat] = new Batch02FeatureResultDto($target, $status, StatisticQualityStatus::Degraded,
                $features, $evidence, hash('sha256', self::VERSIONS[$stat].':'.$hash));
        }

        return $result;
    }
}
