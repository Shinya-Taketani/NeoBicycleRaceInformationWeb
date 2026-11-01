<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\ChronologicalValidation;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

final readonly class Scope
{
    public function __construct(public string $kind, public string $from, public string $to)
    {
        self::day($from);
        self::day($to);
        if (! in_array($kind, ['SYNTHETIC_TEST', 'LEGACY_COMPATIBILITY'], true) || $from > $to
            || ($kind === 'LEGACY_COMPATIBILITY' && ($from < '2022-01-01' || $to > '2025-12-31'))
            || ($kind === 'SYNTHETIC_TEST' && ($from < '2022-01-01' || $to > '2026-01-31'))) {
            throw new RuntimeException('Real execution NOT_AUTHORIZED or scope invalid.');
        }
    }

    public function target(string $date): void
    {
        self::day($date);
        if ($date < $this->from || $date > $this->to) {
            throw new RuntimeException('Target outside explicit offline scope.');
        }
    }

    public function years(): array
    {
        return $this->kind === 'SYNTHETIC_TEST' ? [2022, 2023, 2024, 2025, 2026] : [2022, 2023, 2024, 2025];
    }

    public static function day(string $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('Asia/Tokyo'));
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new RuntimeException('Invalid JST date.');
        }

        return $date;
    }

    public static function instant(string $value): DateTimeImmutable
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D', $value)) {
            throw new RuntimeException('Timezone-qualified instant required.');
        }
        $date = new DateTimeImmutable($value);
        $errors = DateTimeImmutable::getLastErrors();
        if ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
            throw new RuntimeException('Invalid instant.');
        }

        return $date;
    }
}
