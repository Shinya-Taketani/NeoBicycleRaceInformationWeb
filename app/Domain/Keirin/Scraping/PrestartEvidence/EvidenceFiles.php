<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\PrestartEvidence;

use RuntimeException;

final class EvidenceFiles
{
    public const VERSION = 'C1-RACECARD-PRESTART-EVIDENCE-PHASE1-SYNTHETIC-v1';

    public const MAX_BYTES = 8 * 1024 * 1024;

    public const FILES = ['raw.bin', 'capture.json', 'targets.json', 'private-source.json', 'validation.json'];

    public static function directory(string $path): array
    {
        if ($path === '' || ! str_starts_with($path, '/') || str_contains($path, "\0") || str_contains($path, '\\')
            || preg_match('~(?:^|/)(?:\.|\.\.)(?:/|$)|//|/$~', $path) === 1) {
            throw new RuntimeException('Canonical absolute evidence path required.');
        }
        $prefix = '';
        $device = null;
        foreach (explode('/', substr($path, 1)) as $component) {
            $prefix .= '/'.$component;
            $stat = self::stat($prefix);
            if ($stat === null || ($stat['mode'] & 0170000) !== 0040000) {
                throw new RuntimeException('Evidence directory contains a link or invalid component.');
            }
            $device = $stat['dev'];
        }
        if (realpath($path) !== $path) {
            throw new RuntimeException('Evidence path cannot be resolved.');
        }
        $mounts = file('/proc/self/mountinfo', FILE_IGNORE_NEW_LINES);
        if ($mounts === false) {
            throw new RuntimeException('Cannot verify evidence mount boundaries.');
        }
        foreach ($mounts as $line) {
            $parts = explode(' ', $line);
            $mount = strtr($parts[4], ['\\040' => ' ', '\\011' => "\t", '\\012' => "\n", '\\134' => '\\']);
            if ($mount === $path || str_starts_with($mount, $path.'/')) {
                throw new RuntimeException('Mount boundary inside evidence directory.');
            }
        }

        return ['dev' => $device, 'ino' => self::stat($path)['ino']];
    }

    public static function writableRoot(string $path): array
    {
        $identity = self::directory($path);
        $repository = dirname(__DIR__, 5);
        $stat = self::stat($path);
        if (in_array($path, ['/tmp', '/home', '/home/shinya', '/home/shinya/Desktop', '/home/shinya/Desktop/Trash'], true)
            || $path === $repository || str_starts_with($repository, $path.'/') || str_starts_with($path, $repository.'/')
            || ($stat['mode'] & 0777) !== 0700 || $stat['uid'] !== posix_geteuid()) {
            throw new RuntimeException('Dedicated synthetic root required; protected directory refused.');
        }

        return $identity;
    }

    public static function writeNew(string $directory, string $name, string $bytes): void
    {
        self::directory($directory);
        self::name($name);
        if (strlen($bytes) > self::MAX_BYTES || self::stat($directory.'/'.$name) !== null) {
            throw new RuntimeException('Evidence collision or size limit; nothing overwritten.');
        }
        $handle = fopen($directory.'/'.$name, 'xb');
        if ($handle === false) {
            throw new RuntimeException('Exclusive evidence creation failed.');
        }
        try {
            if (! chmod($directory.'/'.$name, 0600) || fwrite($handle, $bytes) !== strlen($bytes)
                || ! fflush($handle) || ! fsync($handle)) {
                throw new RuntimeException('Evidence persistence failed; partial bytes retained.');
            }
        } finally {
            fclose($handle);
        }
    }

    public static function read(string $directory, string $name): string
    {
        $identity = self::directory($directory);
        self::name($name);
        $stat = self::stat($directory.'/'.$name);
        if ($stat === null || ($stat['mode'] & 0170000) !== 0100000 || $stat['nlink'] !== 1
            || $stat['dev'] !== $identity['dev'] || $stat['size'] > self::MAX_BYTES) {
            throw new RuntimeException('Missing, linked, oversized or invalid evidence file.');
        }
        $handle = fopen($directory.'/'.$name, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Cannot open evidence.');
        }
        try {
            $opened = fstat($handle);
            if ($opened === false || $opened['dev'] !== $stat['dev'] || $opened['ino'] !== $stat['ino']) {
                throw new RuntimeException('Evidence file changed while opening.');
            }
            $bytes = stream_get_contents($handle, self::MAX_BYTES + 1);
            if ($bytes === false || strlen($bytes) !== $stat['size'] || strlen($bytes) > self::MAX_BYTES) {
                throw new RuntimeException('Evidence file size changed.');
            }

            return $bytes;
        } finally {
            fclose($handle);
        }
    }

    public static function json(array $data): string
    {
        return json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
    }

    public static function object(string $bytes): array
    {
        if (! is_object(json_decode($bytes, false, 64, JSON_THROW_ON_ERROR))) {
            throw new RuntimeException('Evidence JSON object required.');
        }

        return json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
    }

    public static function seal(string $bytes): array
    {
        return ['bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes)];
    }

    public static function codeSeal(): array
    {
        $paths = glob(__DIR__.'/*.php');
        sort($paths);
        $seal = [];
        foreach ($paths as $path) {
            $seal[basename($path)] = ['bytes' => filesize($path), 'sha256' => hash_file('sha256', $path)];
        }

        return $seal;
    }

    public static function stat(string $path): ?array
    {
        clearstatcache(true, $path);
        $stat = @lstat($path);

        return $stat === false ? null : $stat;
    }

    private static function name(string $name): void
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9.-]*$/D', $name) !== 1 || str_contains($name, '..')) {
            throw new RuntimeException('Invalid evidence filename.');
        }
    }
}
