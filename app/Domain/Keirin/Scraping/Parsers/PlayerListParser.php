<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\Parsers;

use App\Domain\Keirin\Scraping\DTO\PlayerListPageDto;
use App\Domain\Keirin\Scraping\DTO\PlayerSummaryDto;
use App\Domain\Keirin\Scraping\Exceptions\ParserException;
use App\Domain\Keirin\Scraping\Support\GradeNormalizer;
use App\Domain\Keirin\Scraping\Support\HtmlTextNormalizer;
use DateTimeImmutable;
use Symfony\Component\DomCrawler\Crawler;

class PlayerListParser
{
    public function parse(string $html, string $sourceUrl): PlayerListPageDto
    {
        $crawler = new Crawler(null, $sourceUrl);
        $crawler->addHtmlContent($html, 'UTF-8');
        $text = HtmlTextNormalizer::normalize($crawler->text(null, false)) ?? '';

        if (str_contains($text, '検索結果が1000件を超えています')) {
            throw new ParserException('Player search result exceeded 1000 records. Narrow search conditions are required.');
        }

        if (! str_contains($text, '選手検索結果')) {
            throw new ParserException('Player search result marker was not found.');
        }

        $players = [];
        $crawler->filterXPath('//td[contains(@onclick, "sensyuLink(")]')->each(function (Crawler $node) use (&$players, $sourceUrl): void {
            $players[] = $this->parsePlayer($node, $sourceUrl);
        });

        if ($players === []) {
            throw new ParserException('No players were parsed from a search result page.');
        }

        $totalCount = $this->extractTotalCount($text);
        $currentPage = $this->extractCurrentPage($crawler, $text);
        $explicitLastPage = $this->extractLastPage($text);
        $lastPage = $this->resolveLastPage(
            explicitLastPage: $explicitLastPage,
            currentPage: $currentPage,
            totalCount: $totalCount,
            parsedPlayerCount: count($players),
            hasNextPageIndicator: $this->hasNextPageIndicator($crawler, $currentPage),
        );

        return new PlayerListPageDto(
            players: $players,
            totalCount: $totalCount,
            currentPage: $currentPage,
            lastPage: $lastPage,
            sourceUpdatedAt: $this->extractUpdatedAt($text),
        );
    }

    private function parsePlayer(Crawler $node, string $sourceUrl): PlayerSummaryDto
    {
        $onclick = $node->attr('onclick') ?? '';
        if (preg_match("/sensyuLink\\('([0-9]+)'\\)/", $onclick, $matches) !== 1) {
            throw new ParserException("Player external ID could not be parsed from onclick: {$onclick}");
        }

        $externalId = $matches[1];
        $outerTables = $node->ancestors()->filter('table.btn25pv2');
        if ($outerTables->count() === 0) {
            throw new ParserException("Player row table could not be determined for {$externalId}.");
        }

        $outerTable = $outerTables->first();
        $nameKana = $this->spanValue($outerTable, 'UNQ_orlabel_6', $externalId);
        $name = $this->requiredSpanValue($outerTable, 'UNQ_orlabel_8', 'name', $externalId);
        $registrationNumber = $this->requiredSpanValue($outerTable, 'UNQ_orlabel_9', 'registration number', $externalId);
        $rawGrade = $this->requiredSpanValue($outerTable, 'UNQ_orlabel_10', 'grade', $externalId);

        if ($registrationNumber !== $externalId) {
            throw new ParserException("Player registration number mismatch for {$externalId}: parsed {$registrationNumber}.");
        }

        $ageDigits = HtmlTextNormalizer::digits($this->spanValue($outerTable, 'UNQ_orlabel_14', $externalId));

        return new PlayerSummaryDto(
            externalPlayerId: $externalId,
            name: $name,
            nameKana: $nameKana,
            grade: GradeNormalizer::normalize($rawGrade),
            district: $this->spanValue($outerTable, 'UNQ_orlabel_11', $externalId),
            prefecture: $this->spanValue($outerTable, 'UNQ_orlabel_12', $externalId),
            graduationPeriod: $this->spanValue($outerTable, 'UNQ_orlabel_13', $externalId),
            age: $ageDigits === null ? null : (int) $ageDigits,
            homeBank: $this->spanValue($outerTable, 'UNQ_orlabel_15', $externalId),
            ridingStyle: $this->spanValue($outerTable, 'UNQ_orlabel_16', $externalId),
            detailUrl: $this->absoluteUrl($sourceUrl, '/pc/racerprofile?snum='.$externalId),
        );
    }

    private function requiredSpanValue(Crawler $outerTable, string $idSuffix, string $field, string $externalId): string
    {
        $value = $this->spanValue($outerTable, $idSuffix, $externalId);
        if ($value === null) {
            throw new ParserException("Player {$field} was missing for {$externalId}.");
        }

        return $value;
    }

    private function spanValue(Crawler $outerTable, string $idSuffix, string $externalId): ?string
    {
        $spans = $outerTable->filter('span[id$="'.$idSuffix.'"]');
        if ($spans->count() > 1) {
            throw new ParserException("Player field {$idSuffix} appeared more than once for {$externalId}.");
        }

        if ($spans->count() === 0) {
            return null;
        }

        return HtmlTextNormalizer::normalize($spans->first()->text(null, false));
    }

    private function extractTotalCount(string $text): ?int
    {
        if (preg_match('/([0-9]+)件見つかりました/u', $text, $matches) === 1) {
            return (int) $matches[1];
        }

        return null;
    }

    private function extractCurrentPage(Crawler $crawler, string $text): int
    {
        if (preg_match('/ページ\s*([0-9]+)\/[0-9]+/u', $text, $matches) === 1) {
            return (int) $matches[1];
        }

        $formPages = $crawler->filter('input[type="hidden"][name="dppg"]')->each(
            fn (Crawler $input): ?int => $this->positiveInteger($input->attr('value')),
        );
        $formPages = array_values(array_unique(array_filter($formPages, fn (?int $page): bool => $page !== null)));

        if (count($formPages) === 1) {
            return $formPages[0];
        }

        throw new ParserException('Player currentPage could not be determined.');
    }

    private function extractLastPage(string $text): ?int
    {
        if (preg_match('/ページ\s*[0-9]+\/([0-9]+)/u', $text, $matches) === 1) {
            return (int) $matches[1];
        }

        return null;
    }

    private function resolveLastPage(
        ?int $explicitLastPage,
        int $currentPage,
        ?int $totalCount,
        int $parsedPlayerCount,
        bool $hasNextPageIndicator,
    ): int {
        if ($explicitLastPage !== null) {
            return $explicitLastPage;
        }

        if ($currentPage === 1 && $totalCount !== null && $totalCount === $parsedPlayerCount && ! $hasNextPageIndicator) {
            return 1;
        }

        if ($totalCount === null) {
            throw new ParserException('Player totalCount and lastPage could not be determined.');
        }

        if ($totalCount > $parsedPlayerCount) {
            throw new ParserException("Player lastPage was missing although totalCount={$totalCount} exceeded parsed players={$parsedPlayerCount}.");
        }

        if ($hasNextPageIndicator) {
            throw new ParserException('Player lastPage was missing although a next-page indicator was present.');
        }

        throw new ParserException('Player lastPage could not be determined safely.');
    }

    private function hasNextPageIndicator(Crawler $crawler, int $currentPage): bool
    {
        foreach ($crawler->filter('a, button, input')->getIterator() as $element) {
            $control = new Crawler($element);
            if ($control->attr('disabled') !== null || $control->attr('aria-disabled') === 'true') {
                continue;
            }

            $label = HtmlTextNormalizer::normalize(implode(' ', array_filter([
                $control->text(null, false),
                $control->attr('value'),
                $control->attr('aria-label'),
                $control->attr('title'),
            ], fn (?string $value): bool => $value !== null))) ?? '';

            if (str_contains($label, '次へ') || str_contains($label, '次ページ') || str_contains($label, '次のページ') || strtoupper($label) === 'NEXT') {
                return true;
            }

            $navigation = implode(' ', array_filter([
                $control->attr('href'),
                $control->attr('onclick'),
                $control->attr('formaction'),
            ], fn (?string $value): bool => $value !== null));
            if (preg_match('/(?:[?&]dppg=|pageChange\(\s*[\'\"]?)([0-9]+)/i', $navigation, $matches) === 1 && (int) $matches[1] > $currentPage) {
                return true;
            }

            if ($control->attr('name') === 'dppg') {
                $targetPage = $this->positiveInteger($control->attr('value'));
                if ($targetPage !== null && $targetPage > $currentPage) {
                    return true;
                }
            }
        }

        return false;
    }

    private function extractUpdatedAt(string $text): ?DateTimeImmutable
    {
        if (preg_match('/([0-9]{4}\/[0-9]{2}\/[0-9]{2}\s+[0-9]{2}:[0-9]{2})\s*更新/u', $text, $matches) === 1) {
            return new DateTimeImmutable(str_replace('/', '-', $matches[1]));
        }

        return null;
    }

    private function absoluteUrl(string $baseUrl, string $path): string
    {
        $parts = parse_url($baseUrl);
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? parse_url((string) config('keirin.base_url'), PHP_URL_HOST);

        return "{$scheme}://{$host}{$path}";
    }

    private function positiveInteger(?string $value): ?int
    {
        if ($value === null || preg_match('/^[1-9][0-9]*$/', $value) !== 1) {
            return null;
        }

        return (int) $value;
    }
}
