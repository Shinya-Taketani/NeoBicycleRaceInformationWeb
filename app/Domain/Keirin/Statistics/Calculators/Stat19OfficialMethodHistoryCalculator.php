<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\Calculators;

use App\Domain\Keirin\Statistics\DTO\OfficialMethodHistoryProfileDto;
use App\Domain\Keirin\Statistics\DTO\OfficialMethodHistoryResultDto;
use App\Domain\Keirin\Statistics\Enums\StatisticFeatureResultStatus;
use App\Domain\Keirin\Statistics\Enums\StatisticQualityStatus;
use App\Domain\Keirin\Statistics\Support\DeterministicJsonHasher;

class Stat19OfficialMethodHistoryCalculator
{
    public const STAT_CODE = 'STAT-19';

    public const CALCULATION_VERSION = 'STAT19-OFFICIAL-TOP2-METHOD-MVP-v2-PR119';

    public function __construct(private readonly DeterministicJsonHasher $hasher) {}

    public function calculate(OfficialMethodHistoryProfileDto $profile): OfficialMethodHistoryResultDto
    {
        $complete = array_filter($profile->windows, fn ($w) => $w['status'] === 'AVAILABLE');
        $status = $profile->audit['history_rows'] === 0 ? StatisticFeatureResultStatus::NoHistory
            : ($complete === [] ? StatisticFeatureResultStatus::PartialHistory : StatisticFeatureResultStatus::Partial);
        $features = ['profile' => 'OFFICIAL_TOP2_METHOD_OUTCOME_PROFILE', 'RETROSPECTIVE' => $profile->windows,
            'recent_aggressiveness' => null, 'recent_aggressiveness_reason' => 'ACTION_EVIDENCE_NOT_ESTABLISHED',
            'points' => null, 'confidence' => null, 'prediction_use' => 'NOT_AUTHORIZED'];
        $evidence = $profile->audit + ['target' => $profile->target, 'entry' => $profile->entry,
            'input_as_of' => $profile->inputAsOf->format(DATE_ATOM)];

        return new OfficialMethodHistoryResultDto(self::STAT_CODE, self::CALCULATION_VERSION, $status,
            StatisticQualityStatus::Degraded, $features, $evidence, $this->hasher->hash([$evidence, $features]));
    }
}
