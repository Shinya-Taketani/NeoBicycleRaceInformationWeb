<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\Parsers;

use App\Domain\Keirin\Scraping\DTO\RaceResultDto;
use App\Domain\Keirin\Scraping\Enums\RaceEntryResultStatus;
use App\Domain\Keirin\Scraping\Exceptions\ParserException;
use App\Domain\Keirin\Scraping\Support\HtmlTextNormalizer;
use Symfony\Component\DomCrawler\Crawler;

class RaceResultParser
{
    /**
     * @return list<RaceResultDto>
     */
    public function parse(string $html): array
    {
        $crawler = new Crawler;
        $crawler->addHtmlContent($html, 'UTF-8');
        $results = [];
        $text = HtmlTextNormalizer::normalize($crawler->text(null, false)) ?? '';

        if ($crawler->filter('#pitbodyBs')->count() === 0) {
            throw new ParserException('Race result table marker was not found.');
        }

        $crawler->filter('#pitbodyBs tr')->each(function (Crawler $row) use (&$results): void {
            $values = $row->filter('td')->each(fn (Crawler $cell): ?string => HtmlTextNormalizer::normalize($cell->text(null, false)));
            if (count($values) < 4) {
                return;
            }

            $rawRank = $values[0] ?? null;
            $results[] = new RaceResultDto(
                rank: is_numeric($rawRank) ? (int) $rawRank : null,
                bikeNumber: is_numeric($values[2] ?? null) ? (int) $values[2] : null,
                playerName: $values[3] ?? null,
                status: $this->status($rawRank),
                winningTechnique: null,
                rawText: implode(' ', array_filter($values)),
            );
        });

        if ($results === [] && ! str_contains($text, '中止') && ! str_contains($text, '結果未掲載') && ! str_contains($text, '結果未確定')) {
            throw new ParserException('Race result table exists, but no result rows were parsed.');
        }

        $rankCounts = [];
        foreach ($results as $result) {
            if ($result->rank !== null) {
                $rankCounts[$result->rank] = ($rankCounts[$result->rank] ?? 0) + 1;
            }
        }

        $results = array_map(
            fn (RaceResultDto $result): RaceResultDto => $result->rank !== null && ($rankCounts[$result->rank] ?? 0) > 1
                ? new RaceResultDto($result->rank, $result->bikeNumber, $result->playerName, RaceEntryResultStatus::Tied, $result->winningTechnique, $result->rawText)
                : $result,
            $results,
        );

        return $results;
    }

    private function status(?string $rawRank): RaceEntryResultStatus
    {
        return match ($rawRank) {
            '失' => RaceEntryResultStatus::Disqualified,
            '欠', '欠場' => RaceEntryResultStatus::DidNotStart,
            '取消' => RaceEntryResultStatus::Withdrawn,
            '棄' => RaceEntryResultStatus::DidNotFinish,
            '落' => RaceEntryResultStatus::Crashed,
            '未着' => RaceEntryResultStatus::DidNotFinish,
            default => is_numeric($rawRank) ? RaceEntryResultStatus::Finished : RaceEntryResultStatus::Unknown,
        };
    }
}
