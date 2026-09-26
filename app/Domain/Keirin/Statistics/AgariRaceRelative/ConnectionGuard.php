<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\AgariRaceRelative;

use RuntimeException;

final class ConnectionGuard
{
    public static function endpoint(array $observed): array
    {
        return self::validate($observed, [
            'database' => 'neo_keirin_prediction_db', 'schema' => 'public',
            'host' => '127.0.0.1', 'port' => 5432,
            'session_read_only' => 'on', 'transaction_read_only' => 'on',
        ]);
    }

    public static function snapshot(array $observed): array
    {
        return self::validate($observed, ['isolation' => 'repeatable read', 'snapshot_read_only' => 'on'])
            + ['snapshot' => is_string($observed['snapshot'] ?? null) ? $observed['snapshot'] : null];
    }

    private static function validate(array $observed, array $expected): array
    {
        $errors = [];
        foreach ($expected as $field => $value) {
            $actual = $observed[$field] ?? null;
            $normalized = $actual;
            if ($field === 'port' && is_string($actual) && preg_match('/\A[0-9]+\z/', $actual)) {
                // Compare digits before casting, including values outside PHP's integer range.
                $normalized = ltrim($actual, '0') === (string) $value ? $value : $actual;
            }
            if ($normalized !== $value) {
                $errors[] = ['field' => $field, 'expected' => $value,
                    'actual' => is_scalar($actual) || $actual === null ? $actual : '[NON_SCALAR]',
                    'actual_type' => array_key_exists($field, $observed) ? get_debug_type($actual) : 'MISSING'];
            }
        }
        if ($errors !== []) {
            throw new RuntimeException('Export connection validation failed: '.json_encode($errors, JSON_THROW_ON_ERROR));
        }

        return $expected;
    }
}
