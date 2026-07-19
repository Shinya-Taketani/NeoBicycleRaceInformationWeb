<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\Parsers;

use App\Domain\Keirin\Scraping\DTO\RaceEntryListPageDto;
use App\Domain\Keirin\Scraping\DTO\RaceListEntryDto;
use App\Domain\Keirin\Scraping\DTO\RaceListRaceDto;
use App\Domain\Keirin\Scraping\Enums\RaceCategory;
use App\Domain\Keirin\Scraping\Exceptions\ParserException;
use App\Domain\Keirin\Scraping\Support\HtmlTextNormalizer;
use App\Domain\Keirin\Scraping\Support\RaceCategoryPolicy;
use App\Domain\Keirin\Scraping\Support\RaceEntrantCountPolicy;
use JsonException;

class RaceEntryListParser
{
    private readonly RaceCategoryPolicy $categories;

    private readonly RaceEntrantCountPolicy $entrantCounts;

    public function __construct(
        ?RaceCategoryPolicy $categories = null,
        ?RaceEntrantCountPolicy $entrantCounts = null,
    ) {
        $this->categories = $categories ?? new RaceCategoryPolicy;
        $this->entrantCounts = $entrantCounts ?? new RaceEntrantCountPolicy;
    }

    public function parse(string $json): RaceEntryListPageDto
    {
        try {
            $root = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ParserException('JSJ017 response was invalid JSON.', previous: $exception);
        }
        if (! is_array($root) || (int) ($root['resultCd'] ?? -1) !== 0) {
            throw new ParserException('JSJ017 result code was invalid.');
        }

        $trackCode = $this->digits($root['keirinCd'] ?? null, 'keirinCd');
        $raceDate = $this->digits($root['kaisaihi'] ?? null, 'kaisaihi', 8);
        $request = $root['reqprm'] ?? null;
        if (! is_array($request)
            || $this->digits($request['bkcd'] ?? null, 'reqprm.bkcd') !== $trackCode
            || $this->digits($request['kday'] ?? null, 'reqprm.kday', 8) !== $raceDate) {
            throw new ParserException('JSJ017 request parameters did not match its response context.');
        }

        $rawRaces = $root['rInfo'] ?? null;
        if (! is_array($rawRaces) || $rawRaces === []) {
            throw new ParserException('JSJ017 rInfo was missing.');
        }

        $races = [];
        foreach ($rawRaces as $rawRace) {
            if (! is_array($rawRace)) {
                throw new ParserException('JSJ017 race was invalid.');
            }
            $raceNumber = $this->integer($rawRace['raceNo'] ?? null, 'raceNo', 1, 99);
            $raceType = HtmlTextNormalizer::normalize(is_string($rawRace['syumoku'] ?? null) ? $rawRace['syumoku'] : null);
            $category = $this->categories->classify($raceType);
            $entries = [];
            $rawEntries = $rawRace['sInfo'] ?? null;
            if (! is_array($rawEntries) || $rawEntries === []) {
                throw new ParserException('JSJ017 race entrants were missing.');
            }
            foreach ($rawEntries as $rawEntry) {
                if (! is_array($rawEntry)) {
                    throw new ParserException('JSJ017 race entrant was invalid.');
                }
                $externalPlayerId = $rawEntry['senNo'] ?? null;
                if (! is_string($externalPlayerId) || preg_match('/^\d+$/', $externalPlayerId) !== 1) {
                    throw new ParserException('JSJ017 player registration number was invalid.');
                }
                $entries[] = new RaceListEntryDto(
                    bikeNumber: $this->integer($rawEntry['syaban'] ?? null, 'syaban', 1, 9),
                    externalPlayerId: $externalPlayerId,
                    playerName: HtmlTextNormalizer::normalize(is_string($rawEntry['senName'] ?? null) ? $rawEntry['senName'] : null),
                    prefecture: HtmlTextNormalizer::normalize(is_string($rawEntry['huken'] ?? null) ? $rawEntry['huken'] : null),
                    ridingStyle: HtmlTextNormalizer::normalize(is_string($rawEntry['kyaku'] ?? null) ? $rawEntry['kyaku'] : null),
                );
            }

            $bikeNumbers = array_map(fn (RaceListEntryDto $entry): int => $entry->bikeNumber, $entries);
            if (count($bikeNumbers) !== count(array_unique($bikeNumbers))) {
                throw new ParserException('JSJ017 contained duplicate bike numbers.');
            }

            if ($category === RaceCategory::Men) {
                $this->entrantCounts->assertSupported(count($entries), "JSJ017 race {$raceNumber}");
            }

            $races[] = new RaceListRaceDto(
                raceNumber: $raceNumber,
                raceType: $raceType,
                salesCloseTime: $this->time($rawRace['denTime'] ?? null, 'denTime'),
                startTime: $this->time($rawRace['stTime'] ?? null, 'stTime'),
                resultAvailable: (string) ($rawRace['resultFlg'] ?? '') === '1',
                category: $category,
                entries: $entries,
            );
        }

        return new RaceEntryListPageDto(
            trackCode: $trackCode,
            raceDate: $raceDate,
            lastUpdatedAt: HtmlTextNormalizer::normalize(is_string($root['lastUpdateTime'] ?? null) ? $root['lastUpdateTime'] : null),
            races: $races,
        );
    }

    private function digits(mixed $value, string $key, ?int $length = null): string
    {
        if (! is_string($value) && ! is_int($value)) {
            throw new ParserException("JSJ017 {$key} was missing.");
        }
        $string = (string) $value;
        if (preg_match('/^\d+$/', $string) !== 1 || ($length !== null && strlen($string) !== $length)) {
            throw new ParserException("JSJ017 {$key} was invalid.");
        }

        return $string;
    }

    private function integer(mixed $value, string $key, int $minimum, int $maximum): int
    {
        $digits = $this->digits($value, $key);
        $integer = (int) $digits;
        if ($integer < $minimum || $integer > $maximum) {
            throw new ParserException("JSJ017 {$key} was outside its valid range.");
        }

        return $integer;
    }

    private function time(mixed $value, string $key): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value) || preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value) !== 1) {
            throw new ParserException("JSJ017 {$key} was invalid.");
        }

        return $value;
    }
}
