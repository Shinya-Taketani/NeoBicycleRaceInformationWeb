<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\SavedWeather;

use Generator;
use PDO;
use RuntimeException;

final class Files
{
    public const VERSION = 'STAT-21-SAVED-WEATHER-BATCH-v2-PR131';

    public static function json(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)."\n";
    }

    public static function seal(string $path): array
    {
        if (realpath($path) !== $path || is_link($path) || ! is_file($path)) {
            throw new RuntimeException('INVALID_EVIDENCE_FILE');
        }
        clearstatcache(true, $path);

        return ['path' => $path, 'bytes' => filesize($path), 'sha256' => hash_file('sha256', $path)];
    }

    public static function verify(array $seal): void
    {
        if (self::seal($seal['path']) !== $seal) {
            throw new RuntimeException('SEALED_INPUT_CHANGED');
        }
    }

    public static function read(string $path): array
    {
        self::seal($path);

        return json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }

    public static function create(string $path)
    {
        if (! str_starts_with($path, '/') || realpath(dirname($path)) !== dirname($path)
            || file_exists($path) || is_link($path)) {
            throw new RuntimeException('OUTPUT_COLLISION_OR_INVALID_PATH');
        }
        $stream = fopen($path, 'xb');
        if ($stream === false || ! chmod($path, 0600)) {
            throw new RuntimeException('OUTPUT_CREATE_FAILED');
        }

        return $stream;
    }

    public static function append($stream, mixed $row): void
    {
        $bytes = self::json($row);
        if (fwrite($stream, $bytes) !== strlen($bytes)) {
            throw new RuntimeException('OUTPUT_SHORT_WRITE');
        }
    }

    public static function write(string $path, mixed $value): void
    {
        $stream = self::create($path);
        try {
            self::append($stream, $value);
        } finally {
            fclose($stream);
        }
    }

    public static function directory(string $path): void
    {
        if (! str_starts_with($path, '/') || realpath(dirname($path)) !== dirname($path)
            || file_exists($path) || is_link($path) || ! mkdir($path, 0700)) {
            throw new RuntimeException('NEW_OUTPUT_DIRECTORY_REQUIRED');
        }
    }

    public static function rows(string $path): Generator
    {
        $stream = fopen($path, 'rb');
        if ($stream === false) {
            throw new RuntimeException('INPUT_OPEN_FAILED');
        }
        try {
            while (($line = fgets($stream)) !== false) {
                yield json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            }
            if (! feof($stream)) {
                throw new RuntimeException('INPUT_READ_FAILED');
            }
        } finally {
            fclose($stream);
        }
    }

    public static function sqlite(string $path): PDO
    {
        self::seal($path);
        $db = new PDO('sqlite:file:'.$path.'?mode=ro', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db->exec('PRAGMA query_only=ON');
        if ((int) $db->query('PRAGMA query_only')->fetchColumn() !== 1) {
            throw new RuntimeException('SQLITE_NOT_READ_ONLY');
        }

        return $db;
    }

    public static function select(PDO $db, string $sql, array $bindings = []): array
    {
        $q = $db->prepare($sql);
        $q->execute($bindings);

        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function code(): array
    {
        $paths = array_merge(glob(__DIR__.'/*.php'), [
            app_path('Console/Commands/Keirin/PrepareSavedResultWeatherCommand.php'),
            app_path('Console/Commands/Keirin/BuildSavedResultWeatherCommand.php'),
            app_path('Console/Commands/Keirin/DeriveSavedResultWeatherCommand.php'),
            app_path('Domain/Keirin/Statistics/Services/SavedResultWeatherProjectionService.php'),
            app_path('Domain/Keirin/Statistics/OfficialMethodHistory/Reader.php'),
            app_path('Domain/Keirin/Statistics/OfficialResultEvidence/Verifier.php'),
            app_path('Domain/Keirin/Statistics/OfficialMethodHistory/Contract.php'),
            app_path('Domain/Keirin/Statistics/AgariC1Context/ReadOnlySession.php'),
            app_path('Domain/Keirin/Statistics/AgariRaceRelative/ConnectionGuard.php'),
            app_path('Domain/Keirin/Audit/Stat35DataReadiness/RawReader.php'),
            app_path('Domain/Keirin/Audit/Stat35DataReadiness/Contract.php'),
            app_path('Domain/Keirin/Backtest/Experiments/TacticalHistoryFinal/Files.php'),
        ], glob(app_path('Domain/Keirin/Scraping/Parsers/*.php')),
            glob(app_path('Domain/Keirin/Scraping/Support/*.php')),
            glob(app_path('Domain/Keirin/Scraping/DTO/*.php')),
            glob(app_path('Domain/Keirin/Scraping/Enums/*.php')),
            array_map(fn ($name) => app_path('Models/'.$name.'.php'), ['Race', 'RaceEntry', 'RaceResult', 'RaceResultImport', 'ScrapingFetchLog', 'Racetrack']));
        sort($paths);

        return array_map(self::seal(...), $paths);
    }
}
