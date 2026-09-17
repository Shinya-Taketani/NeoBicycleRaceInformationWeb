<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal;

use RuntimeException;

final class Files
{
    public static function json(string $path): array
    {
        if (! is_file($path) || filesize($path) > 16777216) {
            throw new RuntimeException('Missing or oversized JSON artifact: '.$path);
        }
        $data = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($data)) {
            throw new RuntimeException('Artifact must be a JSON object: '.$path);
        }

        return $data;
    }

    public static function identity(string $path): array
    {
        clearstatcache(true, $path);
        if (! is_file($path) || is_link($path)) {
            throw new RuntimeException('Missing regular artifact: '.$path);
        }

        return ['bytes' => filesize($path), 'sha256' => hash_file('sha256', $path)];
    }

    public static function verify(string $path, array $seal): void
    {
        $actual = self::identity($path);
        if ($actual !== ['bytes' => $seal['bytes'] ?? null, 'sha256' => $seal['sha256'] ?? null]) {
            throw new RuntimeException('Artifact hash/size mismatch: '.$path);
        }
    }

    public static function canonical(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES);
    }

    public static function same(array $expected, array $actual, string $context): void
    {
        if (self::canonical($expected) !== self::canonical($actual)) {
            throw new RuntimeException('Semantic mismatch: '.$context);
        }
    }

    public static function directory(string $path): string
    {
        if (file_exists($path) || is_link($path) || ! mkdir($path, 0755)) {
            throw new RuntimeException('Output directory must be new: '.$path);
        }

        return $path;
    }
}
