<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\DTO;

readonly class PairedEligibilitySetDto
{
    /** @param list<int> $raceEntryIds */
    public function __construct(public array $raceEntryIds) {}

    /** @return list<int> */
    public function baselineRaceEntryIds(): array
    {
        return $this->raceEntryIds;
    }

    /** @return list<int> */
    public function incrementalRaceEntryIds(): array
    {
        return $this->raceEntryIds;
    }
}
