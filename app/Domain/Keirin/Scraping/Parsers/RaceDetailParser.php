<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\Parsers;

use App\Domain\Keirin\Scraping\DTO\RaceDetailEntryDto;
use App\Domain\Keirin\Scraping\DTO\RaceDetailPageDto;
use App\Domain\Keirin\Scraping\Exceptions\ParserException;
use App\Domain\Keirin\Scraping\Support\HtmlTextNormalizer;

class RaceDetailParser
{
    public function __construct(private readonly EmbeddedJsonExtractor $embeddedJson) {}

    public function parse(string $html): RaceDetailPageDto
    {
        $pc = $this->embeddedJson->extract($html, 'PC0201');
        $detailPage = $this->embeddedJson->extract($html, 'PJ0315');
        $context = $pc['C0201data'] ?? null;
        if (! is_array($context)) {
            throw new ParserException('PJ0315 PC0201 context was missing.');
        }
        $detail = $context['C0201racedtl'] ?? null;
        $rawEntries = $detailPage['sensyuTypeInfo'] ?? null;
        if (! is_array($detail) || ! is_array($rawEntries) || $rawEntries === []) {
            throw new ParserException('PJ0315 race details or entrants were missing.');
        }

        $summaryEntries = $detail['C0201sensyu'] ?? null;
        if (! is_array($summaryEntries) || count($summaryEntries) !== count($rawEntries)) {
            throw new ParserException('PJ0315 entrant lists did not match.');
        }
        $summaryRegistrationByBike = [];
        foreach ($summaryEntries as $summary) {
            if (! is_array($summary)) {
                throw new ParserException('PJ0315 PC0201 entrant was invalid.');
            }
            $summaryRegistrationByBike[$this->integer($summary['carNum'] ?? null, 'carNum', 1, 9)] = $this->registration($summary['numPlayer'] ?? null);
        }
        if (count($summaryRegistrationByBike) !== count($summaryEntries)) {
            throw new ParserException('PJ0315 PC0201 contained duplicate bike numbers.');
        }

        $entries = [];
        foreach ($rawEntries as $rawEntry) {
            if (! is_array($rawEntry)) {
                throw new ParserException('PJ0315 detailed entrant was invalid.');
            }
            $bikeNumber = $this->integer($rawEntry['syaban'] ?? null, 'syaban', 1, 9);
            $registration = $this->registration($rawEntry['sensyuRegistNo'] ?? null);
            if (($summaryRegistrationByBike[$bikeNumber] ?? null) !== $registration) {
                throw new ParserException("PJ0315 registration number did not match for bike {$bikeNumber}.");
            }
            $entries[] = new RaceDetailEntryDto(
                bikeNumber: $bikeNumber,
                frameNumber: $this->nullableInteger($rawEntry['wakuban'] ?? null, 'wakuban', 1, 9),
                externalPlayerId: $registration,
                playerName: $this->text($rawEntry['sensyuName'] ?? null),
                prefecture: $this->text($rawEntry['huKen'] ?? null),
                previousGrade: $this->text($rawEntry['prevKyuhan'] ?? null),
                grade: $this->text($rawEntry['kyuhan'] ?? null),
                ridingStyle: $this->text($rawEntry['kyakusitu'] ?? null),
                graduationPeriod: $this->text($rawEntry['sotugyouki'] ?? null),
                age: $this->nullableInteger($rawEntry['age'] ?? null, 'age', 1, 120),
                raceScore: $this->decimal($rawEntry['heikinTokuten'] ?? null, 'heikinTokuten'),
                escapeCount: $this->nullableInteger($rawEntry['nigeCnt'] ?? null, 'nigeCnt', 0, 9999),
                sprintCount: $this->nullableInteger($rawEntry['makuriCnt'] ?? null, 'makuriCnt', 0, 9999),
                overtakeCount: $this->nullableInteger($rawEntry['sasiCnt'] ?? null, 'sasiCnt', 0, 9999),
                markCount: $this->nullableInteger($rawEntry['markCnt'] ?? null, 'markCnt', 0, 9999),
                backCount: $this->nullableInteger($rawEntry['backCnt'] ?? null, 'backCnt', 0, 9999),
                homeCount: $this->nullableInteger($rawEntry['homeTori'] ?? null, 'homeTori', 0, 9999),
                startCount: $this->nullableInteger($rawEntry['stTori'] ?? null, 'stTori', 0, 9999),
                winRate: $this->decimal($rawEntry['syouritu'] ?? null, 'syouritu'),
                quinellaRate: $this->decimal($rawEntry['rentairitu2'] ?? null, 'rentairitu2'),
                trioRate: $this->decimal($rawEntry['rentairitu3'] ?? null, 'rentairitu3'),
            );
        }

        return new RaceDetailPageDto(
            raceDate: $this->digits($context['selKaisai'] ?? null, 'selKaisai', 8),
            trackCode: $this->digits($context['selKjyoCd'] ?? null, 'selKjyoCd'),
            raceNumber: $this->integer($context['selRaceNo'] ?? null, 'selRaceNo', 1, 99),
            raceType: $this->text($detail['syumoku'] ?? null),
            distance: $this->nullableInteger($detail['kyori'] ?? null, 'kyori', 1, 10000),
            laps: $this->nullableInteger($detail['syukai'] ?? null, 'syukai', 1, 100),
            raceName: $this->text($detail['nameKyosou'] ?? null),
            startTime: $this->time($detail['aftStartTime'] ?? null, 'aftStartTime'),
            salesCloseTime: $this->time($detail['aftBetTime'] ?? null, 'aftBetTime'),
            entries: $entries,
        );
    }

    private function registration(mixed $value): string
    {
        if (! is_string($value) || preg_match('/^\d+$/', $value) !== 1) {
            throw new ParserException('PJ0315 registration number was invalid.');
        }

        return $value;
    }

    private function digits(mixed $value, string $key, ?int $length = null): string
    {
        if (! is_string($value) && ! is_int($value)) {
            throw new ParserException("PJ0315 {$key} was missing.");
        }
        $string = (string) $value;
        if (preg_match('/^\d+$/', $string) !== 1 || ($length !== null && strlen($string) !== $length)) {
            throw new ParserException("PJ0315 {$key} was invalid.");
        }

        return $string;
    }

    private function integer(mixed $value, string $key, int $minimum, int $maximum): int
    {
        $integer = (int) $this->digits($value, $key);
        if ($integer < $minimum || $integer > $maximum) {
            throw new ParserException("PJ0315 {$key} was outside its valid range.");
        }

        return $integer;
    }

    private function nullableInteger(mixed $value, string $key, int $minimum, int $maximum): ?int
    {
        if ($value === null || $value === '' || $value === '－') {
            return null;
        }

        return $this->integer($value, $key, $minimum, $maximum);
    }

    private function decimal(mixed $value, string $key): ?string
    {
        if ($value === null || $value === '' || $value === '－') {
            return null;
        }
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            throw new ParserException("PJ0315 {$key} was invalid.");
        }
        $string = (string) $value;
        if (preg_match('/^\d+(?:\.\d+)?$/', $string) !== 1) {
            throw new ParserException("PJ0315 {$key} was invalid.");
        }

        return $string;
    }

    private function text(mixed $value): ?string
    {
        return HtmlTextNormalizer::normalize(is_string($value) ? $value : null);
    }

    private function time(mixed $value, string $key): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value) || preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value) !== 1) {
            throw new ParserException("PJ0315 {$key} was invalid.");
        }

        return $value;
    }
}
