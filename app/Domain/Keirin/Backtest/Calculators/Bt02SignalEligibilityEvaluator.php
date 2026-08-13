<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Calculators;

use App\Domain\Keirin\Backtest\DTO\Bt02SignalFeatureDto;
use App\Domain\Keirin\Backtest\DTO\PairedEligibilitySetDto;
use App\Domain\Keirin\Backtest\Enums\Bt02SignalCohort;
use App\Domain\Keirin\Backtest\Services\Bt02SignalRegistry;

class Bt02SignalEligibilityEvaluator
{
    public const STRICT_POLICY_VERSION = 'BT02-STRICT-v1';

    public const OPERATIONAL_POLICY_VERSION = 'BT02-OPERATIONAL-v1';

    public function __construct(private readonly Bt02SignalRegistry $registry) {}

    public function eligible(string $statCode, Bt02SignalCohort $cohort, Bt02SignalFeatureDto $feature): bool
    {
        $definition = $this->registry->get($statCode);
        if ($feature->status !== 'VALID' || $feature->primaryValue === null) {
            return false;
        }
        if ($cohort === Bt02SignalCohort::Strict) {
            return $feature->qualityStatus === 'FULL';
        }
        if (! $definition->permitsOperationalUse() || $feature->qualityStatus === 'PARTIAL') {
            return false;
        }

        return array_diff($feature->qualityReasons, $definition->operationalAllowedQualityReasons) === [];
    }

    /**
     * @param  list<int>  $baselineEligibleRaceEntryIds
     * @param  list<Bt02SignalFeatureDto>  $signalFeatures
     */
    public function matchedSet(string $statCode, Bt02SignalCohort $cohort, array $baselineEligibleRaceEntryIds, array $signalFeatures): PairedEligibilitySetDto
    {
        $baseline = array_fill_keys(array_map('intval', $baselineEligibleRaceEntryIds), true);
        $matched = [];
        foreach ($signalFeatures as $feature) {
            if (isset($baseline[$feature->raceEntryId]) && $this->eligible($statCode, $cohort, $feature)) {
                $matched[$feature->raceEntryId] = true;
            }
        }
        $ids = array_map('intval', array_keys($matched));
        sort($ids, SORT_NUMERIC);

        return new PairedEligibilitySetDto($ids);
    }
}
