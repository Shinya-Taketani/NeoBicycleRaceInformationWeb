<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\PrestartEvidence\Attributes;

use App\Domain\Keirin\Scraping\PrestartEvidence\EvidenceFiles;
use App\Domain\Keirin\Scraping\PrestartEvidence\ObservationProjector;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

final class BundleFiles
{
    public const VERSION = 'STAT39-PRESTART-ATTRIBUTES-SYNTHETIC-v1';

    public const FILES = ['source-reference.json', 'attribute-observations.jsonl', 'scenario-time.json', 'validation.json', 'execution.json'];

    public static function codeSeal(): array
    {
        $result = [];
        foreach (EvidenceFiles::codeSeal() as $name => $seal) {
            $result['PrestartEvidence/'.$name] = $seal;
        }
        $paths = glob(__DIR__.'/*.php');
        sort($paths);
        foreach ($paths as $path) {
            $result['PrestartEvidence/Attributes/'.basename($path)] = ['bytes' => filesize($path), 'sha256' => hash_file('sha256', $path)];
        }

        return ['contract' => self::VERSION, 'source_contract' => EvidenceFiles::VERSION, 'source_timing' => ObservationProjector::TIMING_VERSION,
            'decoder' => LosslessJson::VERSION, 'projection' => AttributeProjector::VERSION,
            'timing' => ScenarioTiming::VERSION, 'files' => $result];
    }

    public static function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z');
    }

    public static function jsonl(array $races): string
    {
        $bytes = '';
        foreach ($races as $race) {
            $bytes .= json_encode($race, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
            if (strlen($bytes) > EvidenceFiles::MAX_BYTES) {
                throw new RuntimeException('Finite attribute evidence size exceeded.');
            }
        }

        return $bytes;
    }

    public static function restrictions(): array
    {
        return ['historical_as_of_available' => false, 'prediction_use' => 'NOT_AUTHORIZED', 'formal_adoption' => false];
    }

    public static function expectedHash(string $hash): void
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1) {
            throw new RuntimeException('External expected SHA-256 required.');
        }
    }
}
