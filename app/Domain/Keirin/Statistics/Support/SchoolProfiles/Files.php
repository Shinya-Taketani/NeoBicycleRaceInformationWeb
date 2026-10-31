<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\Support\SchoolProfiles;

use Generator;
use RuntimeException;

final class Files
{
    public static function seal(string $path): array
    {
        if (! str_starts_with($path, '/') || realpath($path) !== $path || ! is_file($path) || is_link($path)) {
            throw new RuntimeException('School evidence requires an existing canonical regular file.');
        }

        return ['path' => $path, 'bytes' => filesize($path), 'sha256' => hash_file('sha256', $path)];
    }

    public static function verify(array $ref): string
    {
        $seal = self::seal($ref['path'] ?? '');
        if ($seal['bytes'] !== ($ref['bytes'] ?? null) || $seal['sha256'] !== ($ref['sha256'] ?? null)) {
            throw new RuntimeException('School evidence SHA/size mismatch.');
        }

        return $seal['path'];
    }

    public static function read(string $path, string $sha): array
    {
        $seal = self::seal($path);
        if ($seal['sha256'] !== $sha || $seal['bytes'] > 16 * 1024 * 1024) {
            throw new RuntimeException('School JSON seal or size mismatch.');
        }

        return json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }

    public static function rows(string $path): Generator
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Cannot read school JSONL.');
        }
        try {
            $line = 0;
            while (! feof($handle)) {
                $offset = ftell($handle);
                $raw = fgets($handle, 2 * 1024 * 1024 + 1);
                if ($raw === false) {
                    if (! feof($handle)) {
                        throw new RuntimeException('School JSONL read failed.');
                    }
                    break;
                }
                if (strlen($raw) >= 2 * 1024 * 1024 || trim($raw) === '') {
                    throw new RuntimeException('Empty or oversized school JSONL record.');
                }
                yield ['line' => ++$line, 'offset' => $offset, 'bytes' => strlen($raw), 'raw' => rtrim($raw, "\r\n"),
                    'value' => json_decode($raw, true, flags: JSON_THROW_ON_ERROR)];
            }
        } finally {
            fclose($handle);
        }
    }

    public static function createDirectory(string $path): void
    {
        if (! str_starts_with($path, '/') || preg_match('~(?:^|/)(?:\.|\.\.)(?:/|$)|//|/$~', $path) === 1
            || realpath(dirname($path)) !== dirname($path) || file_exists($path) || is_link($path) || ! mkdir($path, 0700)) {
            throw new RuntimeException('A new dedicated absolute school output directory is required.');
        }
    }

    public static function write(string $path, string $bytes): void
    {
        $h = fopen($path, 'xb');
        if ($h === false) {
            throw new RuntimeException('Cannot exclusively create school evidence.');
        }
        try {
            if (! chmod($path, 0600) || fwrite($h, $bytes) !== strlen($bytes) || ! fflush($h) || ! fsync($h)) {
                throw new RuntimeException('Incomplete school evidence retained.');
            }
        } finally {
            fclose($h);
        }
    }

    public static function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)."\n";
    }
}
