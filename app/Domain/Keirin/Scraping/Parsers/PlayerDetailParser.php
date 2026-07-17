<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\Parsers;

use App\Domain\Keirin\Scraping\DTO\PlayerDetailDto;
use App\Domain\Keirin\Scraping\DTO\PlayerGradeHistoryDto;
use App\Domain\Keirin\Scraping\DTO\PlayerStatsDto;
use App\Domain\Keirin\Scraping\Exceptions\ParserException;
use App\Domain\Keirin\Scraping\Support\GradeNormalizer;
use App\Domain\Keirin\Scraping\Support\HtmlTextNormalizer;
use DateTimeImmutable;
use Symfony\Component\DomCrawler\Crawler;

class PlayerDetailParser
{
    public function parse(string $html, string $sourceUrl): PlayerDetailDto
    {
        $crawler = new Crawler(null, $sourceUrl);
        $crawler->addHtmlContent($html, 'UTF-8');
        $playerNoNode = $crawler->filter('#PlayerNo');

        if ($playerNoNode->count() === 0) {
            throw new ParserException('Player detail registration number marker was not found.');
        }

        $profileTable = $playerNoNode->ancestors()->filter('table')->first();
        $rows = $profileTable->filter('tr');

        if ($rows->count() < 4) {
            throw new ParserException('Player profile table structure was not recognized.');
        }

        $basicValues = $this->rowValues($rows->eq(1));
        $gradeValues = $this->rowValues($rows->eq(3));
        $externalId = HtmlTextNormalizer::normalize($basicValues[6] ?? null);
        $name = HtmlTextNormalizer::normalize($basicValues[0] ?? null);

        if ($externalId === null || $name === null) {
            throw new ParserException('Required player detail fields were missing.');
        }

        return new PlayerDetailDto(
            externalPlayerId: $externalId,
            name: $name,
            nameKana: HtmlTextNormalizer::normalize($basicValues[1] ?? null),
            prefecture: HtmlTextNormalizer::normalize($basicValues[2] ?? null),
            birthDate: $this->date($basicValues[3] ?? null),
            gender: $this->gender($basicValues[5] ?? null),
            registrationNumber: $externalId,
            graduationPeriod: HtmlTextNormalizer::normalize($gradeValues[0] ?? null),
            grade: GradeNormalizer::normalize($gradeValues[1] ?? null),
            gradeAssignedOn: $this->date($gradeValues[2] ?? null),
            nextGrade: GradeNormalizer::normalize($gradeValues[3] ?? null),
            ridingStyle: HtmlTextNormalizer::normalize($gradeValues[4] ?? null),
            currentScore: $this->float($gradeValues[5] ?? null),
            recentStats: $this->recentStats($crawler),
            gradeHistories: $this->gradeHistories($crawler),
            sourceUpdatedAt: $this->sourceUpdatedAt($crawler),
            sourceUrl: $sourceUrl,
        );
    }

    /**
     * @return list<string|null>
     */
    private function rowValues(Crawler $row): array
    {
        return $row->filter('td')->each(
            fn (Crawler $cell): ?string => HtmlTextNormalizer::normalize($cell->text(null, false))
        );
    }

    /**
     * @return list<PlayerGradeHistoryDto>
     */
    private function gradeHistories(Crawler $crawler): array
    {
        $histories = [];
        $crawler->filterXPath('//p[contains(normalize-space(.), "級班の履歴情報")]/following-sibling::table[1]//tbody/tr')
            ->each(function (Crawler $row) use (&$histories): void {
                $values = $this->rowValues($row);
                $grade = GradeNormalizer::normalize($values[0] ?? null);
                $assignedOn = $this->date($values[1] ?? null);
                if ($grade !== null || $assignedOn !== null) {
                    $histories[] = new PlayerGradeHistoryDto($grade, $assignedOn);
                }
            });

        return $histories;
    }

    private function recentStats(Crawler $crawler): ?PlayerStatsDto
    {
        $rows = $crawler->filterXPath('//p[contains(normalize-space(.), "直近4ヶ月成績")]/following-sibling::table[1]//tr');
        if ($rows->count() < 2) {
            return null;
        }

        $values = $this->rowValues($rows->eq(1));

        return new PlayerStatsDto(
            raceScore: $this->float($values[12] ?? null),
            winRate: $this->percent($values[7] ?? null),
            quinellaRate: $this->percent($values[8] ?? null),
            trioRate: $this->percent($values[9] ?? null),
            homeCount: $this->int($values[10] ?? null),
            backCount: $this->int($values[11] ?? null),
            startCount: $this->int($values[6] ?? null),
        );
    }

    private function sourceUpdatedAt(Crawler $crawler): ?DateTimeImmutable
    {
        $text = HtmlTextNormalizer::normalize($crawler->text(null, false)) ?? '';
        if (preg_match('/([0-9]{4}\/[0-9]{2}\/[0-9]{2}\s+[0-9]{2}:[0-9]{2})\s*更新/u', $text, $matches) === 1) {
            return new DateTimeImmutable(str_replace('/', '-', $matches[1]));
        }

        return null;
    }

    private function date(?string $value): ?DateTimeImmutable
    {
        $normalized = HtmlTextNormalizer::normalize($value);
        if ($normalized === null) {
            return null;
        }

        return new DateTimeImmutable(str_replace('/', '-', $normalized));
    }

    private function gender(?string $value): ?string
    {
        return match (HtmlTextNormalizer::normalize($value)) {
            '男', '男子' => 'male',
            '女', '女子' => 'female',
            default => null,
        };
    }

    private function int(?string $value): ?int
    {
        $digits = HtmlTextNormalizer::digits($value);

        return $digits === null ? null : (int) $digits;
    }

    private function float(?string $value): ?float
    {
        $normalized = HtmlTextNormalizer::normalize($value);
        if ($normalized === null) {
            return null;
        }

        $numeric = preg_replace('/[^0-9.\-]/', '', mb_convert_kana($normalized, 'n', 'UTF-8'));

        return $numeric === '' ? null : (float) $numeric;
    }

    private function percent(?string $value): ?float
    {
        return $this->float($value);
    }
}
