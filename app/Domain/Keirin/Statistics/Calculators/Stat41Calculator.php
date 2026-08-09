<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\Calculators;

use App\Domain\Keirin\Statistics\DTO\Batch05FeatureResultDto;
use App\Domain\Keirin\Statistics\DTO\Batch05RaceInputDto;
use App\Domain\Keirin\Statistics\DTO\Batch05TargetEntryDto;
use App\Domain\Keirin\Statistics\Enums\StatisticFeatureResultStatus;
use App\Domain\Keirin\Statistics\Enums\StatisticQualityStatus;
use App\Domain\Keirin\Statistics\Support\DeterministicJsonHasher;
use App\Domain\Keirin\Statistics\Support\StatisticalMath;

class Stat41Calculator
{
    public const STAT_CODE = 'STAT-41';

    public const CALCULATION_VERSION = 'STAT-41-existing-db-v1';

    public function __construct(
        private readonly StatisticalMath $math,
        private readonly DeterministicJsonHasher $hasher,
    ) {}

    public function calculate(Batch05RaceInputDto $race): Batch05FeatureResultDto
    {
        $entries = $race->entries;
        usort($entries, fn (Batch05TargetEntryDto $left, Batch05TargetEntryDto $right): int => $left->raceEntryId <=> $right->raceEntryId);

        $expectedValues = [];
        $expectedInvalid = false;
        $usable = [];
        $missingCount = 0;
        $invalidCount = 0;
        $unresolvedPlayerCount = 0;
        $sourceFetchedAt = null;
        $participants = [];
        foreach ($entries as $entry) {
            $expected = $this->positiveInteger($entry->expectedEntrantCount);
            if ($expected === null || $expected <= 1) {
                $expectedInvalid = true;
            } else {
                $expectedValues[] = $expected;
            }

            $score = $this->positiveFloat($entry->raceScoreRaw);
            $isUsable = $entry->raceScoreAvailable === true && $score !== null;
            if ($isUsable) {
                $usable[] = ['race_entry_id' => $entry->raceEntryId, 'score' => $score];
            } elseif ($entry->raceScoreRaw === null || (is_string($entry->raceScoreRaw) && trim($entry->raceScoreRaw) === '')) {
                $missingCount++;
            } else {
                $invalidCount++;
            }
            if ($entry->playerId === null) {
                $unresolvedPlayerCount++;
            }
            if ($entry->sourceFetchedAt !== null && ($sourceFetchedAt === null || $entry->sourceFetchedAt > $sourceFetchedAt)) {
                $sourceFetchedAt = $entry->sourceFetchedAt;
            }
            $participants[] = [
                'race_entry_id' => $entry->raceEntryId,
                'player_id' => $entry->playerId,
                'bike_number' => $entry->bikeNumber,
                'stat01_input_hash' => $entry->stat01InputHash,
                'stat01_status' => $entry->stat01Status,
                'stat01_quality_status' => $entry->stat01QualityStatus,
                'race_score_available' => $entry->raceScoreAvailable,
                'race_score_raw' => $this->canonicalNumeric($entry->raceScoreRaw),
                'expected_entrant_count' => $expected,
                'expected_entrant_count_raw' => $this->canonicalNumeric($entry->expectedEntrantCount),
                'stat01_rank' => $entry->stat01Rank,
                'stat01_dense_rank' => $entry->stat01DenseRank,
                'stat01_strength_percentile' => $entry->stat01StrengthPercentile,
                'stat01_race_input_hash' => $entry->stat01RaceInputHash,
                'source_fetched_at' => $entry->sourceFetchedAt?->format('Y-m-d H:i:s.uP'),
            ];
        }

        $uniqueExpected = array_values(array_unique($expectedValues));
        sort($uniqueExpected, SORT_NUMERIC);
        $expectedCount = count($uniqueExpected) === 1 ? $uniqueExpected[0] : null;
        $actualCount = count($entries);
        $usableCount = count($usable);
        [$status, $reason] = $this->status(
            $expectedInvalid,
            count($uniqueExpected) > 1,
            $expectedCount,
            $actualCount,
            $usableCount,
            $missingCount,
            $invalidCount,
        );
        $quality = $status === StatisticFeatureResultStatus::Valid
            ? StatisticQualityStatus::Full
            : StatisticQualityStatus::Partial;

        usort($usable, fn (array $left, array $right): int => $right['score'] <=> $left['score'] ?: $left['race_entry_id'] <=> $right['race_entry_id']);
        $scores = array_column($usable, 'score');
        $pairGaps = $this->pairGaps($scores);
        $mean = $this->math->mean($scores);
        $standardDeviation = $this->math->populationStandardDeviation($scores);
        $topScores = array_pad(array_slice($scores, 0, 4), 4, null);
        $topGaps = [
            $this->gap($topScores[0], $topScores[1]),
            $this->gap($topScores[1], $topScores[2]),
            $this->gap($topScores[2], $topScores[3]),
        ];
        $coverage = $expectedCount !== null && $expectedCount > 0 ? (float) $usableCount / $expectedCount : null;
        $scoreStructureScope = $usableCount === $actualCount && $actualCount === $expectedCount
            ? 'ALL_TARGET_ENTRIES'
            : 'VALID_SCORE_SUBSET';
        $qualityReasons = array_values(array_filter([
            $expectedInvalid ? 'INVALID_ENTRANT_COUNT' : null,
            count($uniqueExpected) > 1 ? 'INCONSISTENT_ENTRANT_COUNT' : null,
            $actualCount !== $expectedCount ? 'ENTRY_COUNT_MISMATCH' : null,
            $missingCount > 0 ? 'MISSING_PLAYER_SCORES' : null,
            $invalidCount > 0 ? 'INVALID_PLAYER_SCORES' : null,
            $usableCount < 2 ? 'INSUFFICIENT_COMPETITION_STRUCTURE' : null,
        ]));

        $features = [
            'RACE_CONTEXT' => [
                'expected_entrant_count' => $expectedCount,
                'actual_entry_count' => $actualCount,
                'input_as_of' => $race->inputAsOf->format('Y-m-d H:i:s.uP'),
            ],
            'SCORE_COVERAGE' => [
                'usable_score_count' => $usableCount,
                'missing_score_count' => $missingCount,
                'invalid_score_count' => $invalidCount,
                'score_coverage_ratio' => $coverage,
                'score_structure_scope' => $scoreStructureScope,
            ],
            'SCORE_DISTRIBUTION' => [
                'distinct_score_count' => count(array_unique($scores, SORT_REGULAR)),
                'mean' => $mean,
                'min' => $scores === [] ? null : min($scores),
                'max' => $scores === [] ? null : max($scores),
                'range' => $scores === [] ? null : max($scores) - min($scores),
                'variance_pop' => $standardDeviation !== null ? $standardDeviation ** 2 : null,
                'stddev_pop' => $standardDeviation,
                'median' => $this->math->median($scores),
                'q25' => $this->math->quantile($scores, 0.25),
                'q75' => $this->math->quantile($scores, 0.75),
                'iqr' => $this->math->interquartileRange($scores),
                'mad' => $this->math->medianAbsoluteDeviation($scores),
                'cv_pop' => $mean !== null && $mean > 0 && $standardDeviation !== null
                    ? $standardDeviation / $mean
                    : null,
            ],
            'TOP_SCORE_STRUCTURE' => [
                'top1_score' => $topScores[0],
                'top2_score' => $topScores[1],
                'top3_score' => $topScores[2],
                'top4_score' => $topScores[3],
                'gap_rank1_rank2' => $topGaps[0],
                'gap_rank1_rank3' => $this->gap($topScores[0], $topScores[2]),
                'gap_rank2_rank3' => $topGaps[1],
                'gap_rank3_rank4' => $topGaps[2],
                'top_score_tie_count' => $scores === [] ? null : count(array_filter($scores, fn (float $score): bool => $score === $scores[0])),
            ],
            'WINNER_BOUNDARY' => ['rank1_vs_rank2_gap' => $topGaps[0]],
            'TOP2_BOUNDARY' => ['rank2_vs_rank3_gap' => $topGaps[1]],
            'TOP3_BOUNDARY' => ['rank3_vs_rank4_gap' => $topGaps[2]],
            'PAIRWISE_SCORE_GAPS' => [
                'pair_count' => count($pairGaps),
                'min_absolute_gap' => $pairGaps === [] ? null : min($pairGaps),
                'mean_absolute_gap' => $this->math->mean($pairGaps),
                'median_absolute_gap' => $this->math->median($pairGaps),
                'max_absolute_gap' => $pairGaps === [] ? null : max($pairGaps),
                'q25' => $this->math->quantile($pairGaps, 0.25),
                'q75' => $this->math->quantile($pairGaps, 0.75),
                'iqr' => $this->math->interquartileRange($pairGaps),
            ],
            'RACE_COMPETITIVENESS_SCORE' => null,
            'RACE_PREDICTION_UNCERTAINTY_SCORE' => null,
            'RACE_UPSET_STRUCTURE_SCORE' => null,
            'PREDICTION_PROBABILITY_ENTROPY' => null,
            'PREDICTION_PROBABILITY_CONCENTRATION' => null,
            'CANDIDATE_COUNT' => null,
            'CANDIDATE_SELECTION_POLICY' => null,
            'LINE_STRENGTH_DISPERSION' => null,
            'LINE_STRENGTH_TOP_GAP' => null,
            'CONFIDENCE_ADJUSTED_COMPETITIVENESS' => null,
            'SCENARIO_COUNT' => null,
            'SCENARIO_PROBABILITY_CONCENTRATION' => null,
            'RANK_REVERSAL_RATE' => null,
            'PREDICTION_INTERVAL_OVERLAP' => null,
        ];
        $targetContext = [
            'stat_code' => self::STAT_CODE,
            'calculation_version' => self::CALCULATION_VERSION,
            'stat01_run_id' => $race->stat01RunId,
            'stat01_calculation_version' => Stat01Calculator::CALCULATION_VERSION,
            'race_id' => $race->raceId,
            'input_as_of' => $race->inputAsOf->format('Y-m-d H:i:s.uP'),
            'expected_entrant_count' => $expectedCount,
            'actual_entry_count' => $actualCount,
            'participants' => $participants,
        ];
        $targetContextHash = $this->hasher->hash($targetContext);
        $inputHash = $this->hasher->hash([
            'stat_code' => self::STAT_CODE,
            'calculation_version' => self::CALCULATION_VERSION,
            'target_context_hash' => $targetContextHash,
        ]);

        return new Batch05FeatureResultDto(
            raceId: $race->raceId,
            inputAsOf: $race->inputAsOf,
            sourceFetchedAt: $sourceFetchedAt,
            status: $status,
            qualityStatus: $quality,
            features: $features,
            evidence: [
                'reason' => $reason,
                'status_reason' => $reason,
                'quality_reasons' => $qualityReasons,
                'source_stat_code' => Stat01Calculator::STAT_CODE,
                'source_calculation_version' => Stat01Calculator::CALCULATION_VERSION,
                'stat01_run_id' => $race->stat01RunId,
                'stat01_calculation_version' => Stat01Calculator::CALCULATION_VERSION,
                'source_max_fetched_at' => $sourceFetchedAt?->format('Y-m-d H:i:s.uP'),
                'target_context_hash' => $targetContextHash,
                'participant_snapshot' => $participants,
                'canonical_usable_scores' => $usable,
                'unresolved_player_count' => $unresolvedPlayerCount,
                'expected_entrant_count_values' => $uniqueExpected,
                'participant_count' => $actualCount,
                'expected_entrant_count' => $expectedCount,
                'score_coverage_ratio' => $coverage,
                'score_structure_scope' => $scoreStructureScope,
                'prediction_as_of_source' => 'STAT01_RESULT_INPUT_AS_OF',
                'output_policy' => 'BACKTEST_PENDING_NO_COMPOSITE_SCORE',
                'unavailable_components' => [
                    'PREDICTION_PROBABILITIES',
                    'STAT40_LINE_STRENGTH',
                    'SCENARIO_SIMULATION',
                    'PREDICTION_INTERVALS',
                    'RANK_REVERSAL_SIMULATION',
                ],
                'deferred_components' => [
                    'PLAYER_VOLATILITY_INTEGRATION_STAT24',
                    'STAT20_CONFIDENCE_INTEGRATION',
                    'STAT14_COMPETITION_COMPLEXITY',
                    'STAT29_LINE_CHANGE_STRUCTURE',
                ],
                'calculation_version' => self::CALCULATION_VERSION,
            ],
            inputHash: $inputHash,
        );
    }

    /** @return array{StatisticFeatureResultStatus, string} */
    private function status(
        bool $expectedInvalid,
        bool $expectedInconsistent,
        ?int $expectedCount,
        int $actualCount,
        int $usableCount,
        int $missingCount,
        int $invalidCount,
    ): array {
        if ($expectedInconsistent) {
            return [StatisticFeatureResultStatus::InvalidInput, 'INCONSISTENT_ENTRANT_COUNT'];
        }
        if ($expectedInvalid || $expectedCount === null) {
            return [StatisticFeatureResultStatus::InvalidInput, 'INVALID_ENTRANT_COUNT'];
        }
        if ($usableCount === 0) {
            return $invalidCount > 0
                ? [StatisticFeatureResultStatus::InvalidInput, 'INVALID_PLAYER_SCORES']
                : [StatisticFeatureResultStatus::MissingInput, 'MISSING_PLAYER_SCORES'];
        }
        if ($usableCount === 1) {
            return [StatisticFeatureResultStatus::Partial, 'PARTIAL_PLAYER_SCORES_INSUFFICIENT_FOR_COMPETITION_STRUCTURE'];
        }
        if ($actualCount !== $expectedCount) {
            return [StatisticFeatureResultStatus::Partial, 'ENTRY_COUNT_MISMATCH'];
        }
        if ($missingCount > 0 || $invalidCount > 0 || $usableCount !== $actualCount) {
            return [StatisticFeatureResultStatus::Partial, 'PARTIAL_PLAYER_SCORES'];
        }

        return [StatisticFeatureResultStatus::Valid, 'COMPLETE_SCORE_DISTRIBUTION'];
    }

    private function positiveInteger(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^\d+$/', trim($value)) === 1) {
            return (int) trim($value);
        }
        if (is_float($value) && is_finite($value) && floor($value) === $value) {
            return (int) $value;
        }

        return null;
    }

    private function positiveFloat(mixed $value): ?float
    {
        if (! is_int($value) && ! is_float($value) && ! is_string($value)) {
            return null;
        }
        if (! is_numeric($value)) {
            return null;
        }
        $score = (float) $value;

        return is_finite($score) && $score > 0 ? $score : null;
    }

    private function canonicalNumeric(mixed $value): mixed
    {
        if ((is_int($value) || is_float($value) || is_string($value)) && is_numeric($value)) {
            return (float) $value;
        }

        return $value;
    }

    private function gap(?float $higher, ?float $lower): ?float
    {
        return $higher !== null && $lower !== null ? abs($higher - $lower) : null;
    }

    /** @param list<float> $scores @return list<float> */
    private function pairGaps(array $scores): array
    {
        $gaps = [];
        for ($left = 0; $left < count($scores); $left++) {
            for ($right = $left + 1; $right < count($scores); $right++) {
                $gaps[] = abs($scores[$left] - $scores[$right]);
            }
        }

        return $gaps;
    }
}
