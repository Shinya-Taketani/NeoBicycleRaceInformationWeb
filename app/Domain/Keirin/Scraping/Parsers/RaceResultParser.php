<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\Parsers;

use App\Domain\Keirin\Scraping\DTO\RaceResultDto;
use App\Domain\Keirin\Scraping\Enums\RaceEntryResultStatus;
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

        return $results;
    }

    private function status(?string $rawRank): RaceEntryResultStatus
    {
        return match ($rawRank) {
            '失' => RaceEntryResultStatus::Disqualified,
            '欠' => RaceEntryResultStatus::Withdrawn,
            '棄' => RaceEntryResultStatus::DidNotFinish,
            '落' => RaceEntryResultStatus::Crashed,
            default => is_numeric($rawRank) ? RaceEntryResultStatus::Finished : RaceEntryResultStatus::Unknown,
        };
    }
}
