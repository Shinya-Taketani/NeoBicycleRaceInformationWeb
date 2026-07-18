<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\Services;

use App\Domain\Keirin\Scraping\DTO\ExpectedRaceEntrantsDto;
use App\Domain\Keirin\Scraping\DTO\ParsedRaceResultPageDto;
use App\Domain\Keirin\Scraping\Enums\ParsedRaceResultPageStatus;
use App\Domain\Keirin\Scraping\Exceptions\RaceResultCompletenessException;

class RaceResultCompletenessValidator
{
    public function validate(ParsedRaceResultPageDto $page, ExpectedRaceEntrantsDto $expected): void
    {
        if ($page->pageStatus !== ParsedRaceResultPageStatus::ResultsAvailable) {
            throw new RaceResultCompletenessException('Only a results-available page can synchronize race results.');
        }

        if (! $page->resultParsingComplete || ! $page->payoutParsingComplete) {
            throw new RaceResultCompletenessException('Race result or payout parsing was not complete.');
        }

        if ($page->results === []) {
            throw new RaceResultCompletenessException('A results-available page must contain at least one result row.');
        }

        $bikeNumbers = [];
        foreach ($page->results as $result) {
            if ($result->bikeNumber === null || $result->bikeNumber < 1 || $result->bikeNumber > 9) {
                throw new RaceResultCompletenessException('Parsed race result row had an invalid bike number.');
            }

            if (in_array($result->bikeNumber, $bikeNumbers, true)) {
                throw new RaceResultCompletenessException("Parsed bike number {$result->bikeNumber} appeared more than once.");
            }

            $bikeNumbers[] = $result->bikeNumber;
        }

        if (count($bikeNumbers) !== $expected->count) {
            throw new RaceResultCompletenessException('Parsed race result count did not match expected entrants: expected='.$expected->count.', parsed='.count($bikeNumbers).'.');
        }

        sort($bikeNumbers);
        if ($expected->bikeNumbers !== null && $bikeNumbers !== $expected->bikeNumbers) {
            throw new RaceResultCompletenessException('Parsed bike number set did not match race entries.');
        }

        foreach ($page->payouts as $payout) {
            if ($payout->betTypeCode === '' || $payout->combination === '' || $payout->payoutAmount === null) {
                throw new RaceResultCompletenessException('Parsed payout data was incomplete.');
            }
        }
    }
}
