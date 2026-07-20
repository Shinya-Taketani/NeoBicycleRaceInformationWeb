<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\Parsers;

use App\Domain\Keirin\Scraping\DTO\AutomatedRaceResultPageDto;
use App\Domain\Keirin\Scraping\DTO\ParsedRaceResultPageDto;
use App\Domain\Keirin\Scraping\DTO\RacePayoutDto;
use App\Domain\Keirin\Scraping\DTO\RaceResultDto;
use App\Domain\Keirin\Scraping\Enums\ParsedRaceResultPageStatus;
use App\Domain\Keirin\Scraping\Enums\RaceEntryResultStatus;
use App\Domain\Keirin\Scraping\Enums\RaceResultStatus;
use App\Domain\Keirin\Scraping\Exceptions\ParserException;
use App\Domain\Keirin\Scraping\Support\HtmlTextNormalizer;
use App\Domain\Keirin\Scraping\Support\RaceResultStatusPolicy;

class RaceLiveResultParser
{
    private const NON_PAYABLE_PAYOUT_AMOUNTS = [
        '【未発売】',
        '未発売',
        '【全返還】',
        '全返還',
    ];

    private const PAYOUT_TYPES = [
        'WH2HaraiGakuDispItemSubData' => 'FRAME_QUINELLA',
        'WT2HaraiGakuDispItemSubData' => 'FRAME_EXACTA',
        'SH2HaraiGakuDispItemSubData' => 'QUINELLA',
        'ST2HaraiGakuDispItemSubData' => 'EXACTA',
        'RH3HaraiGakuDispItemSubData' => 'TRIO',
        'RT3HaraiGakuDispItemSubData' => 'TRIFECTA',
        'WHaraiGakuDispItemSubData' => 'QUINELLA_PLACE',
    ];

    private readonly RaceResultStatusPolicy $statuses;

    public function __construct(
        private readonly EmbeddedJsonExtractor $embeddedJson,
        ?RaceResultStatusPolicy $statuses = null,
    ) {
        $this->statuses = $statuses ?? new RaceResultStatusPolicy;
    }

    public function parse(string $html): AutomatedRaceResultPageDto
    {
        $pc = $this->embeddedJson->extract($html, 'PC0201');
        $result = $this->embeddedJson->extract($html, 'PJ0326');
        $context = $pc['C0201data'] ?? null;
        if (! is_array($context)) {
            throw new ParserException('PJ0326 PC0201 context was missing.');
        }

        $decision = $this->statuses->decide($context, $result);
        $resultMarker = $this->boolean($result['tyakujyunDispFlg'] ?? false);
        $payoutMarker = $this->boolean($result['haraiGakuDispFlg'] ?? false);
        $parseResults = in_array($decision->status, [RaceResultStatus::Provisional, RaceResultStatus::Confirmed, RaceResultStatus::Corrected], true);
        $pageStatus = match ($decision->status) {
            RaceResultStatus::UnderReview => ParsedRaceResultPageStatus::UnderReview,
            RaceResultStatus::Cancelled => ParsedRaceResultPageStatus::Cancelled,
            RaceResultStatus::Provisional, RaceResultStatus::Confirmed, RaceResultStatus::Corrected => ParsedRaceResultPageStatus::ResultsAvailable,
            default => ParsedRaceResultPageStatus::Unavailable,
        };
        $results = $parseResults ? $this->results($result['tyakujyunItemSubData'] ?? null) : [];
        $payouts = $parseResults && $payoutMarker ? $this->payouts($result['haraiGakuSubData'] ?? null) : [];

        return new AutomatedRaceResultPageDto(
            raceDate: $this->digits($context['selKaisai'] ?? null, 'selKaisai', 8),
            trackCode: $this->digits($context['selKjyoCd'] ?? null, 'selKjyoCd'),
            raceNumber: $this->integer($context['selRaceNo'] ?? null, 'selRaceNo', 1, 99),
            lastUpdatedAt: $this->text($result['lastUpdateTime'] ?? null),
            weather: $this->text($result['tenki'] ?? null),
            windSpeed: $this->text($result['husoku'] ?? null),
            detectedStatus: $decision->status,
            statusEvidence: $decision->evidence,
            resultPage: new ParsedRaceResultPageDto(
                pageStatus: $pageStatus,
                results: $results,
                payouts: $payouts,
                resultMarkerFound: $resultMarker,
                payoutMarkerFound: $payoutMarker,
                explicitNoPayoutMarker: $resultMarker && ! $payoutMarker,
                resultParsingComplete: $parseResults && $resultMarker,
                payoutParsingComplete: $parseResults && ($payoutMarker || ! $resultMarker),
                sourceHash: hash('sha256', $html),
                parserVersion: (string) config('keirin.parser_version'),
            ),
        );
    }

    /** @return list<RaceResultDto> */
    private function results(mixed $rawResults): array
    {
        if (! is_array($rawResults) || $rawResults === []) {
            throw new ParserException('PJ0326 result rows were missing.');
        }

        $rows = [];
        $rankCounts = [];
        foreach ($rawResults as $rawResult) {
            if (! is_array($rawResult)) {
                throw new ParserException('PJ0326 result row was invalid.');
            }
            $rank = $this->nullableInteger($rawResult['tyaku'] ?? null, 'tyaku', 1, 99);
            if ($rank !== null) {
                $rankCounts[$rank] = ($rankCounts[$rank] ?? 0) + 1;
            }
            $rows[] = [$rawResult, $rank];
        }

        $results = [];
        foreach ($rows as [$rawResult, $rank]) {
            $states = $rawResult['kojinStateItemSubData'] ?? [];
            if (! is_array($states)) {
                throw new ParserException('PJ0326 individual state was invalid.');
            }
            $stateText = implode(' ', array_filter(array_map(function (mixed $state): ?string {
                if (! is_array($state)) {
                    throw new ParserException('PJ0326 individual state row was invalid.');
                }

                return $this->text($state['kojinState'] ?? null);
            }, $states)));

            $status = $rank !== null
                ? (($rankCounts[$rank] ?? 0) > 1 ? RaceEntryResultStatus::Tied : RaceEntryResultStatus::Finished)
                : $this->status($stateText);
            $rawText = json_encode($rawResult, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (! is_string($rawText)) {
                throw new ParserException('PJ0326 result row could not be serialized.');
            }
            $results[] = new RaceResultDto(
                rank: $rank,
                bikeNumber: $this->integer($rawResult['syaban'] ?? null, 'syaban', 1, 9),
                playerName: $this->text($rawResult['sensyuName'] ?? null),
                status: $status,
                winningTechnique: $this->text($rawResult['kimarite'] ?? null),
                rawText: $rawText,
                externalPlayerId: $this->registration($rawResult['sensyuRegistNo'] ?? null),
                age: $this->nullableInteger($rawResult['age'] ?? null, 'age', 1, 120),
                prefecture: $this->text($rawResult['huken'] ?? null),
                graduationPeriod: $this->text($rawResult['sotugyouki'] ?? null),
                grade: $this->text($rawResult['kyuhan'] ?? null),
                margin: $this->text($rawResult['tyakusa'] ?? null),
                finishTime: $this->text($rawResult['agari'] ?? null),
                backHome: $this->text($rawResult['BH'] ?? null),
                lineRank: $this->text($rawResult['inLineJyuni'] ?? null),
                individualStates: array_values(array_filter(array_map(fn (mixed $state): ?string => is_array($state) ? $this->text($state['kojinState'] ?? null) : null, $states))),
            );
        }

        return $results;
    }

    /** @return list<RacePayoutDto> */
    private function payouts(mixed $rawPayouts): array
    {
        if (! is_array($rawPayouts)) {
            throw new ParserException('PJ0326 payout data was missing.');
        }
        $payouts = [];
        foreach (self::PAYOUT_TYPES as $key => $code) {
            $rows = $rawPayouts[$key] ?? [];
            if (! is_array($rows)) {
                throw new ParserException("PJ0326 payout {$key} was invalid.");
            }
            foreach ($rows as $index => $row) {
                if (! is_array($row)) {
                    throw new ParserException("PJ0326 payout {$key} row was invalid.");
                }
                if ($this->isExplicitlyNonPayablePayout($row)) {
                    continue;
                }
                $combination = $this->text($row['kumiBan'] ?? null);
                $amount = $this->unsignedIntegerText($row['haraiGaku'] ?? null, 'haraiGaku');
                if ($combination === null || $amount === null) {
                    throw new ParserException("PJ0326 payout {$key} was incomplete.");
                }
                $payouts[] = new RacePayoutDto(
                    betTypeCode: $code,
                    combination: $combination,
                    payoutAmount: $amount,
                    popularity: $this->unsignedIntegerText($row['ninki'] ?? null, 'ninki'),
                    sequence: $index + 1,
                );
            }
        }

        return $payouts;
    }

    private function isExplicitlyNonPayablePayout(array $row): bool
    {
        $combinationDisplayed = $row['kumiDispFlg'] ?? null;
        $amount = $this->text($row['haraiGaku'] ?? null);

        return in_array($combinationDisplayed, [false, 0, '0'], true)
            && in_array($amount, self::NON_PAYABLE_PAYOUT_AMOUNTS, true);
    }

    private function status(string $state): RaceEntryResultStatus
    {
        return match (true) {
            str_contains($state, '失格') => RaceEntryResultStatus::Disqualified,
            str_contains($state, '落車') => RaceEntryResultStatus::Crashed,
            str_contains($state, '欠場') => RaceEntryResultStatus::DidNotStart,
            str_contains($state, '取消') => RaceEntryResultStatus::Withdrawn,
            str_contains($state, '棄権') || str_contains($state, '未完走') => RaceEntryResultStatus::DidNotFinish,
            default => throw new ParserException("PJ0326 blank rank had an unknown state: {$state}"),
        };
    }

    private function digits(mixed $value, string $key, ?int $length = null): string
    {
        if (! is_string($value) && ! is_int($value)) {
            throw new ParserException("PJ0326 {$key} was missing.");
        }
        $string = (string) $value;
        if (preg_match('/^\d+$/', $string) !== 1 || ($length !== null && strlen($string) !== $length)) {
            throw new ParserException("PJ0326 {$key} was invalid.");
        }

        return $string;
    }

    private function integer(mixed $value, string $key, int $minimum, int $maximum): int
    {
        $integer = (int) $this->digits($value, $key);
        if ($integer < $minimum || $integer > $maximum) {
            throw new ParserException("PJ0326 {$key} was outside its valid range.");
        }

        return $integer;
    }

    private function nullableInteger(mixed $value, string $key, int $minimum, int $maximum): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->integer($value, $key, $minimum, $maximum);
    }

    private function unsignedIntegerText(mixed $value, string $key): ?int
    {
        $text = $this->text($value);
        if ($text === null) {
            return null;
        }
        $digits = preg_replace('/\D+/u', '', mb_convert_kana($text, 'n', 'UTF-8'));
        if (! is_string($digits) || $digits === '') {
            throw new ParserException("PJ0326 {$key} was invalid.");
        }

        return (int) $digits;
    }

    private function registration(mixed $value): string
    {
        if (! is_string($value) || preg_match('/^\d+$/', $value) !== 1) {
            throw new ParserException('PJ0326 registration number was invalid.');
        }

        return $value;
    }

    private function text(mixed $value): ?string
    {
        return HtmlTextNormalizer::normalize(is_string($value) || is_int($value) || is_float($value) ? (string) $value : null);
    }

    private function boolean(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1';
    }
}
