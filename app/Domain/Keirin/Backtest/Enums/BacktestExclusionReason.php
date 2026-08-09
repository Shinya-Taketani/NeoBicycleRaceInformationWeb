<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Enums;

enum BacktestExclusionReason: string
{
    case FeatureSourceRunInvalid = 'FEATURE_SOURCE_RUN_INVALID';
    case FeatureResultCountMismatch = 'FEATURE_RESULT_COUNT_MISMATCH';
    case FeatureDuplicateEntry = 'FEATURE_DUPLICATE_ENTRY';
    case FeatureDuplicateBike = 'FEATURE_DUPLICATE_BIKE';
    case FeatureStatusNotValid = 'FEATURE_STATUS_NOT_VALID';
    case FeatureQualityNotFull = 'FEATURE_QUALITY_NOT_FULL';
    case FeatureScoreUnavailable = 'FEATURE_SCORE_UNAVAILABLE';
    case FeatureScoreNonPositive = 'FEATURE_SCORE_NON_POSITIVE';
    case FeatureRankMissing = 'FEATURE_RANK_MISSING';
    case FeatureInputAsOfMissing = 'FEATURE_INPUT_AS_OF_MISSING';
    case FeatureInputAsOfAfterStart = 'FEATURE_INPUT_AS_OF_AFTER_START';
    case LabelRaceNotConfirmed = 'LABEL_RACE_NOT_CONFIRMED';
    case LabelResultCountMismatch = 'LABEL_RESULT_COUNT_MISMATCH';
    case LabelNoWinner = 'LABEL_NO_WINNER';
    case LabelFinishedRankMissing = 'LABEL_FINISHED_RANK_MISSING';
    case LabelAbnormalResultPresent = 'LABEL_ABNORMAL_RESULT_PRESENT';
}
