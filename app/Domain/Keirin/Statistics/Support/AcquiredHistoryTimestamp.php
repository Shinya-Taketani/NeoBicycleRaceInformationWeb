<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\Support;

use DateTimeImmutable;
use DateTimeZone;
use UnexpectedValueException;

final class AcquiredHistoryTimestamp
{
    public static function parse(mixed $value): DateTimeImmutable
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}(?::?\d{2})?)?$/D', $value) !== 1) {
            throw new UnexpectedValueException('Invalid saved acquired-history timestamp.');
        }
        $date = new DateTimeImmutable($value, new DateTimeZone('Asia/Tokyo'));
        $errors = DateTimeImmutable::getLastErrors();
        if (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
            throw new UnexpectedValueException('Invalid saved acquired-history timestamp.');
        }

        return $date;
    }
}
