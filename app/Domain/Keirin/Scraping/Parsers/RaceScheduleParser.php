<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\Parsers;

use App\Domain\Keirin\Scraping\DTO\RaceScheduleItemDto;
use App\Domain\Keirin\Scraping\Exceptions\ParserException;
use App\Domain\Keirin\Scraping\Support\HtmlTextNormalizer;
use DateTimeImmutable;
use Symfony\Component\DomCrawler\Crawler;

class RaceScheduleParser
{
    /**
     * @return list<RaceScheduleItemDto>
     */
    public function parse(string $html): array
    {
        $crawler = new Crawler;
        $crawler->addHtmlContent($html, 'UTF-8');
        $year = $crawler->filter('#dispYearData')->attr('value') ?: null;
        $month = $crawler->filter('#dispDayData')->attr('value') ?: null;

        if ($year === null || $month === null) {
            throw new ParserException('Race schedule year/month markers were not found.');
        }

        $items = [];
        $crawler->filter('table.chiku_tbl tbody tr')->each(function (Crawler $row) use (&$items, $year, $month): void {
            $trackLink = $row->filter('td.td_keirinjo a')->first();
            if ($trackLink->count() === 0) {
                return;
            }

            $trackName = HtmlTextNormalizer::normalize($trackLink->text(null, false));
            $href = $trackLink->attr('href') ?? '';
            if ($trackName === null || preg_match('/jocd=([0-9]+)/', $href, $trackMatches) !== 1) {
                return;
            }

            $day = 1;
            $row->filter('td.td_day')->each(function (Crawler $cell) use (&$items, &$day, $year, $month, $trackName, $trackMatches): void {
                $colspan = (int) ($cell->attr('colspan') ?: 1);
                $link = $cell->filter('a[data-pprm-href]')->first();
                if ($link->count() > 0) {
                    $grade = $this->gradeFromCell($cell);
                    $items[] = new RaceScheduleItemDto(
                        trackCode: $trackMatches[1],
                        trackName: $trackName,
                        startsOn: new DateTimeImmutable(sprintf('%04d-%02d-%02d', (int) $year, (int) $month, $day)),
                        durationDays: $colspan,
                        grade: $grade,
                        raceListUrl: $link->attr('data-pprm-href'),
                        encryptedParameter: $link->attr('data-pprm-encp'),
                        dayKind: $link->attr('data-pprm-dkbn'),
                    );
                }
                $day += $colspan;
            });
        });

        if ($items === []) {
            throw new ParserException('No race schedule items were parsed.');
        }

        return $items;
    }

    private function gradeFromCell(Crawler $cell): ?string
    {
        $srcs = $cell->filter('img.gradeIconSize')->each(fn (Crawler $img): ?string => $img->attr('src'));
        $src = $srcs[0] ?? null;
        if ($src === null) {
            return null;
        }

        return match (true) {
            str_contains($src, 'ico_g1') => 'G1',
            str_contains($src, 'ico_g2') => 'G2',
            str_contains($src, 'ico_g3') => 'G3',
            str_contains($src, 'ico_f1') => 'F1',
            str_contains($src, 'ico_f2') => 'F2',
            default => null,
        };
    }
}
