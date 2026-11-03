<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\InitialHistoryMetadata;

use App\Domain\Keirin\Backtest\ChronologicalValidation\Json;
use DateTimeImmutable;
use DateTimeZone;
use Generator;
use RuntimeException;
use Throwable;

final class Evidence
{
    public const VERSION = 'INITIAL-HISTORY-METADATA-v1';

    public const TYPES = ['CONTEXT', 'ENTRY', 'FETCH', 'IMPORT', 'RESULT'];

    public const SCOPE_FILES = ['CONTEXT' => 'races.jsonl', 'ENTRY' => 'entries.jsonl', 'FETCH' => 'fetches.jsonl', 'IMPORT' => 'imports.jsonl', 'RESULT' => 'results.jsonl'];

    public static function require(bool $condition, string $reason): void
    {
        if (! $condition) {
            throw new RuntimeException($reason);
        }
    }

    public static function create(string $path): void
    {
        self::require(str_starts_with($path, '/') && ! preg_match('~(?:^|/)(?:\.|\.\.)(?:/|$)|//|/$~', $path)
            && ! file_exists($path) && ! is_link($path), 'New canonical output root required.');
        $parent = dirname($path);
        self::require(realpath($parent) === $parent && is_dir($parent), 'Existing canonical parent required.');
        for ($p = $parent; $p !== '/'; $p = dirname($p)) {
            self::require(! is_link($p), 'Symlink output parent forbidden.');
        }
        self::require(mkdir($path, 0700), 'Cannot create dedicated output.');
    }

    public static function json(string $root, string $name, array $data): array
    {
        self::require(! file_exists($root.'/'.$name) && ! is_link($root.'/'.$name), 'Evidence already exists.');
        Json::write($root.'/'.$name, Json::encode($data));

        return Json::seal($root.'/'.$name);
    }

    public static function rows(string $path, ?array $seal = null, bool $objects = false): Generator
    {
        Json::file($path);
        $stream = fopen($path, 'rb');
        $hash = hash_init('sha256');
        $bytes = $rows = 0;
        try {
            while (($line = fgets($stream, 1048577)) !== false) {
                self::require(str_ends_with($line, "\n"), 'Oversized or incomplete metadata row.');
                hash_update($hash, $line);
                $bytes += strlen($line);
                $rows++;
                $value = json_decode($line, ! $objects, 64, JSON_THROW_ON_ERROR);
                self::require($objects ? $value instanceof \stdClass : is_array($value) && ! array_is_list($value), 'Metadata object required.');
                yield $objects ? (array) $value : $value;
            }
            self::require(feof($stream), 'Metadata read failed.');
        } finally {
            fclose($stream);
        }
        $actual = ['bytes' => $bytes, 'sha256' => hash_final($hash)];
        if ($seal !== null) {
            self::require($actual['bytes'] === ($seal['bytes'] ?? $seal['byte_count'] ?? null)
                && $actual['sha256'] === $seal['sha256'] && (! isset($seal['rows']) || $seal['rows'] === $rows), 'Metadata stream seal/count mismatch.');
        }
    }

    public static function writeRows(string $root, string $name, iterable $rows): array
    {
        self::require(! file_exists($root.'/'.$name) && ! is_link($root.'/'.$name), 'Metadata output already exists.');
        $stream = fopen($root.'/'.$name, 'xb');
        self::require(is_resource($stream), 'Exclusive metadata output failed.');
        $hash = hash_init('sha256');
        $bytes = $count = 0;
        try {
            foreach ($rows as $row) {
                $line = Json::encode($row);
                self::require(strlen($line) <= 1048576 && fwrite($stream, $line) === strlen($line), 'Metadata output failed; partial evidence retained.');
                hash_update($hash, $line);
                $bytes += strlen($line);
                $count++;
            }
            self::require(fflush($stream) && fsync($stream), 'Metadata flush failed.');
        } finally {
            fclose($stream);
        }

        return ['bytes' => $bytes, 'sha256' => hash_final($hash), 'rows' => $count];
    }

    public static function publish(string $root, string $name, array $manifest): array
    {
        $seal = self::json($root, $name, $manifest);
        self::json($root, 'COMPLETE.json', $seal);

        return ['path' => $root, 'manifest' => $seal, 'status' => 'COMPLETE_METADATA_ONLY', 'initial_history_epoch' => 'NOT_ESTABLISHED'];
    }

    public static function open(string $root, string $name, string $kind): array
    {
        Json::verify($root.'/'.$name, Json::read($root.'/COMPLETE.json'));
        $m = Json::read($root.'/'.$name);
        self::require($m['version'] === self::VERSION && $m['kind'] === $kind && ! file_exists($root.'/FAILED.json'), 'Invalid metadata terminal/version.');
        $expected = [...array_keys($m['files']), $name, 'COMPLETE.json'];
        $actual = array_values(array_diff(scandir($root), ['.', '..']));
        sort($expected);
        sort($actual);
        self::require($actual === $expected, 'Unexpected or missing metadata bundle files.');
        foreach ($actual as $file) {
            self::require(basename($file) === $file, 'Invalid metadata filename.');
            Json::file($root.'/'.$file);
        }

        return $m;
    }

    public static function day(string $day): void
    {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $day, new DateTimeZone('Asia/Tokyo'));
        self::require($d !== false && $d->format('Y-m-d') === $day && $day >= '2022-01-01' && $day < '2026-01-01', 'Race outside fixed development period.');
    }

    public static function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d\TH:i:s.uP');
    }

    public static function code(): array
    {
        $paths = [...glob(__DIR__.'/*.php'), dirname(__DIR__, 4).'/Console/Commands/Keirin/SupplementInitialHistoryMetadataCommand.php', (new \ReflectionClass(Json::class))->getFileName()];
        sort($paths);
        $out = [];
        foreach ($paths as $path) {
            $out[basename($path)] = Json::seal($path);
        }

        return $out;
    }

    public static function failed(string $root, Throwable $error, string $phase, string $started, int $clock): void
    {
        self::json($root, 'FAILED.json', ['phase' => $phase, 'exception' => $error::class, 'code' => (string) $error->getCode(),
            'started_at' => $started, 'finished_at' => self::now(), 'elapsed_seconds' => (hrtime(true) - $clock) / 1e9,
            'peak_memory_bytes' => memory_get_peak_usage(true), 'partial_files_retained' => true, 'business_writes' => 0]);
    }
}
