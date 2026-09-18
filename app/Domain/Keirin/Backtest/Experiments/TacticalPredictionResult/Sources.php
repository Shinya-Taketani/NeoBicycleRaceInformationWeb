<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\TacticalPredictionResult;

use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\JsonlArtifact;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Backtest\Experiments\TacticalPredictionPipeline\ArtifactStore;
use App\Domain\Keirin\Backtest\Experiments\TacticalPredictionPipeline\ModelIdentity;
use Generator;
use RuntimeException;

class Sources
{
    public function __construct(private readonly ArtifactStore $predictions, private readonly ModelIdentity $models, private readonly Matcher $matcher) {}

    public function capture(string $requests, string $labels, string $labelsManifest): array
    {
        foreach ([$requests, $labels, $labelsManifest] as $path) {
            $this->path($path);
        }
        if ($labelsManifest !== $labels.'.manifest.json') {
            throw new RuntimeException('Specify the original JSONL sidecar manifest.');
        }
        $seals = [$requests => Files::identity($requests)];
        $selection = Files::json($requests);
        if (! in_array($selection['result_year'] ?? null, Contract::plan()['years'], true)
            || ! is_array($selection['targets'] ?? null) || ! array_is_list($selection['targets']) || $selection['targets'] === []) {
            throw new RuntimeException('Invalid selected request universe.');
        }
        $races = $ids = [];
        foreach ($selection['targets'] as $target) {
            Contract::race($target);
            Contract::id($target['request_id']);
            if ($target['year'] !== $selection['result_year'] || isset($races[$target['race_id']]) || isset($ids[$target['request_id']])) {
                throw new RuntimeException('Duplicate or inconsistent selected request.');
            }
            $races[$target['race_id']] = $ids[$target['request_id']] = true;
            $path = $target['bundle_path'];
            $this->path($path);
            $manifest = $this->predictions->verify($path);
            Files::verify($path.'/manifest.json', $target['manifest']);
            $this->models->validate($path.'/artifact.json');
            $request = $manifest['request'];
            if ($request['race_id'] !== $target['race_id'] || $request['request_id'] !== $target['request_id']) {
                throw new RuntimeException('Selected request identity mismatch.');
            }
            Contract::mode($request['mode']);
            Files::verify($path.'/model.json', $request['model']);
            Files::verify($path.'/artifact.json', $request['artifact']);
            $fixed = $this->fixed($target);
            $this->matcher->prediction($fixed['input'], $fixed['prediction']);
            if ($fixed['input']['year'] !== $target['year'] || $fixed['input']['race_id'] !== $target['race_id']) {
                throw new RuntimeException('Fixed input identity differs from selected target.');
            }
            $seals[$path.'/LOCKED.json'] = Files::identity($path.'/LOCKED.json');
            $seals[$path.'/manifest.json'] = $target['manifest'];
            foreach ($manifest['files'] as $file => $seal) {
                $seals[$path.'/'.$file] = $seal;
            }
        }
        foreach ($selection['evidence'] ?? [] as $path => $seal) {
            $this->path($path);
            Files::verify($path, $seal);
            $seals[$path] = $seal;
        }
        // Byte verification does not interpret outcomes. Every prediction is checked first.
        $seals[$labelsManifest] = Files::identity($labelsManifest);
        $manifest = Files::json($labelsManifest);
        if (! is_int($manifest['rows'] ?? null) || $manifest['rows'] < 1) {
            throw new RuntimeException('Invalid labels manifest.');
        }
        Files::verify($labels, $manifest);
        $seals[$labels] = ['bytes' => $manifest['bytes'], 'sha256' => $manifest['sha256']];
        $this->verify($seals);

        return ['selection' => $selection, 'requests_path' => $requests, 'labels_path' => $labels,
            'labels_manifest' => $manifest, 'seals' => $seals];
    }

    public function fixed(array $target): array
    {
        $rows = [];
        foreach (['input', 'prediction'] as $name) {
            $count = 0;
            foreach (JsonlArtifact::read($target['bundle_path'].'/'.$name.'.jsonl') as $row) {
                if (++$count > 1) {
                    throw new RuntimeException('One fixed race per request is required.');
                }
                $rows[$name] = $row;
            }
            if ($count !== 1) {
                throw new RuntimeException('Fixed race is missing.');
            }
        }

        return ['request_id' => $target['request_id']] + $rows;
    }

    public function extract(array $source): Generator
    {
        $targets = array_fill_keys(array_column($source['selection']['targets'], 'race_id'), true);
        $seen = [];
        foreach (JsonlArtifact::read($source['labels_path']) as $row) {
            Contract::race($row);
            if ($row['year'] !== $source['selection']['result_year'] || isset($seen[$row['race_id']])) {
                throw new RuntimeException('Duplicate race or wrong year in labels.');
            }
            $seen[$row['race_id']] = true;
            if (isset($targets[$row['race_id']])) {
                unset($targets[$row['race_id']]);
                yield $this->matcher->result($row);
            }
        }
        if ($targets !== []) {
            throw new RuntimeException('Missing target results: '.implode(',', array_keys($targets)));
        }
    }

    public function verify(array $seals): void
    {
        foreach ($seals as $path => $seal) {
            $this->path($path);
            Files::verify($path, $seal);
        }
    }

    private function path(string $path): void
    {
        if (realpath($path) !== $path || is_link($path)) {
            throw new RuntimeException('Source must be a canonical existing non-symlink path.');
        }
    }
}
