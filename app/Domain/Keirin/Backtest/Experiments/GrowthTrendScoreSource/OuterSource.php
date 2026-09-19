<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\GrowthTrendScoreSource;

use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\JsonlArtifact;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use Generator;
use RuntimeException;

class OuterSource
{
    public function open(string $root): array
    {
        $registry = $root.'/report-export-manifest.json';
        if (realpath($root) !== $root || Files::identity($registry)['sha256'] !== '4268b801b74b77cfb3a94b64832ff483e9eb5e3221ee8e75ceb467e5a5cf92e6') {
            throw new RuntimeException('Expected reviewed run-01 Outer registry.');
        }
        $index = Files::json($registry);
        $files = [$registry => Files::identity($registry)];
        $deferred = [];
        $add = function (string $relative, bool $outcome = false) use ($root, $index, &$files, &$deferred): string {
            $path = $root.'/'.$relative;
            $seal = $index['included'][$relative] ?? $index['omitted'][$relative] ?? null;
            if (! is_array($seal)) {
                throw new RuntimeException('Unregistered Outer source.');
            }
            $seal = ['bytes' => $seal['bytes'], 'sha256' => $seal['sha256']];
            if ($outcome) {
                $deferred[$path] = $seal;
            } else {
                Files::verify($path, $seal);
                $files[$path] = $seal;
            }

            return $path;
        };
        $run = Files::json($add('run-01/model-run.json'));
        $completion = Files::json($add('run-01-completion.json'));
        Files::same($run['outer_paths'], $completion['outer_paths'], 'Outer run identity');
        $inputs = Files::json($add('inputs-v2/manifest.json'));
        $years = [];
        foreach (Contract::COUNTS as $year => $n) {
            foreach (['input' => "inputs-v2/inputs-$year.jsonl", 'prediction' => "run-01/C1-fit-$year/predictions.jsonl",
                'labels' => "run-01/labels-$year.jsonl"] as $kind => $relative) {
                $years[$year][$kind] = $add($relative, $kind === 'labels');
                $add($relative.'.manifest.json', $kind === 'labels');
            }
            if ($years[$year]['prediction'] !== $run['outer_paths'][$year]['C1'] || $years[$year]['labels'] !== $run['outer_paths'][$year]['labels']) {
                throw new RuntimeException('Outer prediction identity mismatch.');
            }
            Files::same($inputs['manifests'][$year]['inputs'], Files::json($years[$year]['input'].'.manifest.json'), 'Outer input manifest');
        }

        return ['files' => $files, 'deferred' => $deferred, 'years' => $years, 'counts' => Contract::COUNTS, 'entries' => Contract::ENTRIES];
    }

    public static function verify(array $files): void
    {
        foreach ($files as $path => $seal) {
            Files::verify($path, $seal);
        }
    }

    public function targets(array $source): Generator
    {
        foreach ($source['years'] as $year => $paths) {
            Contract::year($year, true);
            $races = $entries = 0;
            foreach (JsonlArtifact::read($paths['input']) as $row) {
                self::keys($row, ['year', 'race_id', 'entries']);
                if ($row['year'] !== $year || ! is_int($row['race_id']) || $row['race_id'] < 1 || ! is_array($row['entries'])
                    || count($row['entries']) < 5 || count($row['entries']) > 9) {
                    throw new RuntimeException('Invalid target race.');
                }
                $races++;
                $ids = $bikes = [];
                foreach ($row['entries'] as $entry) {
                    self::keys($entry, ['id', 'bike', 'raw', 'stat01_rank', 'anchor', 'anchor_status', 'signals', 'history', 'history_status']);
                    if (! is_int($entry['id']) || $entry['id'] < 1 || ! is_int($entry['bike']) || $entry['bike'] < 1 || $entry['bike'] > 9
                        || isset($ids[$entry['id']]) || isset($bikes[$entry['bike']])) {
                        throw new RuntimeException('Invalid/duplicate target entry.');
                    }
                    $ids[$entry['id']] = $bikes[$entry['bike']] = true;
                    $entries++;
                    yield ['year' => $year, 'race_id' => $row['race_id'], 'entry_id' => $entry['id'], 'bike' => $entry['bike']];
                }
            }
            if ($races !== $source['counts'][$year] || $entries !== $source['entries'][$year]) {
                throw new RuntimeException('Frozen target count mismatch.');
            }
        }
    }

    public static function keys(array $row, array $allowed): void
    {
        $keys = array_keys($row);
        sort($keys);
        sort($allowed);
        if ($keys !== $allowed) {
            throw new RuntimeException('Unexpected/missing artifact fields.');
        }
    }
}
