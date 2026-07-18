<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\Parsers;

use App\Domain\Keirin\Scraping\DTO\RacePayoutDto;
use App\Domain\Keirin\Scraping\Exceptions\ParserException;
use App\Domain\Keirin\Scraping\Support\HtmlTextNormalizer;
use Symfony\Component\DomCrawler\Crawler;

class PayoutParser
{
    /**
     * @return list<RacePayoutDto>
     */
    public function parse(string $html): array
    {
        $crawler = new Crawler;
        $crawler->addHtmlContent($html, 'UTF-8');
        $sequenceByType = [];
        $payouts = [];
        $text = HtmlTextNormalizer::normalize($crawler->text(null, false)) ?? '';
        $dataRows = 0;

        if ($crawler->filter('#pitbodyHarai')->count() === 0) {
            if (str_contains($text, '払戻なし') || str_contains($text, '開催中止') || str_contains($text, '中止')) {
                return [];
            }

            throw new ParserException('Payout table marker was not found.');
        }

        if (str_contains($text, '払戻なし')) {
            return [];
        }

        $crawler->filter('#pitbodyHarai tr')->each(function (Crawler $row) use (&$sequenceByType, &$payouts, &$dataRows): void {
            if ($row->filter('th')->count() > 0) {
                if ($row->filter('td')->count() > 0) {
                    throw new ParserException('Payout row mixed header and data cells.');
                }

                return;
            }

            $values = $row->filter('td')->each(fn (Crawler $cell): ?string => HtmlTextNormalizer::normalize($cell->text(null, false)));
            if ($values === [] || $this->isEmptyRow($values)) {
                return;
            }

            $dataRows++;
            if (count($values) !== 4) {
                throw new ParserException('Payout row had an unsupported column count.');
            }

            if ($values[0] === null) {
                throw new ParserException('Payout bet type was empty.');
            }

            if ($values[1] === null) {
                throw new ParserException('Payout combination was empty.');
            }

            if ($values[2] === null) {
                throw new ParserException('Payout amount was empty.');
            }

            $type = $this->betTypeCode($values[0]);
            if ($type === null) {
                throw new ParserException('Unknown payout bet type: '.$values[0]);
            }

            $combination = $this->combination($values[1]);
            if ($combination === '') {
                throw new ParserException('Payout combination was empty.');
            }

            $amount = $this->money($values[2] ?? null);
            if ($amount === null) {
                throw new ParserException('Payout amount could not be parsed: '.($values[2] ?? ''));
            }

            $sequenceByType[$type] = ($sequenceByType[$type] ?? 0) + 1;
            $popularity = $this->int($values[3]);
            if ($values[3] !== null && $popularity === null) {
                throw new ParserException('Payout popularity could not be parsed: '.$values[3]);
            }

            $payouts[] = new RacePayoutDto(
                betTypeCode: $type,
                combination: $combination,
                payoutAmount: $amount,
                popularity: $popularity,
                sequence: $sequenceByType[$type],
            );
        });

        if ($dataRows > 0 && count($payouts) !== $dataRows) {
            throw new ParserException('Payout table was only partially parsed.');
        }

        if ($dataRows === 0) {
            throw new ParserException('Payout table exists, but no payout rows were parsed.');
        }

        return $payouts;
    }

    private function combination(string $raw): string
    {
        $normalized = HtmlTextNormalizer::normalize(mb_convert_kana($raw, 'as', 'UTF-8')) ?? $raw;

        return preg_replace('/\s+/u', '', $normalized) ?? $normalized;
    }

    private function betTypeCode(string $raw): ?string
    {
        $normalized = str_replace(' ', '', mb_convert_kana($raw, 'as', 'UTF-8'));

        return match ($normalized) {
            '2枠複' => 'FRAME_QUINELLA',
            '2枠単' => 'FRAME_EXACTA',
            '2車複' => 'QUINELLA',
            '2車単' => 'EXACTA',
            '3連複' => 'TRIO',
            '3連単' => 'TRIFECTA',
            'ワイド' => 'QUINELLA_PLACE',
            default => null,
        };
    }

    private function money(?string $value): ?int
    {
        return $this->int($value);
    }

    private function int(?string $value): ?int
    {
        $digits = HtmlTextNormalizer::digits($value);

        return $digits === null ? null : (int) $digits;
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
}
