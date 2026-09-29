<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\AgariC1Context;

use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Statistics\AgariC1Input\Contract as InputContract;
use App\Domain\Keirin\Statistics\AgariC1Input\SourceProjector;
use App\Domain\Keirin\Statistics\AgariC1Input\Sources;
use App\Domain\Keirin\Statistics\AgariC1Input\Stream;
use App\Domain\Keirin\Statistics\AgariRaceRelative\Artifacts;
use PDO;
use RuntimeException;

final class Targets
{
    // Injection is for synthetic tests, never a CLI-supplied trust anchor.
    public function __construct(private readonly array $pin = Sources::PINS['c1']) {}

    public function prepare(string $source, string $output): array
    {
        Files::verify($source.'/manifest.json', $this->pin);
        $manifest = Files::json($source.'/manifest.json');
        if (($manifest['calculation_version'] ?? null) !== InputContract::C1_VERSION
            || array_keys($manifest['manifests'] ?? []) !== InputContract::YEARS) {
            throw new RuntimeException('Unknown fixed C1 version/years.');
        }
        $seals = [$source.'/manifest.json' => $this->pin];
        foreach (InputContract::YEARS as $year) {
            foreach (['inputs', 'history'] as $kind) {
                $seal = $manifest['manifests'][$year][$kind];
                if (! is_int($seal['rows'] ?? null) || $seal['rows'] < 0) {
                    throw new RuntimeException('Invalid fixed source count.');
                }
                Files::verify($source.'/'.$kind.'-'.$year.'.jsonl', $seal);
                $seals[$source.'/'.$kind.'-'.$year.'.jsonl'] = ['bytes' => $seal['bytes'], 'sha256' => $seal['sha256']];
            }
        }
        $path = $output.'/targets.sqlite';
        if (file_exists($path) || is_link($path)) {
            throw new RuntimeException('Target index already exists.');
        }
        $db = new PDO('sqlite:'.$path, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db->exec('PRAGMA cache_size=-2048; PRAGMA temp_store=FILE;
            CREATE TABLE targets(entry INTEGER PRIMARY KEY, year INTEGER, body TEXT, used INTEGER DEFAULT 0);
            CREATE TABLE races(id INTEGER PRIMARY KEY);');
        $put = $db->prepare('INSERT INTO targets(entry,year,body) VALUES (?,?,?)');
        $get = $db->prepare('SELECT year,body,used FROM targets WHERE entry=?');
        $use = $db->prepare('UPDATE targets SET used=1 WHERE entry=?');
        $racePut = $db->prepare('INSERT INTO races VALUES (?)');
        $stream = new Stream($output.'/targets.jsonl');
        $counts = [];
        $db->beginTransaction();
        foreach (InputContract::YEARS as $year) {
            $historyCount = 0;
            foreach (Artifacts::lines($source.'/history-'.$year.'.jsonl') as $row) {
                $target = [];
                foreach (Contract::TARGET_FIELDS as $key) {
                    if (! is_array($row['target'] ?? null) || ! array_key_exists($key, $row['target'])) {
                        throw new RuntimeException('Missing fixed target field.');
                    }
                    $target[$key] = $row['target'][$key];
                }
                Contract::target($target, $year);
                $put->execute([$target['entry_id'], $year, Files::canonical($target)]);
                $historyCount++;
            }
            $races = $entries = 0;
            $projection = hash_init('sha256');
            foreach (Artifacts::lines($source.'/inputs-'.$year.'.jsonl') as $raw) {
                $race = SourceProjector::project($raw, $year, InputContract::C1_VERSION);
                hash_update($projection, Files::canonical($race)."\n");
                $racePut->execute([$race['race_id']]);
                $targets = [];
                foreach ($race['entries'] as $entry) {
                    $get->execute([$entry['id']]);
                    $found = $get->fetch(PDO::FETCH_ASSOC);
                    $get->closeCursor();
                    $target = $found ? json_decode($found['body'], true, flags: JSON_THROW_ON_ERROR) : null;
                    if ($target === null || $found['used'] !== 0 || $found['year'] !== $year
                        || $target['race_id'] !== $race['race_id'] || $target['bike'] !== $entry['bike']) {
                        throw new RuntimeException('Fixed C1 input/target mismatch or duplicate.');
                    }
                    $use->execute([$entry['id']]);
                    $targets[] = $target;
                    $entries++;
                }
                $stream->row(['year' => $year, 'race_id' => $race['race_id'], 'entries' => $targets]);
                $races++;
            }
            if ($races !== $manifest['manifests'][$year]['inputs']['rows']
                || $historyCount !== $manifest['manifests'][$year]['history']['rows'] || $entries !== $historyCount) {
                throw new RuntimeException('Fixed C1 cohort counts disagree.');
            }
            $counts[$year] = ['races' => $races, 'entries' => $entries, 'non_result_sha256' => hash_final($projection)];
        }
        $db->commit();
        $seal = $stream->finish();
        self::verify($seals);

        return ['manifest' => $this->pin, 'seals' => $seals, 'years' => $counts, 'target_seal' => $seal];
    }

    public static function verify(array $seals): void
    {
        foreach ($seals as $path => $seal) {
            Files::verify($path, $seal);
        }
    }
}
