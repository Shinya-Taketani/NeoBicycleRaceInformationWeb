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
            $values = $row->filter('td')->each(fn (Crawler $cell): ?string => HtmlTextNormalizer::normalize($cell->text(null, false)));
            $values = array_values(array_filter($values, fn (?string $value): bool => $value !== null));
            if ($values === []) {
                return;
            }

            $dataRows++;
            if (count($values) < 3) {
                throw new ParserException('Payout row had an unsupported column count.');
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
            $payouts[] = new RacePayoutDto(
                betTypeCode: $type,
                combination: $combination,
                payoutAmount: $amount,
                popularity: $this->int($values[3] ?? null),
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
}
