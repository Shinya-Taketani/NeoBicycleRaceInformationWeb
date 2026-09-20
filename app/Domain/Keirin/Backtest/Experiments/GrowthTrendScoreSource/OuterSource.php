<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\GrowthTrendScoreSource;

use App\Domain\Keirin\Backtest\Experiments\GrowthTrendAnalysis\TemporalAccess;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\JsonlArtifact;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use Generator;
use RuntimeException;

class OuterSource
{
    protected function projectionHash(): string
    {
        return '0e8d516e0ad0349954276e911f4273932ef2c6198136cac6f432a979d9d94074';
    }

    public function openOutcomeFree(string $root): array
    {
        if (realpath($root) !== $root) {
            throw new RuntimeException('Canonical Outer root required.');
        }
        $index = Files::json($root.'/report-export-manifest.json');
        $files = $relativeFiles = $years = [];
        foreach (Contract::COUNTS as $year => $n) {
            foreach (['input' => "inputs-v2/inputs-$year.jsonl", 'prediction' => "run-01/C1-fit-$year/predictions.jsonl"] as $kind => $relative) {
                $years[$year][$kind] = $root.'/'.$relative;
                foreach ([$relative, $relative.'.manifest.json'] as $name) {
                    $relativeFiles[$name] = $files[$root.'/'.$name] = $this->registered($index, $name);
                }
            }
        }
        // Only the eight directly consumed input/prediction files anchor this identity.
        // The enclosing input/run/export manifests can also identify outcome-bearing files.
        $projection = ['run' => 'run-01', 'files' => $relativeFiles];
        $hash = hash('sha256', Files::canonical($projection));
        if (! hash_equals($this->projectionHash(), $hash)) {
            throw new RuntimeException('Outer outcome-free projection mismatch.');
        }
        self::verify($files);

        return ['kind' => 'OUTCOME_FREE_SOURCE_PROJECTION', 'root' => $root, 'projection' => $projection,
            'outcome_free_projection_sha256' => $hash, 'files' => $files, 'years' => $years,
            'counts' => Contract::COUNTS, 'entries' => Contract::ENTRIES];
    }

    public function openOutcomeSource(string $root, int $year, TemporalAccess $access): array
    {
        Contract::year($year, true);
        $access->authorize();
        $registry = $root.'/report-export-manifest.json';
        $registrySeal = Files::identity($registry);
        $index = Files::json($registry);
        $path = $root.'/run-01/labels-'.$year.'.jsonl';
        $files = [];
        foreach (['run-01/labels-'.$year.'.jsonl', 'run-01/labels-'.$year.'.jsonl.manifest.json'] as $relative) {
            $files[$root.'/'.$relative] = $this->registered($index, $relative);
        }
        self::verify($files);
        Files::verify($registry, $registrySeal);
        $access->record($year.'_OUTCOME_SOURCE_RESOLVED');

        return ['path' => $path, 'files' => $files, 'registry' => [$registry => $registrySeal]];
    }

    private function registered(array $index, string $relative): array
    {
        $seal = $index['included'][$relative] ?? $index['omitted'][$relative] ?? null;
        if (! is_array($seal) || ! is_int($seal['bytes'] ?? null) || $seal['bytes'] < 0
            || ! is_string($seal['sha256'] ?? null) || ! preg_match('/\A[a-f0-9]{64}\z/D', $seal['sha256'])) {
            throw new RuntimeException('Unregistered Outer source.');
        }

        return ['bytes' => $seal['bytes'], 'sha256' => $seal['sha256']];
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
