<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\Parsers;

use App\Domain\Keirin\Scraping\DTO\ParsedRaceResultPageDto;
use App\Domain\Keirin\Scraping\Enums\ParsedRaceResultPageStatus;
use App\Domain\Keirin\Scraping\Exceptions\ParserException;
use App\Domain\Keirin\Scraping\Support\HtmlTextNormalizer;
use Symfony\Component\DomCrawler\Crawler;

class RaceResultPageParser
{
    public function __construct(
        private readonly RaceResultParser $results,
        private readonly PayoutParser $payouts,
    ) {}

    public function parse(string $html): ParsedRaceResultPageDto
    {
        if ($html === '') {
            throw new ParserException('Race result HTML was empty.');
        }

        $crawler = new Crawler;
        $crawler->addHtmlContent($html, 'UTF-8');
        $text = HtmlTextNormalizer::normalize($crawler->text(null, false)) ?? '';
        $resultMarkerFound = $crawler->filter('#pitbodyBs')->count() > 0;
        $payoutMarkerFound = $crawler->filter('#pitbodyHarai')->count() > 0;
        $explicitNoPayoutMarker = str_contains($text, '払戻なし');
        $sourceHash = hash('sha256', $html);
        try {
            $parserVersion = (string) config('keirin.parser_version');
        } catch (\Throwable) {
            $parserVersion = 'unknown';
        }

        if (str_contains($text, '開催中止')) {
            return new ParsedRaceResultPageDto(
                ParsedRaceResultPageStatus::Cancelled,
                [],
                [],
                $resultMarkerFound,
                $payoutMarkerFound,
                $explicitNoPayoutMarker,
                true,
                true,
                $sourceHash,
                $parserVersion,
            );
        }

        if (str_contains($text, '結果未確定')) {
            return new ParsedRaceResultPageDto(
                ParsedRaceResultPageStatus::UnderReview,
                [],
                [],
                $resultMarkerFound,
                $payoutMarkerFound,
                $explicitNoPayoutMarker,
                true,
                true,
                $sourceHash,
                $parserVersion,
            );
        }

        if (str_contains($text, '結果未掲載')) {
            return new ParsedRaceResultPageDto(
                ParsedRaceResultPageStatus::Unavailable,
                [],
                [],
                $resultMarkerFound,
                $payoutMarkerFound,
                $explicitNoPayoutMarker,
                true,
                true,
                $sourceHash,
                $parserVersion,
            );
        }

        $results = $this->results->parse($html);
        if ($results === []) {
            throw new ParserException('Race result page had no parsed result rows.');
        }

        $payouts = $this->payouts->parse($html);

        return new ParsedRaceResultPageDto(
            ParsedRaceResultPageStatus::ResultsAvailable,
            $results,
            $payouts,
            $resultMarkerFound,
            $payoutMarkerFound,
            $explicitNoPayoutMarker,
            true,
            true,
            $sourceHash,
            $parserVersion,
        );
    }
}
