<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\Support;

final class SimilarConditionsHashInput
{
    public const VERSION = 'SIMILAR-CONDITIONS-INSTANT-HASH-v3';

    public static function conditions(array $copy): array
    {
        foreach (['raw' => 'scheduled_start_at', 'sources' => 'fetched_at'] as $section => $field) {
            if (isset($copy[$section]) && array_key_exists($field, $copy[$section])
                && $copy[$section][$field] !== null && $copy[$section][$field] !== '') {
                $copy[$section][$field] = AcquiredHistoryTimestamp::parse($copy[$section][$field])->format('U.u');
            }
        }

        return $copy;
    }

    public static function references(array $copy): array
    {
        foreach ($copy as &$reference) {
            if (isset($reference['conditions'])) {
                $reference['conditions'] = self::conditions($reference['conditions']);
            }
        }
        unset($reference);

        return $copy;
    }
}
