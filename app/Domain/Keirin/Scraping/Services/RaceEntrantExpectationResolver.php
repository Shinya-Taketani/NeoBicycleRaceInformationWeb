<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\Services;

use App\Domain\Keirin\Scraping\DTO\ExpectedRaceEntrantsDto;
use App\Domain\Keirin\Scraping\Enums\RaceEntrantExpectationSource;
use App\Domain\Keirin\Scraping\Exceptions\RaceResultCompletenessException;
use App\Models\Race;
use App\Models\RaceEntry;

class RaceEntrantExpectationResolver
{
    public function resolve(Race $race, bool $lockForUpdate = false): ExpectedRaceEntrantsDto
    {
        $query = RaceEntry::query()
            ->where('race_id', $race->id)
            ->orderBy('bike_number');

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $this->resolveFromValues(
            $race->entrant_count === null ? null : (int) $race->entrant_count,
            $query->pluck('bike_number')->all(),
        );
    }

    /**
     * @param  list<int|string|null>  $entryBikeNumbers
     */
    public function resolveFromValues(?int $entrantCount, array $entryBikeNumbers): ExpectedRaceEntrantsDto
    {
        if ($entryBikeNumbers !== []) {
            $bikeNumbers = $this->normalizeBikeNumbers($entryBikeNumbers);
            $entryCount = count($bikeNumbers);
            $this->assertSupportedCount($entryCount);

            if ($entrantCount !== null && $entrantCount !== $entryCount) {
                throw new RaceResultCompletenessException("Race entry count mismatch: entries={$entryCount}, entrant_count={$entrantCount}.");
            }

            sort($bikeNumbers);

            return new ExpectedRaceEntrantsDto(
                count: $entryCount,
                bikeNumbers: $bikeNumbers,
                source: RaceEntrantExpectationSource::RaceEntries,
            );
        }

        if ($entrantCount === null) {
            throw new RaceResultCompletenessException('Expected race entrant count could not be determined.');
        }

        $this->assertSupportedCount($entrantCount);

        return new ExpectedRaceEntrantsDto(
            count: $entrantCount,
            bikeNumbers: null,
            source: RaceEntrantExpectationSource::RaceEntrantCount,
        );
    }

    /**
     * @param  list<int|string|null>  $rawBikeNumbers
     * @return list<int>
     */
    private function normalizeBikeNumbers(array $rawBikeNumbers): array
    {
        $bikeNumbers = [];

        foreach ($rawBikeNumbers as $rawBikeNumber) {
            if ($rawBikeNumber === null || (is_string($rawBikeNumber) && preg_match('/^[0-9]+$/', $rawBikeNumber) !== 1) || (! is_int($rawBikeNumber) && ! is_string($rawBikeNumber))) {
                throw new RaceResultCompletenessException('Race entry bike number was missing or invalid.');
            }

            $bikeNumber = (int) $rawBikeNumber;
            if ($bikeNumber < 1 || $bikeNumber > 9) {
                throw new RaceResultCompletenessException("Race entry bike number was out of range: {$bikeNumber}.");
            }

            if (in_array($bikeNumber, $bikeNumbers, true)) {
                throw new RaceResultCompletenessException("Race entry bike number appeared more than once: {$bikeNumber}.");
            }

            $bikeNumbers[] = $bikeNumber;
        }

        return $bikeNumbers;
    }

    private function assertSupportedCount(int $count): void
    {
        if (! in_array($count, [5, 6, 7, 8, 9], true)) {
            throw new RaceResultCompletenessException("Unsupported entrant count: {$count}.");
        }
    }
}
