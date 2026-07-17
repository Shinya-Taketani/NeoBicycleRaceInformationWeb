<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\Support;

final class HtmlTextNormalizer
{
    public static function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $normalized = str_replace(["\u{00A0}", "\r", "\n", "\t"], ' ', $normalized);
        $normalized = preg_replace('/[ ]+/u', ' ', $normalized) ?? $normalized;
        $normalized = trim($normalized);

        return $normalized === '' || $normalized === '-' || $normalized === '－' ? null : $normalized;
    }

    public static function digits(?string $value): ?string
    {
        $normalized = self::normalize($value);

        if ($normalized === null) {
            return null;
        }

        $converted = mb_convert_kana($normalized, 'n', 'UTF-8');
        $digits = preg_replace('/\D+/', '', $converted);

        return $digits === '' ? null : $digits;
    }
}
