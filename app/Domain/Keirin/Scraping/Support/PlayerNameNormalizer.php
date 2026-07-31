<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\Support;

final class PlayerNameNormalizer
{
    public static function comparisonKey(?string $name): ?string
    {
        $normalized = HtmlTextNormalizer::normalize($name);
        if ($normalized === null) {
            return null;
        }

        return preg_replace('/[\s\x{3000}]+/u', '', $normalized);
    }

    public static function displayName(string $name): string
    {
        return trim((string) preg_replace('/[\s\x{3000}]+/u', ' ', $name));
    }
}
