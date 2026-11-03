<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\InitialHistoryMetadata;

use App\Domain\Keirin\Backtest\ChronologicalValidation\Json;
use stdClass;

final class Parameters
{
    public static function fingerprint(mixed $parameters): array
    {
        $object = $parameters instanceof stdClass;
        $fields = $object ? get_object_vars($parameters) : [];
        $out = ['container_type' => get_debug_type($parameters)];
        foreach (['disp', 'encp'] as $field) {
            $present = array_key_exists($field, $fields);
            $value = $fields[$field] ?? null;
            $out[$field] = ['present' => $present, 'type' => $present ? get_debug_type($value) : 'MISSING',
                'sha256' => $present ? Json::hash($value) : null];
        }
        $out['disp_value'] = is_string($fields['disp'] ?? null) ? $fields['disp'] : null;

        return $out;
    }
}
