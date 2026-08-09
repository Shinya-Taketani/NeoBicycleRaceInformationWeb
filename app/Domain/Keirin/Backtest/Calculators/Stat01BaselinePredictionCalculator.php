<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Calculators;

use App\Domain\Keirin\Backtest\DTO\FeatureInputDto;
use App\Domain\Keirin\Backtest\DTO\PredictionDto;
use App\Domain\Keirin\Backtest\Support\CanonicalHasher;

class Stat01BaselinePredictionCalculator
{
    public const RULE_VERSION = 'STAT01-RACE-SCORE-RANK-v1';

    public const CALCULATION_VERSION = 'BT-01-stat01-baseline-v1';

    public function __construct(private readonly CanonicalHasher $hasher) {}

    /** @param list<FeatureInputDto> $features @return list<PredictionDto> */
    public function calculate(array $features): array
    {
        usort($features, fn (FeatureInputDto $a, FeatureInputDto $b): int => $a->raceEntryId <=> $b->raceEntryId);

        return array_map(function (FeatureInputDto $feature): PredictionDto {
            $score = number_format((float) $feature->raceScoreRaw, 2, '.', '');
            $rank = (int) $feature->raceScoreRank;
            $canonical = [
                'calculation_version' => self::CALCULATION_VERSION,
                'prediction_rule_version' => self::RULE_VERSION,
                'feature_run_id' => $feature->featureRunId,
                'feature_result_id' => $feature->id,
                'source_input_hash' => $feature->inputHash,
                'race_id' => $feature->raceId,
                'race_entry_id' => $feature->raceEntryId,
                'bike_number' => $feature->bikeNumber,
                'prediction_score' => $score,
                'predicted_rank' => $rank,
                'is_rank1_set' => $rank === 1,
                'is_top3_set' => $rank <= 3,
            ];

            return new PredictionDto(
                raceId: $feature->raceId,
                raceEntryId: $feature->raceEntryId,
                playerId: $feature->playerId,
                bikeNumber: $feature->bikeNumber,
                featureRunId: $feature->featureRunId,
                featureResultId: $feature->id,
                sourceInputHash: $feature->inputHash,
                predictionScore: $score,
                predictedRank: $rank,
                isRank1Set: $rank === 1,
                isTop3Set: $rank <= 3,
                predictionHash: $this->hasher->hash($canonical),
            );
        }, $features);
    }
}
