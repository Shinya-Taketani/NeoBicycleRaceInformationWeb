<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\PrestartCapture;

use App\Domain\Keirin\Scraping\DTO\RaceRosterSnapshotDto;
use App\Domain\Keirin\Scraping\PrestartEvidence\EvidenceFiles;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

final class Contract
{
    public const VERSION = 'PRESTART-CAPTURE-OFFLINE-BRIDGE-v1';

    public const ROUTE = ['scheme' => 'https', 'host' => 'keirin.jp', 'port' => 443, 'path' => '/pc/racelive'];

    public const FILES = ['request.json', 'response.json', 'private-request.json', 'private-response.json', 'allowed-targets.json', 'execution.json', 'validation.json'];

    public static function restrictions(): array
    {
        return ['historical_as_of_available' => false, 'prediction_use' => 'NOT_AUTHORIZED', 'formal_adoption' => false,
            'official_publication_at' => null, 'points' => null, 'confidence' => null];
    }

    public static function target(array $target): void
    {
        $keys = array_keys($target);
        sort($keys);
        if ($keys !== ['entries', 'race_date', 'race_id', 'race_number', 'track_code']) {
            throw new RuntimeException('Only fixed roster identity target fields are allowed.');
        }
        $date = $target['race_date'];
        $parsed = is_string($date) ? DateTimeImmutable::createFromFormat('!Y-m-d', $date) : false;
        if (! $parsed || $parsed->format('Y-m-d') !== $date || $date < '2022-01-01' || $date > '2025-12-31'
            || ! is_int($target['race_id']) || $target['race_id'] < 1 || ! is_int($target['race_number'])
            || $target['race_number'] < 1 || $target['race_number'] > 12 || ! is_string($target['track_code'])
            || preg_match('/^[0-9]{2}$/D', $target['track_code']) !== 1 || ! is_array($target['entries']) || ! array_is_list($target['entries'])) {
            throw new RuntimeException('Invalid or forbidden offline target identity/year.');
        }
        $entries = [];
        foreach ($target['entries'] as $entry) {
            $entryKeys = array_keys($entry);
            sort($entryKeys);
            if ($entryKeys !== ['bike_number', 'external_player_id']) {
                throw new RuntimeException('Only bike/registration target fields are allowed.');
            }
            $entries[] = [...$entry, 'frame_number' => null];
        }
        RaceRosterSnapshotDto::validateEntries($entries);
    }

    public static function instant(?string $value): ?DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-](?:[01]\d|2[0-3]):[0-5]\d)$/D', $value) !== 1) {
            throw new RuntimeException('Offset-qualified capture timestamp required.');
        }
        $parsed = new DateTimeImmutable($value);
        if (DateTimeImmutable::getLastErrors() !== false) {
            throw new RuntimeException('Invalid capture timestamp.');
        }

        return $parsed;
    }

    public static function id(string $id): void
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $id) !== 1) {
            throw new RuntimeException('Explicit unique capture/attempt ID required.');
        }
    }

    public static function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z');
    }

    public static function codeSeal(): array
    {
        $root = dirname(__DIR__);
        $paths = glob(__DIR__.'/*.php');
        foreach (['PrestartEvidence/EvidenceFiles.php', 'PrestartEvidence/SourceValidator.php', 'PrestartEvidence/RawObservationDecoder.php',
            'Parsers/EmbeddedJsonExtractor.php', 'Parsers/RaceDetailParser.php', 'Support/CharacterEncodingConverter.php',
            'Support/HtmlTextNormalizer.php', 'Support/RaceCategoryPolicy.php', 'Enums/RaceCategory.php',
            'Exceptions/CharacterEncodingConversionException.php', 'Exceptions/ParserException.php',
            'DTO/RaceDetailPageDto.php', 'DTO/RaceDetailEntryDto.php', 'DTO/RaceRosterSnapshotDto.php'] as $path) {
            $paths[] = $root.'/'.$path;
        }
        sort($paths);
        $seal = [];
        foreach ($paths as $path) {
            $seal[substr($path, strlen($root) + 1)] = ['bytes' => filesize($path), 'sha256' => hash_file('sha256', $path)];
        }

        return ['version' => self::VERSION, 'files' => $seal];
    }

    public static function binding(string $uri, array $parameters): string
    {
        ksort($parameters);

        return hash('sha256', EvidenceFiles::json(['method' => 'POST', 'uri' => $uri, 'parameters' => $parameters]));
    }

    public static function writeNew(string $directory, string $name, string $bytes): void
    {
        EvidenceFiles::directory($directory);
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9.-]*$/D', $name) !== 1 || str_contains($name, '..')
            || strlen($bytes) > EvidenceFiles::MAX_BYTES || file_exists($directory.'/'.$name) || is_link($directory.'/'.$name)) {
            throw new RuntimeException('Offline file collision/name/size violation.');
        }
        // Exclusive creation avoids the legacy missing-path lstat warning without changing its frozen API.
        $stream = fopen($directory.'/'.$name, 'xb');
        if ($stream === false) {
            throw new RuntimeException('Cannot exclusively create offline evidence.');
        }
        try {
            if (! chmod($directory.'/'.$name, 0600) || fwrite($stream, $bytes) !== strlen($bytes) || ! fflush($stream) || ! fsync($stream)) {
                throw new RuntimeException('Offline file persistence failed; partial file retained.');
            }
        } finally {
            fclose($stream);
        }
        $handle = fopen($directory, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Cannot verify offline directory persistence.');
        }
        try {
            if (! fsync($handle)) {
                throw new RuntimeException('Offline directory persistence failed.');
            }
        } finally {
            fclose($handle);
        }
    }
}
