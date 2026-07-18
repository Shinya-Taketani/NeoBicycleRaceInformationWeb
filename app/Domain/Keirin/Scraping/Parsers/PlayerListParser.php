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
            $onclick = $node->attr('onclick') ?? '';
            if (preg_match("/sensyuLink\\('([0-9]+)'\\)/", $onclick, $matches) !== 1) {
                return;
            }

            $externalId = $matches[1];
            $nameKana = HtmlTextNormalizer::normalize($node->filter('p')->eq(0)->text(null, false));
            $name = HtmlTextNormalizer::normalize($node->filter('p')->eq(1)->text(null, false));
            if ($name === null) {
                throw new ParserException("Player name was missing for {$externalId}.");
            }

            $outerTable = $node->ancestors()->filter('table.btn25pv2')->first();
            $spans = $outerTable->filter('span')->each(
                fn (Crawler $span): ?string => HtmlTextNormalizer::normalize($span->text(null, false))
            );

            $registrationNumber = $spans[2] ?? $externalId;
            $grade = GradeNormalizer::normalize($spans[3] ?? null);
            $district = HtmlTextNormalizer::normalize($spans[4] ?? null);
            $prefecture = HtmlTextNormalizer::normalize($spans[5] ?? null);
            $graduationPeriod = HtmlTextNormalizer::normalize($spans[6] ?? null);
            $age = isset($spans[7]) ? (int) HtmlTextNormalizer::digits($spans[7]) : null;
            $homeBank = HtmlTextNormalizer::normalize($spans[8] ?? null);
            $ridingStyle = HtmlTextNormalizer::normalize($spans[9] ?? null);

            $players[] = new PlayerSummaryDto(
                externalPlayerId: $registrationNumber,
                name: $name,
                nameKana: $nameKana,
                grade: $grade,
                district: $district,
                prefecture: $prefecture,
                graduationPeriod: $graduationPeriod,
                age: $age,
                homeBank: $homeBank,
                ridingStyle: $ridingStyle,
                detailUrl: $this->absoluteUrl($sourceUrl, '/pc/racerprofile?snum='.$registrationNumber),
            );
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
