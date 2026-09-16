<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\TacticalHistory;

use Generator;
use RuntimeException;

final class JsonlArtifact
{
    public static function write(string $path, iterable $rows): array
    {
        if (file_exists($path) || file_exists($path.'.manifest.json')) {
            throw new RuntimeException('Refusing to overwrite a sealed artifact.');
        }
        $handle = fopen($path.'.partial', 'xb');
        $count = 0;
        try {
            foreach ($rows as $row) {
                $line = json_encode($row, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES)."\n";
                if (fwrite($handle, $line) !== strlen($line)) {
                    throw new RuntimeException('Artifact write was incomplete.');
                }
                $count++;
            }
            if (! fflush($handle) || ! fsync($handle)) {
                throw new RuntimeException('Artifact flush failed.');
            }
        } finally {
            fclose($handle);
        }
        $manifest = ['rows' => $count, 'bytes' => filesize($path.'.partial'), 'sha256' => hash_file('sha256', $path.'.partial')];
        if (! rename($path.'.partial', $path)) {
            throw new RuntimeException('Artifact publication failed.');
        }
        self::json($path.'.manifest.json', $manifest);

        return $manifest;
    }

    public static function json(string $path, array $data): void
    {
        if (file_exists($path)) {
            throw new RuntimeException('Refusing to overwrite JSON evidence.');
        }
        $handle = fopen($path.'.partial', 'xb');
        try {
            $text = json_encode($data, JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
            if (fwrite($handle, $text) !== strlen($text) || ! fflush($handle) || ! fsync($handle)) {
                throw new RuntimeException('JSON evidence write failed.');
            }
        } finally {
            fclose($handle);
        }
        if (! rename($path.'.partial', $path)) {
            throw new RuntimeException('JSON evidence publication failed.');
        }
    }

    public static function read(string $path): Generator
    {
        $manifest = json_decode(file_get_contents($path.'.manifest.json'), true, flags: JSON_THROW_ON_ERROR);
        $hash = hash_init('sha256');
        $handle = fopen($path, 'rb');
        $count = $bytes = 0;
        try {
            while (($line = fgets($handle)) !== false) {
                $count++;
                $bytes += strlen($line);
                hash_update($hash, $line);
                yield json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            }
            if (! feof($handle) || $count !== $manifest['rows'] || $bytes !== $manifest['bytes'] || hash_final($hash) !== $manifest['sha256']) {
                throw new RuntimeException('Artifact content drifted.');
            }
        } finally {
            fclose($handle);
        }
    }
}
