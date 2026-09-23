<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\AgariRaceRelative;

use App\Console\Commands\Keirin\BuildAgariRaceRelativeCommand;
use App\Console\Commands\Keirin\ExportAgariRaceRelativeCommand;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Backtest\Experiments\TacticalMeetingGradeAnalysis\Classification;
use App\Domain\Keirin\Scraping\Enums\AgariStatus;
use App\Domain\Keirin\Scraping\Enums\RaceCategory;
use App\Domain\Keirin\Scraping\Enums\RaceEntryResultStatus;
use App\Domain\Keirin\Scraping\Support\RaceCategoryPolicy;
use App\Domain\Keirin\TrackContext\AgariSpeedCalculator;
use App\Domain\Keirin\TrackContext\ExcerptEvidenceVerifier;
use App\Domain\Keirin\TrackContext\LayoutResolution;
use App\Domain\Keirin\TrackContext\StructureValues;
use App\Domain\Keirin\TrackContext\TrackContextMaster;
use Generator;
use RuntimeException;

final class Artifacts
{
    public static function create(string $directory): void
    {
        if ($directory === '' || file_exists($directory) || is_link($directory) || ! mkdir($directory, 0700)) {
            throw new RuntimeException('A new output directory with an existing parent is required.');
        }
    }

    public static function lines(string $path): Generator
    {
        if (is_link($path) || ! is_file($path) || ($handle = fopen($path, 'rb')) === false) {
            throw new RuntimeException('Unreadable regular JSONL input.');
        }
        try {
            while (($line = fgets($handle, 1048577)) !== false) {
                if (! str_ends_with($line, "\n")) {
                    throw new RuntimeException('Truncated or oversized JSONL row.');
                }
                $object = json_decode($line, false, 64, JSON_THROW_ON_ERROR);
                if (! $object instanceof \stdClass) {
                    throw new RuntimeException('JSONL row must be an object.');
                }
                yield json_decode($line, true, 64, JSON_THROW_ON_ERROR);
            }
            if (! feof($handle)) {
                throw new RuntimeException('JSONL read failed.');
            }
        } finally {
            fclose($handle);
        }
    }

    public static function write(string $directory, string $name, iterable $lines): array
    {
        $path = $directory.'/'.$name;
        if (file_exists($path) || is_link($path) || ($handle = fopen($path.'.partial', 'xb')) === false) {
            throw new RuntimeException('Output already exists.');
        }
        $hash = hash_init('sha256');
        $bytes = 0;
        try {
            foreach ($lines as $line) {
                if (fwrite($handle, $line) !== strlen($line)) {
                    throw new RuntimeException('Short artifact write.');
                }
                hash_update($hash, $line);
                $bytes += strlen($line);
            }
            if (! fflush($handle)) {
                throw new RuntimeException('Artifact flush failed.');
            }
        } finally {
            fclose($handle);
        }
        if (! rename($path.'.partial', $path)) {
            throw new RuntimeException('Artifact finalization failed.');
        }

        $seal = ['bytes' => $bytes, 'sha256' => hash_final($hash)];
        Files::verify($path, $seal);

        return $seal;
    }

    public static function json(string $directory, string $name, array $data): array
    {
        return self::write($directory, $name, [Files::canonical($data)."\n"]);
    }

    public static function publish(string $directory, array $manifest): void
    {
        foreach ($manifest['files'] as $name => $seal) {
            Files::verify($directory.'/'.$name, $seal);
        }
        $seal = self::json($directory, 'manifest.json', $manifest);
        self::json($directory, 'COMPLETE.json', $seal);
    }

    public static function input(string $directory): array
    {
        Files::verify($directory.'/manifest.json', Files::json($directory.'/COMPLETE.json'));
        $manifest = Files::json($directory.'/manifest.json');
        if (($manifest['version'] ?? null) !== Contract::VERSION || ($manifest['kind'] ?? null) !== 'INPUT'
            || ($manifest['source'] ?? null) !== 'keirin_jp' || ($manifest['order'] ?? null) !== 'race_id_ASC'
            || array_keys($manifest['files'] ?? []) !== ['races.jsonl'] || ! is_int($manifest['race_count'] ?? null)
            || ! is_int($manifest['result_count'] ?? null)) {
            throw new RuntimeException('Invalid input manifest.');
        }
        Contract::dates($manifest['from'], $manifest['to']);
        Files::verify($directory.'/races.jsonl', $manifest['files']['races.jsonl']);

        return $manifest;
    }

    public static function code(): array
    {
        $paths = glob(__DIR__.'/*.php');
        foreach ([TrackContextMaster::class,
            AgariSpeedCalculator::class,
            StructureValues::class,
            LayoutResolution::class,
            ExcerptEvidenceVerifier::class,
            Classification::class,
            Files::class, RaceCategoryPolicy::class, RaceCategory::class, AgariStatus::class,
            RaceEntryResultStatus::class, ExportAgariRaceRelativeCommand::class, BuildAgariRaceRelativeCommand::class] as $class) {
            $paths[] = (new \ReflectionClass($class))->getFileName();
        }
        sort($paths, SORT_STRING);
        $hashes = [];
        foreach ($paths as $path) {
            $hashes[str_replace(base_path().'/', '', $path)] = hash_file('sha256', $path);
        }

        return $hashes;
    }
}
