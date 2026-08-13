<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Calculators;

class Bt02RaceCompletenessEvaluator
{
    /** @param list<int> $officialRaceEntryIds @param list<int> $eligibleRaceEntryIds */
    public function complete(array $officialRaceEntryIds, array $eligibleRaceEntryIds): bool
    {
        $official = array_values(array_unique(array_map('intval', $officialRaceEntryIds)));
        $eligible = array_values(array_unique(array_map('intval', $eligibleRaceEntryIds)));
        sort($official, SORT_NUMERIC);
        sort($eligible, SORT_NUMERIC);

        return count($official) === count($officialRaceEntryIds)
            && count($eligible) === count($eligibleRaceEntryIds)
            && $official === $eligible;
    }
}
