<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\TacticalInputReadiness;

use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Statistics\AgariC1Context\Contract as Context;
use App\Domain\Keirin\Statistics\AgariC1Input\Contract as C1;
use App\Domain\Keirin\Statistics\AgariC1Input\Stream;
use App\Domain\Keirin\Statistics\AgariC1Input\Validator;
use App\Domain\Keirin\Statistics\AgariRaceRelative\Artifacts;
use PDO;
use RuntimeException;

final class Universe
{
    public const FIXED = [
        'c1' => ['path' => '/home/shinya/neo-keirin-artifacts/stat35-c1-input-02/run-20260930-054458-8739da4b/result',
            'seal' => ['bytes' => 10205, 'sha256' => '7f4356b93f90203dc727dc95d6215a8d8ce3f44869c4441c3981087ff1099c26']],
        'targets' => ['path' => '/home/shinya/neo-keirin-artifacts/stat35-c1-context-01/run-20260928-215453-d152edab/extraction',
            'seal' => ['bytes' => 6331, 'sha256' => '1e1975b5d2c0b598870bb3b860a1cfdbe9bbfbcbaba035e3d878be5928f9a021']],
        'mapping' => ['path' => '/home/shinya/neo-keirin-artifacts/stat35-c1-context-01/run-20260928-215453-d152edab/mapping',
            'seal' => ['bytes' => 3564, 'sha256' => '5facd83259a5b2b147115ff1632632a6f7a11f96e07f9f4d3078286743eb839b']],
    ];

    // Trust-anchor injection is restricted to synthetic tests; CLI has no pin override.
    public function __construct(private readonly array $pins = self::FIXED) {}

    public function prepare(string $output): array
    {
        $manifests = $seals = [];
        foreach ($this->pins as $kind => $pin) {
            $path = $pin['path'];
            Files::verify($path.'/manifest.json', $pin['seal']);
            Files::same($pin['seal'], Files::json($path.'/COMPLETE.json'), 'fixed source COMPLETE');
            $m = Files::json($path.'/manifest.json');
            $manifests[$kind] = $m;
            $names = match ($kind) {
                'c1' => array_map(fn ($y) => 'c1-'.$y.'.jsonl', Contract::YEARS),
                'targets' => ['targets.jsonl'],
                'mapping' => ['mapping-audit.jsonl', 'provenance.json'],
            };
            foreach (['manifest.json', 'COMPLETE.json', ...$names] as $name) {
                $seal = match ($name) {
                    'manifest.json' => $pin['seal'], 'COMPLETE.json' => Files::identity($path.'/'.$name),
                    default => $m['files'][$name] ?? throw new RuntimeException('Missing fixed source seal.'),
                };
                Files::verify($path.'/'.$name, $seal);
                $seals[$path.'/'.$name] = ['bytes' => $seal['bytes'], 'sha256' => $seal['sha256']];
            }
        }
        $c1 = $manifests['c1'];
        $targets = $manifests['targets'];
        $mapping = $manifests['mapping'];
        if (($c1['contract']['version'] ?? null) !== C1::VERSION || ($c1['status'] ?? null) !== 'INPUTS_PREPARED'
            || ($c1['contract']['years'] ?? null) !== Contract::YEARS
            || ($targets['version'] ?? null) !== Context::VERSION || ($targets['kind'] ?? null) !== 'EXTRACTION'
            || ($mapping['version'] ?? null) !== Context::VERSION || ($mapping['kind'] ?? null) !== 'MAPPING') {
            throw new RuntimeException('Fixed source version mismatch.');
        }
        $provenance = Files::json($this->pins['mapping']['path'].'/provenance.json');
        Files::same($this->pins['targets']['seal'], $provenance['extraction_manifest'], 'mapping extraction');
        Files::same($targets['source']['manifest'], $provenance['source']['manifest'], 'mapping origin');
        Files::same($targets['source']['manifest'], $c1['source']['seals'][$c1['source']['c1'].'/manifest.json'], 'C1 origin');
        foreach (Contract::YEARS as $year) {
            $expected = ['races' => $c1['source']['expected_rows'][$year], 'entries' => $c1['source']['expected_targets'][$year],
                'non_result_sha256' => $c1['files']['c1-'.$year.'.jsonl']['sha256']];
            Files::same($expected, $targets['source']['years'][$year], 'fixed target cohort');
            Files::same($expected, $provenance['source']['years'][$year], 'mapping cohort');
        }
        $db = self::index($output.'/universe.sqlite');
        $put = $db->prepare('INSERT INTO entries(id,year,body) VALUES (?,?,?)');
        $get = $db->prepare('SELECT year,body,used FROM entries WHERE id=?');
        $use = $db->prepare('UPDATE entries SET used=1 WHERE id=?');
        $racePut = $db->prepare('INSERT INTO races(id) VALUES (?)');
        $db->beginTransaction();
        foreach (Artifacts::lines($this->pins['targets']['path'].'/targets.jsonl') as $race) {
            C1::keys($race, ['year', 'race_id', 'entries']);
            self::race($race);
            $racePut->execute([$race['race_id']]);
            foreach ($race['entries'] as $t) {
                Contract::target($t, $race['year']);
                if ($t['race_id'] !== $race['race_id']) {
                    throw new RuntimeException('Target race mismatch.');
                }
                $put->execute([$t['entry_id'], $race['year'], Files::canonical($t)]);
            }
        }
        $years = [];
        foreach (Contract::YEARS as $year) {
            $nr = $ne = 0;
            foreach (Artifacts::lines($this->pins['c1']['path'].'/c1-'.$year.'.jsonl') as $race) {
                Validator::race($race, $year);
                foreach ($race['entries'] as $e) {
                    $get->execute([$e['id']]);
                    $found = $get->fetch(PDO::FETCH_ASSOC);
                    $get->closeCursor();
                    $t = $found ? json_decode($found['body'], true, flags: JSON_THROW_ON_ERROR) : null;
                    if ($t === null || $found['year'] !== $year || $found['used'] !== 0
                        || $t['race_id'] !== $race['race_id'] || $t['bike'] !== $e['bike']) {
                        throw new RuntimeException('C1/target identity mismatch.');
                    }
                    $use->execute([$e['id']]);
                    $ne++;
                }
                $nr++;
            }
            if ($nr !== $c1['source']['expected_rows'][$year] || $ne !== $c1['source']['expected_targets'][$year]) {
                throw new RuntimeException('Fixed C1 counts mismatch.');
            }
            $years[$year] = ['races' => $nr, 'entries' => $ne];
        }
        $map = $db->prepare('UPDATE entries SET external=?,mapped=1 WHERE id=? AND mapped=0');
        foreach (Artifacts::lines($this->pins['mapping']['path'].'/mapping-audit.jsonl') as $row) {
            $get->execute([$row['entry_id']]);
            $found = $get->fetch(PDO::FETCH_ASSOC);
            $get->closeCursor();
            if (! $found || $row['year'] !== $found['year']) {
                throw new RuntimeException('Unexpected mapping identity.');
            }
            Files::same(json_decode($found['body'], true, flags: JSON_THROW_ON_ERROR), $row['target'], 'mapped target');
            $external = $row['context']['external_player_id'] ?? null;
            if (($row['checks']['identity_matched'] ?? null) !== true || ! is_string($external)
                || ! preg_match('/\A[0-9]+\z/', $external)) {
                $external = null;
            }
            $map->execute([$external, $row['entry_id']]);
            if ($map->rowCount() !== 1) {
                throw new RuntimeException('Duplicate mapping record.');
            }
        }
        if ((int) $db->query('SELECT COUNT(*) FROM entries WHERE used=0 OR mapped=0')->fetchColumn() !== 0) {
            throw new RuntimeException('Incomplete fixed cohort.');
        }
        $db->commit();
        $stream = new Stream($output.'/targets.jsonl');
        $externalQuery = $db->prepare('SELECT external FROM entries WHERE id=?');
        foreach (Artifacts::lines($this->pins['targets']['path'].'/targets.jsonl') as $race) {
            foreach ($race['entries'] as &$t) {
                $externalQuery->execute([$t['entry_id']]);
                $t['external_player_id'] = $externalQuery->fetchColumn() ?: null;
                $externalQuery->closeCursor();
            }
            unset($t);
            $stream->row($race);
        }
        self::verify($seals);

        return ['years' => $years, 'seals' => $seals, 'target_seal' => $stream->finish()];
    }

    public static function index(string $path): PDO
    {
        if (file_exists($path) || is_link($path)) {
            throw new RuntimeException('Index already exists.');
        }
        $db = new PDO('sqlite:'.$path, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db->exec('PRAGMA cache_size=-2048; PRAGMA temp_store=FILE;
            CREATE TABLE entries(id INTEGER PRIMARY KEY, year INTEGER, body TEXT, used INTEGER DEFAULT 0, mapped INTEGER DEFAULT 0, external TEXT);
            CREATE TABLE races(id INTEGER PRIMARY KEY);');

        return $db;
    }

    public static function race(array $race): void
    {
        if (! in_array($race['year'], Contract::YEARS, true) || ! C1::id($race['race_id'])
            || ! is_array($race['entries']) || ! array_is_list($race['entries'])
            || count($race['entries']) < 5 || count($race['entries']) > 9) {
            throw new RuntimeException('Invalid target year/race/entrants, including 2026.');
        }
        $bikes = array_column($race['entries'], 'bike');
        if (count($bikes) !== count(array_unique($bikes))) {
            throw new RuntimeException('Duplicate target bike.');
        }
    }

    public static function verify(array $seals): void
    {
        foreach ($seals as $path => $seal) {
            Files::verify($path, $seal);
        }
    }
}
