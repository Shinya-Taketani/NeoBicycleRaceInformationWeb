<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\TacticalGradeAnalysis;

use RuntimeException;

final class Grade
{
    public const MAP = ['SS' => 'SS', 'S級S班' => 'SS', 'S1' => 'S1', 'S級1班' => 'S1', 'S2' => 'S2', 'S級2班' => 'S2',
        'A1' => 'A1', 'A級1班' => 'A1', 'A2' => 'A2', 'A級2班' => 'A2', 'A3' => 'A3', 'A級3班' => 'A3'];

    public static function classify(?string $raw): array
    {
        $key = preg_replace('/[\s\x{3000}]+/u', '', mb_convert_kana($raw ?? '', 'as', 'UTF-8'));
        if ($key === null) {
            throw new RuntimeException('Invalid grade encoding.');
        }
        $grade = self::MAP[$key] ?? 'UNKNOWN';
        $unexpected = preg_match('/^(?:S3|A4|L1|B[12]|S級3班|A級4班|L級1班|B級[12]班)$/u', $key) === 1;

        return ['grade_raw' => $raw, 'normalized_grade' => $grade,
            'verification_status' => $grade !== 'UNKNOWN' ? 'VERIFIED_RACE_ENTRY_GRADE'
                : ($unexpected ? 'UNEXPECTED_KNOWN_GRADE' : ($key === '' ? 'MISSING_GRADE' : 'UNRECOGNIZED_GRADE')),
            'publication_time_verified' => 'UNKNOWN'];
    }

    public static function stage(?string $raw): string
    {
        $text = preg_replace('/\s+/u', '', $raw ?? '');
        if (str_contains($text, '準決勝')) {
            return 'SEMIFINAL';
        }
        if (str_contains($text, '決勝')) {
            return 'FINAL';
        }

        return str_contains($text, '予選') ? 'QUALIFIER' : 'UNKNOWN';
    }
}
