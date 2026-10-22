<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\Calculators;

use App\Domain\Keirin\Statistics\DTO\OfficialMethodHistoryProfileDto;
use App\Domain\Keirin\Statistics\DTO\OfficialMethodHistoryResultDto;
use App\Domain\Keirin\Statistics\Enums\StatisticFeatureResultStatus;
use App\Domain\Keirin\Statistics\Enums\StatisticQualityStatus;
use App\Domain\Keirin\Statistics\Support\DeterministicJsonHasher;

class Stat30OfficialMethodMixCalculator
{
    public const STAT_CODE = 'STAT-30';

    public const CALCULATION_VERSION = 'STAT30-OFFICIAL-TOP2-MIX-MVP-v1';

    public function __construct(private readonly DeterministicJsonHasher $hasher) {}

    public function calculate(OfficialMethodHistoryProfileDto $profile): OfficialMethodHistoryResultDto
    {
        $a = $profile->recent['composition'];
        $b = $profile->baseline['composition'];
        $l1 = null;
        if ($a !== null && $b !== null) {
            $l1 = 0.0;
            foreach ($a as $key => $value) {
                $l1 += abs($value - $b[$key]);
            }
        }
        $features = ['profile' => 'OFFICIAL_TOP2_METHOD_MIX_DIFFERENCE',
            'RETROSPECTIVE' => ['recent_5' => $profile->recent, 'preceding_15' => $profile->baseline, 'l1_distance' => $l1],
            'TACTIC_TRANSITION_STATUS' => 'NOT_ESTABLISHED', 'points' => null, 'confidence' => null,
            'prediction_use' => 'NOT_AUTHORIZED'];
        $evidence = $profile->audit + ['target' => $profile->target, 'entry' => $profile->entry,
            'input_as_of' => $profile->inputAsOf->format(DATE_ATOM), 'intervals' => 'LAST_5_AND_PRECEDING_15_DISJOINT'];
        $status = $profile->audit['history_rows'] === 0 ? StatisticFeatureResultStatus::NoHistory
            : ($l1 === null ? StatisticFeatureResultStatus::PartialHistory : StatisticFeatureResultStatus::Partial);

        return new OfficialMethodHistoryResultDto(self::STAT_CODE, self::CALCULATION_VERSION, $status,
            StatisticQualityStatus::Degraded, $features, $evidence, $this->hasher->hash([$evidence, $features]));
    }
}
