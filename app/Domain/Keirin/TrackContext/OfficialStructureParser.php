<?php

declare(strict_types=1);

namespace App\Domain\Keirin\TrackContext;

use App\Domain\Keirin\Scraping\Support\HtmlTextNormalizer;
use InvalidArgumentException;
use Symfony\Component\DomCrawler\Crawler;

final class OfficialStructureParser
{
    public const VERSION = 'official-structure-table-v1';

    /** Only the labelled structural table verified in the Seibuen official static guide. */
    public function parse(string $html): array
    {
        $fields = [];
        $labels = ['周長' => 'bank_circumference_m', 'ホーム傾斜角' => 'straight_slope', 'センター傾斜角' => 'cant'];
        (new Crawler($html))->filter('table.hyo3 tr')->each(function (Crawler $row) use (&$fields, $labels): void {
            $label = HtmlTextNormalizer::normalize($row->filter('th')->count() === 1 ? $row->filter('th')->text() : null);
            if (! isset($labels[$label ?? ''])) {
                return;
            }
            $key = $labels[$label];
            if (isset($fields[$key]) || $row->filter('td')->count() !== 1) {
                throw new InvalidArgumentException('Duplicate or invalid structural field: '.$key);
            }
            $raw = HtmlTextNormalizer::normalize($row->filter('td')->text());
            if ($raw === null) {
                throw new InvalidArgumentException('Missing published structural value: '.$key);
            }
            if ($key === 'bank_circumference_m') {
                if (! preg_match('/\A([0-9]+(?:\.[0-9]+)?)m\z/', $raw, $match)) {
                    throw new InvalidArgumentException('Invalid circumference unit/value.');
                }
                StructureValues::positiveDecimal($match[1]);
                $value = $match[1];
            } else {
                $value = StructureValues::dms($raw);
            }
            $fields[$key] = ['value' => $value, 'raw' => $raw, 'unit' => $key === 'bank_circumference_m' ? 'm' : 'DMS'];
        });
        if (! isset($fields['bank_circumference_m'])) {
            throw new InvalidArgumentException('Circumference was not found in the verified structural table.');
        }

        return $fields;
    }
}
