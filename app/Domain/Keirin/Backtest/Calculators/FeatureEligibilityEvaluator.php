<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Calculators;

use App\Domain\Keirin\Backtest\DTO\EligibilityDecisionDto;
use App\Domain\Keirin\Backtest\DTO\FeatureInputDto;
use App\Domain\Keirin\Backtest\DTO\RaceContextDto;
use App\Domain\Keirin\Backtest\Enums\BacktestExclusionReason;

class FeatureEligibilityEvaluator
{
    /** @param list<FeatureInputDto> $features */
    public function evaluate(RaceContextDto $race, array $features): EligibilityDecisionDto
    {
        $reasons = [];
        if (count($features) !== $race->entrantCount) {
            $reasons[] = BacktestExclusionReason::FeatureResultCountMismatch;
        }
        if ($this->hasDuplicates(array_map(fn (FeatureInputDto $feature): int => $feature->raceEntryId, $features))) {
            $reasons[] = BacktestExclusionReason::FeatureDuplicateEntry;
        }
        if ($this->hasDuplicates(array_map(fn (FeatureInputDto $feature): int => $feature->bikeNumber, $features))) {
            $reasons[] = BacktestExclusionReason::FeatureDuplicateBike;
        }

        foreach ($features as $feature) {
            if ($feature->status !== 'VALID') {
                $reasons[] = BacktestExclusionReason::FeatureStatusNotValid;
            }
            if ($feature->qualityStatus !== 'FULL') {
                $reasons[] = BacktestExclusionReason::FeatureQualityNotFull;
            }
            if (! $feature->raceScoreAvailable) {
                $reasons[] = BacktestExclusionReason::FeatureScoreUnavailable;
            }
            if ($feature->raceScoreRaw === null || ! is_numeric($feature->raceScoreRaw) || (float) $feature->raceScoreRaw <= 0) {
                $reasons[] = BacktestExclusionReason::FeatureScoreNonPositive;
            }
            if ($feature->raceScoreRank === null) {
                $reasons[] = BacktestExclusionReason::FeatureRankMissing;
            }
            if ($feature->inputAsOf === null) {
                $reasons[] = BacktestExclusionReason::FeatureInputAsOfMissing;
            } elseif ($race->scheduledStartAt === null || $feature->inputAsOf > $race->scheduledStartAt) {
                $reasons[] = BacktestExclusionReason::FeatureInputAsOfAfterStart;
            }
        }

        $reasons = array_values(array_unique($reasons, SORT_REGULAR));

        return new EligibilityDecisionDto($reasons === [], $reasons);
    }

    /** @param list<int> $values */
    private function hasDuplicates(array $values): bool
    {
        return count($values) !== count(array_unique($values));
    }
}
