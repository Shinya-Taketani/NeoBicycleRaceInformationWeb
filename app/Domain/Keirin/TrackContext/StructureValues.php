<?php

declare(strict_types=1);

namespace App\Domain\Keirin\TrackContext;

use Brick\Math\BigDecimal;
use DateTimeImmutable;
use InvalidArgumentException;

final class StructureValues
{
    public static function positiveDecimal(mixed $value): BigDecimal
    {
        if (! is_string($value) || ! preg_match('/\A[0-9]+(?:\.[0-9]+)?\z/', $value)) {
            throw new InvalidArgumentException('Expected an exact decimal string.');
        }
        $number = BigDecimal::of($value);
        if ($number->isLessThanOrEqualTo(0)) {
            throw new InvalidArgumentException('Distance/time must be positive.');
        }

        return $number;
    }

    public static function date(mixed $value): string
    {
        $date = is_string($value) ? DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
        if (! $date || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('Invalid calendar date.');
        }

        return $value;
    }

    /** DMS stays DMS; total arcseconds are exact, not a decimal-degree approximation. */
    public static function dms(string $raw): array
    {
        if (! preg_match('/\A([0-9]{1,2})[°度]([0-9]{1,2})[′分]([0-9]{1,2})[″秒]\z/u', $raw, $m)
            || (int) $m[1] >= 90 || (int) $m[2] >= 60 || (int) $m[3] >= 60) {
            throw new InvalidArgumentException('Invalid slope DMS.');
        }

        return ['degrees' => (int) $m[1], 'minutes' => (int) $m[2], 'seconds' => (int) $m[3],
            'arcseconds' => (int) $m[1] * 3600 + (int) $m[2] * 60 + (int) $m[3]];
    }
}
