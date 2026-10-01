<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\StartCountC1Candidate;

final class Timing
{
    public static function relation(mixed $fetched, string $raceDate): string
    {
        if (! is_string($fetched) || ! preg_match('/\A([0-9]{4}-[0-9]{2}-[0-9]{2})[ T]([0-9]{2}:[0-9]{2}:[0-9]{2})(\.[0-9]{1,6})?(Z|[+-][0-9]{2}(?::?[0-9]{2})?)\z/D', $fetched, $m)) {
            return 'UNKNOWN_TIME_OR_TIMEZONE';
        }
        $zone = $m[4] === 'Z' ? '+00:00' : $m[4];
        if (strlen($zone) === 3) {
            $zone .= ':00';
        } elseif (strlen($zone) === 5) {
            $zone = substr($zone, 0, 3).':'.substr($zone, 3);
        }
        if ((int) substr($zone, 1, 2) > 14 || (int) substr($zone, 4, 2) > 59
            || ((int) substr($zone, 1, 2) === 14 && substr($zone, 4) !== '00')) {
            return 'INVALID_FETCH_TIME';
        }
        $text = $m[1].' '.$m[2].'.'.str_pad(ltrim($m[3] ?? '', '.'), 6, '0').$zone;
        $time = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.uP', $text);
        $errors = \DateTimeImmutable::getLastErrors();
        if ($time === false || ($errors !== false && ($errors['warning_count'] || $errors['error_count']))
            || $time->format('Y-m-d H:i:s.uP') !== $text) {
            return 'INVALID_FETCH_TIME';
        }
        // The race date uses the repository's explicit Asia/Tokyo contract, never PHP's default timezone.
        $date = $time->setTimezone(new \DateTimeZone('Asia/Tokyo'))->format('Y-m-d');

        return $date < $raceDate ? 'BEFORE_TARGET_DATE' : ($date > $raceDate ? 'AFTER_TARGET_DATE' : 'SAME_TARGET_DATE_NO_START_TIME');
    }
}
