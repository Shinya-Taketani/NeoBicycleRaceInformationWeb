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
        $totalRows = 0;
        $headerRows = 0;
        $emptyRows = 0;
        $dataRows = 0;
        $parsedRows = 0;
        $seenBikeNumbers = [];

        if ($crawler->filter('#pitbodyBs')->count() === 0) {
            throw new ParserException('Race result table marker was not found.');
        }

        $crawler->filter('#pitbodyBs tr')->each(function (Crawler $row, int $index) use (
            &$results,
            &$totalRows,
            &$headerRows,
            &$emptyRows,
            &$dataRows,
            &$parsedRows,
            &$seenBikeNumbers,
        ): void {
            $totalRows++;
            $thCount = $row->filter('th')->count();
            $tdCount = $row->filter('td')->count();

            if ($thCount > 0) {
                if ($tdCount > 0) {
                    throw new ParserException("Race result row {$index} mixed header and data cells.");
                }

                $headerRows++;

                return;
            }

            $values = $row->filter('td')->each(fn (Crawler $cell): ?string => HtmlTextNormalizer::normalize($cell->text(null, false)));
            if ($values === [] || $this->isEmptyRow($values)) {
                if ($values === [] && HtmlTextNormalizer::normalize($row->text(null, false)) !== null) {
                    throw new ParserException("Race result row {$index} contained text outside data cells.");
                }

                $emptyRows++;

                return;
            }

            $dataRows++;
            if (count($values) < 4) {
                throw new ParserException("Race result row {$index} had fewer than four columns.");
            }

            $rawRank = $values[0] ?? null;
            if ($rawRank === null) {
                throw new ParserException("Race result row {$index} had no rank or official status.");
            }

            $bikeNumber = $this->positiveInteger($values[2] ?? null);
            if ($bikeNumber === null || $bikeNumber > 9) {
                throw new ParserException("Race result row {$index} had an invalid bike number.");
            }

            if (isset($seenBikeNumbers[$bikeNumber])) {
                throw new ParserException("Race result bike number {$bikeNumber} appeared more than once.");
            }

            $playerName = $values[3] ?? null;
            if ($playerName === null) {
                throw new ParserException("Race result row {$index} had no player identifier.");
            }

            [$rank, $status] = $this->rankAndStatus($rawRank, $index);
            $seenBikeNumbers[$bikeNumber] = true;
            $results[] = new RaceResultDto(
                rank: $rank,
                bikeNumber: $bikeNumber,
                playerName: $playerName,
                status: $status,
                winningTechnique: null,
                rawText: implode(' ', array_filter($values, fn (?string $value): bool => $value !== null)),
            );
            $parsedRows++;
        });

        if ($results === []) {
            throw new ParserException('Race result table exists, but no result rows were parsed.');
        }

        $expectedDataRows = $totalRows - $headerRows - $emptyRows;
        if ($dataRows !== $expectedDataRows || $parsedRows !== $dataRows) {
            throw new ParserException("Race result table was only partially parsed: total={$totalRows}, headers={$headerRows}, empty={$emptyRows}, data={$dataRows}, parsed={$parsedRows}.");
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

    /**
     * @return array{0:?int,1:RaceEntryResultStatus}
     */
    private function rankAndStatus(string $rawRank, int $rowIndex): array
    {
        $rank = $this->positiveInteger($rawRank);
        if ($rank !== null) {
            return [$rank, RaceEntryResultStatus::Finished];
        }

        $status = match ($rawRank) {
            '失', '失格' => RaceEntryResultStatus::Disqualified,
            '欠', '欠場' => RaceEntryResultStatus::DidNotStart,
            '取消' => RaceEntryResultStatus::Withdrawn,
            '棄', '棄権', '未着' => RaceEntryResultStatus::DidNotFinish,
            '落', '落車' => RaceEntryResultStatus::Crashed,
            default => null,
        };

        if ($status === null) {
            throw new ParserException("Race result row {$rowIndex} had an unknown rank or status: {$rawRank}");
        }

        return [null, $status];
    }

    /**
     * @param  list<?string>  $values
     */
    private function isEmptyRow(array $values): bool
    {
        foreach ($values as $value) {
            if ($value !== null) {
                return false;
            }
        }

        return true;
    }

    private function positiveInteger(?string $value): ?int
    {
        if ($value === null) {
            return null;
        }

        $normalized = mb_convert_kana($value, 'n', 'UTF-8');
        if (preg_match('/^[0-9]+$/', $normalized) !== 1) {
            return null;
        }

        $number = (int) $normalized;

        return $number > 0 ? $number : null;
    }
}
