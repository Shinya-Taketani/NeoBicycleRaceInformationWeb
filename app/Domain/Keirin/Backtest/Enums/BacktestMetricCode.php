<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Enums;

enum BacktestMetricCode: string
{
    case FeatureCoverageRate = 'FEATURE_COVERAGE_RATE';
    case Rank1SetWinHitRate = 'RANK1_SET_WIN_HIT_RATE';
    case Top3SetWinHitRate = 'TOP3_SET_WIN_HIT_RATE';
    case Rank1SetSizeMean = 'RANK1_SET_SIZE_MEAN';
    case Top3SetSizeMean = 'TOP3_SET_SIZE_MEAN';
}
