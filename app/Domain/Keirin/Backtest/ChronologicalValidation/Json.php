<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\ChronologicalValidation;

use RuntimeException;
use stdClass;

final class Json
{
    public const VERSION = 'CHRONOLOGICAL-JSON-OBJECT-KEYS-v1';

    public static function encode(mixed $value): string
    {
        return json_encode(self::ordered($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)."\n";
    }

    public static function hash(mixed $value): string
    {
        return hash('sha256', self::encode($value));
    }

    public static function read(string $path): array
    {
        self::file($path);
        $data = self::decoded(json_decode(file_get_contents($path), false, 512, JSON_THROW_ON_ERROR));
        if (! is_array($data)) {
            throw new RuntimeException('JSON object required.');
        }

        return $data;
    }

    public static function file(string $path): void
    {
        if (! str_starts_with($path, '/') || realpath($path) !== $path || ! is_file($path) || is_link($path)) {
            throw new RuntimeException('Canonical regular source file required.');
        }
        for ($parent = dirname($path); $parent !== '/'; $parent = dirname($parent)) {
            if (is_link($parent)) {
                throw new RuntimeException('Source symlink forbidden.');
            }
        }
    }

    public static function seal(string $path): array
    {
        self::file($path);

        return ['bytes' => filesize($path), 'sha256' => hash_file('sha256', $path)];
    }

    public static function verify(string $path, array $seal): void
    {
        if (self::seal($path) !== $seal) {
            throw new RuntimeException('Source seal mismatch.');
        }
    }

    public static function write(string $path, string $bytes): void
    {
        $stream = fopen($path, 'xb');
        if ($stream === false) {
            throw new RuntimeException('Exclusive evidence creation failed.');
        }
        try {
            if (! chmod($path, 0600) || fwrite($stream, $bytes) !== strlen($bytes) || ! fflush($stream) || ! fsync($stream)) {
                throw new RuntimeException('Evidence persistence failed; partial file retained.');
            }
        } finally {
            fclose($stream);
        }
    }

    private static function ordered(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $fields = get_object_vars($value);
            ksort($fields, SORT_STRING);

            return (object) array_map(self::ordered(...), $fields);
        }
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map(self::ordered(...), $value);
    }

    private static function decoded(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $fields = array_map(self::decoded(...), get_object_vars($value));

            // PHP associative decoding would turn {} or {"0":...,"1":...} into a list.
            return array_is_list($fields) ? (object) $fields : $fields;
        }

        return is_array($value) ? array_map(self::decoded(...), $value) : $value;
    }
}
