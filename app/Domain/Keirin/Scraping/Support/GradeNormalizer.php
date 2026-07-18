<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\Support;

final class GradeNormalizer
{
    public static function normalize(?string $grade): ?string
    {
        $normalized = HtmlTextNormalizer::normalize($grade);

        if ($normalized === null) {
            return null;
        }

        $normalized = mb_convert_kana($normalized, 'as', 'UTF-8');
        $normalized = str_replace(['級', '班', '･'], ['', '', '/'], $normalized);
        $normalized = preg_replace('/\s+/u', '', $normalized) ?? $normalized;

        return match ($normalized) {
            'SS', 'SＳ', 'S級S' => 'SS',
            'S1', 'S級1' => 'S1',
            'S2', 'S級2' => 'S2',
            'S3', 'S級3' => 'S3',
            'A1', 'A級1' => 'A1',
            'A2', 'A級2' => 'A2',
            'A3', 'A級3' => 'A3',
            'A4', 'A級4' => 'A4',
            'L1', 'L級1' => 'L1',
            'B1', 'B級1' => 'B1',
            'B2', 'B級2' => 'B2',
            default => $grade,
        };
    }
}
