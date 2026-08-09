<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Calculators;

use App\Domain\Keirin\Backtest\DTO\CohortDecisionDto;
use App\Domain\Keirin\Backtest\DTO\LabelResultDto;
use App\Domain\Keirin\Backtest\DTO\PredictionDto;
use App\Domain\Keirin\Backtest\DTO\RaceContextDto;
use App\Domain\Keirin\Backtest\Enums\BacktestCohort;
use App\Domain\Keirin\Backtest\Enums\BacktestExclusionReason;

class LabelCohortEvaluator
{
    /** @param list<PredictionDto> $predictions @param list<LabelResultDto> $labels */
    public function evaluate(BacktestCohort $cohort, RaceContextDto $race, array $predictions, array $labels): CohortDecisionDto
    {
        $operationalReasons = [];
        if ($race->resultStatus !== 'CONFIRMED') {
            $operationalReasons[] = BacktestExclusionReason::LabelRaceNotConfirmed;
        }
        if (count($labels) !== $race->entrantCount) {
            $operationalReasons[] = BacktestExclusionReason::LabelResultCountMismatch;
        }
        if (array_filter($labels, fn (LabelResultDto $label): bool => $label->isWinner()) === []) {
            $operationalReasons[] = BacktestExclusionReason::LabelNoWinner;
        }
        if (array_filter($labels, fn (LabelResultDto $label): bool => in_array($label->resultStatus, ['FINISHED', 'TIED'], true) && $label->rank === null) !== []) {
            $operationalReasons[] = BacktestExclusionReason::LabelFinishedRankMissing;
        }
        if ($operationalReasons !== []) {
            return new CohortDecisionDto(false, array_values(array_unique($operationalReasons, SORT_REGULAR)));
        }

        if ($cohort === BacktestCohort::NormalFinish
            && array_filter($labels, fn (LabelResultDto $label): bool => ! in_array($label->resultStatus, ['FINISHED', 'TIED'], true) || $label->rank === null) !== []) {
            return new CohortDecisionDto(false, [BacktestExclusionReason::LabelAbnormalResultPresent]);
        }

        $winnerBikes = array_map(
            fn (LabelResultDto $label): int => $label->bikeNumber,
            array_values(array_filter($labels, fn (LabelResultDto $label): bool => $label->isWinner())),
        );
        $rank1Bikes = array_map(
            fn (PredictionDto $prediction): int => $prediction->bikeNumber,
            array_values(array_filter($predictions, fn (PredictionDto $prediction): bool => $prediction->isRank1Set)),
        );
        $top3Bikes = array_map(
            fn (PredictionDto $prediction): int => $prediction->bikeNumber,
            array_values(array_filter($predictions, fn (PredictionDto $prediction): bool => $prediction->isTop3Set)),
        );

        return new CohortDecisionDto(
            included: true,
            reasons: [],
            rank1Hit: array_intersect($rank1Bikes, $winnerBikes) !== [],
            top3Hit: array_intersect($top3Bikes, $winnerBikes) !== [],
            rank1SetSize: count($rank1Bikes),
            top3SetSize: count($top3Bikes),
        );
    }
}
