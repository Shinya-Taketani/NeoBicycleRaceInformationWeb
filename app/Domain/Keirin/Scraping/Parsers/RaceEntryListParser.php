<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\Parsers;

use App\Domain\Keirin\Scraping\DTO\RaceEntryListPageDto;
use App\Domain\Keirin\Scraping\DTO\RaceListEntryDto;
use App\Domain\Keirin\Scraping\DTO\RaceListRaceDto;
use App\Domain\Keirin\Scraping\Enums\RaceCategory;
use App\Domain\Keirin\Scraping\Exceptions\ParserException;
use App\Domain\Keirin\Scraping\Exceptions\RaceEntryListUnavailableException;
use App\Domain\Keirin\Scraping\Support\HtmlTextNormalizer;
use App\Domain\Keirin\Scraping\Support\RaceCategoryPolicy;
use App\Domain\Keirin\Scraping\Support\RaceEntrantCountPolicy;
use DateTimeImmutable;
use JsonException;

class RaceEntryListParser
{
    private const UNAVAILABLE_TYPE_CANCELLED = 'CANCELLED';

    private const UNAVAILABLE_TYPE_TERMINATED = 'TERMINATED';

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
            $decodedRoot = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
            $root = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ParserException('JSJ017 response was invalid JSON.', previous: $exception);
        }
        if (! is_object($decodedRoot) || ! is_array($root) || $root === []) {
            throw new ParserException('JSJ017 response root was not a JSON object.');
        }
        if (! $this->isZero($root['resultCd'] ?? null)) {
            throw new ParserException('JSJ017 result code was invalid.');
        }

        $this->throwIfRaceDayUnavailable($root);

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

    /** @param array<string, mixed> $root */
    private function throwIfRaceDayUnavailable(array $root): void
    {
        $displayFlag = $root['syusouDispFlag'] ?? null;
        $rawMessage = $root['kaisaiMsg'] ?? null;
        $message = HtmlTextNormalizer::normalize(is_string($rawMessage) ? $rawMessage : null);

        $unavailableType = $this->raceDayUnavailableType($root, $displayFlag, $message);
        if ($unavailableType !== null) {
            $request = $root['reqprm'];
            throw new RaceEntryListUnavailableException(
                reason: RaceEntryListUnavailableException::REASON_RACE_DAY_CANCELLED,
                message: $message,
                evidence: [
                    'resultCd' => $root['resultCd'],
                    'syusouDispFlag' => $displayFlag,
                    'kaisaiMsg' => $message,
                    'reqprm.bkcd' => (string) $request['bkcd'],
                    'reqprm.kday' => (string) $request['kday'],
                    'hasKeirinCd' => array_key_exists('keirinCd', $root),
                    'hasKaisaihi' => array_key_exists('kaisaihi', $root),
                    'rInfoState' => $this->rInfoState($root),
                    'unavailableType' => $unavailableType,
                ],
            );
        }

        if (in_array($displayFlag, [false, 0, '0'], true)
            && $message !== null
            && str_contains($message, '順延')) {
            throw new RaceEntryListUnavailableException(
                reason: RaceEntryListUnavailableException::REASON_RACE_DAY_POSTPONED,
                message: $message,
                evidence: [
                    'resultCd' => $root['resultCd'],
                    'syusouDispFlag' => $displayFlag,
                    'kaisaiMsg' => $message,
                ],
            );
        }
    }

    /** @param array<string, mixed> $root */
    private function raceDayUnavailableType(array $root, mixed $displayFlag, ?string $message): ?string
    {
        $request = $root['reqprm'] ?? null;
        $rawRaces = $root['rInfo'] ?? null;

        if (! in_array($displayFlag, [false, 0, '0'], true)
            || array_key_exists('keirinCd', $root)
            || array_key_exists('kaisaihi', $root)
            || (array_key_exists('rInfo', $root) && $rawRaces !== null && $rawRaces !== [])
            || ! is_array($request)
            || ! $this->isDigits($request['bkcd'] ?? null)
            || ! $this->isDate($request['kday'] ?? null)) {
            return null;
        }

        return match ($message) {
            '中止となりました。', '中止となりました' => self::UNAVAILABLE_TYPE_CANCELLED,
            '打切となりました。', '打切となりました' => self::UNAVAILABLE_TYPE_TERMINATED,
            default => null,
        };
    }

    /** @param array<string, mixed> $root */
    private function rInfoState(array $root): string
    {
        if (! array_key_exists('rInfo', $root)) {
            return 'missing';
        }
        if ($root['rInfo'] === null) {
            return 'null';
        }
        if ($root['rInfo'] === []) {
            return 'empty_array';
        }

        return is_array($root['rInfo']) ? 'populated_array' : 'invalid_type';
    }

    private function isDigits(mixed $value): bool
    {
        return (is_string($value) || is_int($value))
            && preg_match('/^\d+$/', (string) $value) === 1;
    }

    private function isDate(mixed $value): bool
    {
        if (! $this->isDigits($value) || strlen((string) $value) !== 8) {
            return false;
        }

        $date = DateTimeImmutable::createFromFormat('!Ymd', (string) $value);

        return $date instanceof DateTimeImmutable && $date->format('Ymd') === (string) $value;
    }

    private function isZero(mixed $value): bool
    {
        return $value === 0 || $value === '0';
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
