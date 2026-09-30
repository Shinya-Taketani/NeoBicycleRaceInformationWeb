<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\StartObservation;

use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;

final class DisplaySignature
{
    public const VERSION = 'STAT36-DISPLAY-SIGNATURE-v2-SORTED-OBJECT-KEYS';

    public static function hash(array $row): string
    {
        $fields = [];
        foreach ($row['fields'] as $field => $value) {
            $fields[$field] = ['presence' => $value['presence'], 'raw' => $value['raw']];
        }

        return hash('sha256', Files::canonical([self::VERSION, $row['external_player_id'], self::normalize($fields)]));
    }

    private static function normalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(self::normalize(...), $value);
        }

        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = self::normalize($item);
        }

        // Sorting numeric object keys must not turn the signature copy into a JSON list.
        return (object) $value;
    }
}
