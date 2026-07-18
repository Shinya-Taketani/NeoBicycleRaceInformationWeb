<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\Parsers;

use App\Domain\Keirin\Scraping\DTO\RaceDayMetadataPageDto;
use App\Domain\Keirin\Scraping\DTO\RaceEntryListPageDto;
use App\Domain\Keirin\Scraping\DTO\RaceListRaceDto;
use App\Domain\Keirin\Scraping\DTO\RaceParameterDto;
use App\Domain\Keirin\Scraping\Exceptions\ParserException;

class RaceListConsistencyValidator
{
    /** @return array<int,RaceParameterDto> */
    public function validate(RaceDayMetadataPageDto $metadata, RaceEntryListPageDto $entries): array
    {
        if ($metadata->selectedDate !== $entries->raceDate || $metadata->trackCode !== $entries->trackCode) {
            throw new ParserException('JSJ001 and JSJ017 race context did not match.');
        }
        if (count($metadata->races) !== count($entries->races)) {
            throw new ParserException('JSJ001 and JSJ017 race counts did not match.');
        }

        $numbers = array_map(fn (RaceListRaceDto $race): int => $race->raceNumber, $entries->races);
        if (count($numbers) !== count(array_unique($numbers))) {
            throw new ParserException('JSJ017 contained duplicate race numbers.');
        }
        $expected = range(1, count($numbers));
        if ($numbers !== $expected) {
            throw new ParserException('JSJ017 race numbers were not ascending and contiguous from 1.');
        }

        $parameters = [];
        foreach ($numbers as $index => $raceNumber) {
            $parameters[$raceNumber] = $metadata->races[$index];
        }

        return $parameters;
    }
}
