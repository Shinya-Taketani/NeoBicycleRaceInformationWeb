<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\AgariC1Input;

use RuntimeException;

final class Validator
{
    public static function race(array $race, int $year): void
    {
        Contract::keys($race, ['year', 'race_id', 'entries']);
        if (! in_array($year, Contract::YEARS, true) || $race['year'] !== $year || ! Contract::id($race['race_id'])
            || ! is_array($race['entries']) || ! array_is_list($race['entries']) || count($race['entries']) < 5 || count($race['entries']) > 9) {
            throw new RuntimeException('Invalid C1 race identity/year/entrants.');
        }
        $ids = $bikes = [];
        foreach ($race['entries'] as $entry) {
            Contract::keys($entry, Contract::ENTRY_KEYS);
            if (! Contract::id($entry['id']) || in_array($entry['id'], $ids, true) || ! Contract::id($entry['bike'])
                || $entry['bike'] > 9 || in_array($entry['bike'], $bikes, true)
                || ! self::finite($entry['raw']) || ! self::finite($entry['anchor']) || ! self::finite($entry['stat01_rank'])
                || $entry['stat01_rank'] < 1 || $entry['stat01_rank'] > count($race['entries'])
                || ! is_array($entry['signals']) || ! array_is_list($entry['signals']) || count($entry['signals']) !== 12
                || ! is_array($entry['history']) || ! array_is_list($entry['history']) || count($entry['history']) !== 4) {
                throw new RuntimeException('Invalid C1 entry.');
            }
            foreach ($entry['signals'] as $value) {
                if ($value !== null && ! is_string($value) && ! self::finite($value)) {
                    throw new RuntimeException('Invalid signal value.');
                }
            }
            foreach ($entry['history'] as $value) {
                if ($value !== null && (! is_int($value) || $value < 0)) {
                    throw new RuntimeException('Invalid history count.');
                }
            }
            $unavailable = ['MISSING_TARGET_METADATA', 'LEFT_TRUNCATED', 'INVALID_HISTORY', 'PARTIAL_HISTORY', 'MISSING_METHOD_HISTORY', 'NO_HISTORY'];
            if ($entry['history_status'] === 'AVAILABLE' ? in_array(null, $entry['history'], true)
                : (! in_array($entry['history_status'], $unavailable, true) || $entry['history'] !== [null, null, null, null])) {
                throw new RuntimeException('History status/value contradiction.');
            }
            $ids[] = $entry['id'];
            $bikes[] = $entry['bike'];
        }
        $scores = array_map(fn ($e) => (float) $e['raw'], $race['entries']);
        $mean = array_sum($scores) / count($scores);
        $sd = sqrt(array_sum(array_map(fn ($v) => ($v - $mean) ** 2, $scores)) / count($scores));
        foreach ($race['entries'] as $entry) {
            if ($entry['anchor_status'] !== ($sd > 0 ? 'AVAILABLE' : 'ZERO_VARIANCE')
                || (float) $entry['anchor'] !== ($sd > 0 ? ((float) $entry['raw'] - $mean) / $sd : 0.0)) {
                throw new RuntimeException('Anchor status/value contradiction.');
            }
        }
    }

    private static function finite(mixed $value): bool
    {
        return (is_int($value) || is_float($value)) && is_finite($value);
    }
}
