<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\Parsers;

use App\Domain\Keirin\Scraping\DTO\RacePayoutDto;
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

        $crawler->filter('#pitbodyHarai tr')->each(function (Crawler $row) use (&$sequenceByType, &$payouts): void {
            $values = $row->filter('td')->each(fn (Crawler $cell): ?string => HtmlTextNormalizer::normalize($cell->text(null, false)));
            $values = array_values(array_filter($values, fn (?string $value): bool => $value !== null));
            if (count($values) < 3) {
                return;
            }

            $type = $this->betTypeCode($values[0]);
            if ($type === null) {
                return;
            }

            $sequenceByType[$type] = ($sequenceByType[$type] ?? 0) + 1;
            $payouts[] = new RacePayoutDto(
                betTypeCode: $type,
                combination: $values[1],
                payoutAmount: $this->money($values[2] ?? null),
                popularity: $this->int($values[3] ?? null),
                sequence: $sequenceByType[$type],
            );
        });

        return $payouts;
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
