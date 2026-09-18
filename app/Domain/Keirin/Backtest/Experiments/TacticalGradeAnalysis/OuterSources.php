<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\TacticalGradeAnalysis;

use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\JsonlArtifact;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Backtest\Experiments\TacticalPredictionResult\Matcher;
use Generator;
use RuntimeException;

class OuterSources
{
    public function __construct(private readonly Matcher $matcher) {}

    public function open(string $root): array
    {
        if (realpath($root) !== $root || ! is_dir($root)) {
            throw new RuntimeException('Source root must be an existing canonical directory.');
        }
        $registryPath = $root.'/report-export-manifest.json';
        $files = [$registryPath => Files::identity($registryPath)];
        if ($files[$registryPath]['sha256'] !== '4268b801b74b77cfb3a94b64832ff483e9eb5e3221ee8e75ceb467e5a5cf92e6') {
            throw new RuntimeException('Expected reviewed v2 source registry.');
        }
        $registry = Files::json($registryPath);
        $add = function (string $relative) use ($root, $registry, &$files): string {
            $path = $root.'/'.$relative;
            $seal = $registry['included'][$relative] ?? $registry['omitted'][$relative] ?? null;
            if (! is_array($seal) || realpath($path) !== $path) {
                throw new RuntimeException('Unregistered source: '.$relative);
            }
            Files::verify($path, $seal);
            $files[$path] = ['bytes' => $seal['bytes'], 'sha256' => $seal['sha256']];

            return $path;
        };
        $run = Files::json($add('run-01/model-run.json'));
        $completion = Files::json($add('run-01-completion.json'));
        $inputManifest = Files::json($add('inputs-v2/manifest.json'));
        $evaluation = Files::json($add('comparison-run-01/comparisons.json'));
        Files::same($run['outer_paths'], $completion['outer_paths'], 'run completion');
        $this->versions($run);
        $years = $training = [];
        foreach (Contract::YEARS as $year) {
            $reference = $evaluation['outer']['C1-STAT01'][$year] ?? [];
            if (($reference['race_count'] ?? null) !== Contract::COUNTS[$year]) {
                throw new RuntimeException('Reviewed evaluation race count mismatch.');
            }
            foreach (Contract::METRICS as $position => $metric) {
                if ((float) ($reference['denominators'][$metric] ?? -1) !== (float) Contract::DENOMINATORS[$year][$position]) {
                    throw new RuntimeException('Reviewed evaluation denominator mismatch.');
                }
            }
            $paths = [];
            $relativePaths = ['input' => "inputs-v2/inputs-$year.jsonl", 'history' => "inputs-v2/history-$year.jsonl",
                'prediction' => "run-01/C1-fit-$year/predictions.jsonl", 'labels' => "run-01/labels-$year.jsonl",
                'contributions' => "comparison-run-01/contributions-$year.jsonl", 'training' => "run-01/C1-fit-$year/training.jsonl"];
            foreach ($relativePaths as $kind => $relative) {
                $paths[$kind] = $add($relative);
                $add($relative.'.manifest.json');
            }
            if ($paths['prediction'] !== $run['outer_paths'][$year]['C1'] || $paths['labels'] !== $run['outer_paths'][$year]['labels']) {
                throw new RuntimeException('Outer C1 source reference mismatch.');
            }
            Files::same($inputManifest['manifests'][$year]['inputs'], Files::json($paths['input'].'.manifest.json'), 'input manifest');
            $model = Files::json($add("run-01/C1-fit-$year/model.json"));
            $this->versions($model);
            if (($model['experiment'] ?? null) !== 'TACTICAL-HISTORY-01-120D-PRE-MEETING-v2') {
                throw new RuntimeException('Wrong input/model calculation version.');
            }
            $counts = $seen = [];
            foreach (JsonlArtifact::read($paths['training']) as $row) {
                if (! in_array($row['year'] ?? null, range(2022, $year - 1), true) || ! is_int($row['race_id'] ?? null)
                    || isset($seen[$row['race_id']])) {
                    throw new RuntimeException('Invalid or duplicate temporal training race.');
                }
                $seen[$row['race_id']] = true;
                $counts[$row['year']] = ($counts[$row['year']] ?? 0) + 1;
            }
            ksort($counts);
            if (array_keys($counts) !== range(2022, $year - 1)) {
                throw new RuntimeException('Missing training year.');
            }
            unset($seen);
            $training[$year] = $counts;
            $years[$year] = $paths;
        }

        return ['root' => $root, 'files' => $files, 'years' => $years, 'training_counts' => $training,
            'reference' => $evaluation['outer']['C1-STAT01'], 'expected_counts' => Contract::COUNTS];
    }

    public function rows(array $sources): Generator
    {
        $seenRaces = $seenEntries = [];
        foreach ($sources['years'] as $year => $paths) {
            $streams = [];
            foreach (['prediction', 'labels', 'contributions', 'history'] as $kind) {
                $streams[$kind] = JsonlArtifact::read($paths[$kind]);
                $streams[$kind]->rewind();
            }
            $count = 0;
            foreach (JsonlArtifact::read($paths['input']) as $input) {
                Contract::race($input);
                if ($input['year'] !== $year || isset($seenRaces[$input['race_id']])) {
                    throw new RuntimeException('Duplicate or wrong-year race.');
                }
                $seenRaces[$input['race_id']] = true;
                $prediction = $this->current($streams['prediction']);
                $joined = $this->matcher->join(['request_id' => 'outer:'.$input['race_id'], 'input' => $input, 'prediction' => $prediction], $this->current($streams['labels']));
                $saved = $this->current($streams['contributions']);
                if (($saved['race_id'] ?? null) !== $input['race_id']) {
                    throw new RuntimeException('Saved contribution race mismatch.');
                }
                $computed = $this->matcher->comparison($joined)['comparison'];
                $expected = [];
                foreach (Contract::METRICS as $position => $metric) {
                    $expected[$position] = $saved['C1-STAT01']['candidate'][$metric];
                    Files::same($expected[$position], $computed['candidate'][$metric], 'saved position contribution');
                }
                $targets = [];
                $date = null;
                foreach ($input['entries'] as $entry) {
                    if (isset($seenEntries[$entry['id']])) {
                        throw new RuntimeException('Duplicate source entry identity.');
                    }
                    $seenEntries[$entry['id']] = true;
                    $target = $this->current($streams['history'])['target'] ?? [];
                    if (($target['race_id'] ?? null) !== $input['race_id'] || ($target['entry_id'] ?? null) !== $entry['id']
                        || ($target['bike'] ?? null) !== $entry['bike'] || ! is_int($target['player_id'] ?? null)
                        || ! is_string($target['race_date'] ?? null) || ! str_starts_with($target['race_date'], $year.'-')
                        || ($date !== null && $date !== $target['race_date'])) {
                        throw new RuntimeException('Fixed history target identity mismatch.');
                    }
                    $date = $target['race_date'];
                    $targets[] = ['id' => $entry['id'], 'bike' => $entry['bike'], 'player_id' => $target['player_id']];
                    $streams['history']->next();
                }
                $context = ['year' => $year, 'race_id' => $input['race_id'], 'entries' => []];
                foreach ($joined['context']['entries'] as $entry) {
                    $context['entries'][] = array_intersect_key($entry, array_flip(['id', 'bike', 'raw', 'rank', 'status']));
                }
                yield ['context' => $context, 'decision' => $prediction['decision'], 'race_date' => $date, 'targets' => $targets, 'saved_contributions' => $expected];
                foreach (['prediction', 'labels', 'contributions'] as $kind) {
                    $streams[$kind]->next();
                }
                $count++;
            }
            foreach ($streams as $stream) {
                if ($stream->valid()) {
                    throw new RuntimeException('Extra source records outside input universe.');
                }
            }
            if ($count !== $sources['expected_counts'][$year]) {
                throw new RuntimeException('Incomplete Outer universe.');
            }
        }
    }

    public function verify(array $sources): void
    {
        foreach ($sources['files'] as $path => $seal) {
            Files::verify($path, $seal);
        }
    }

    private function current(Generator $rows): array
    {
        if (! $rows->valid()) {
            throw new RuntimeException('Missing source row.');
        }

        return $rows->current();
    }

    private function versions(array $model): void
    {
        if (($model['optimizer_version'] ?? null) !== 'TACTICAL-HISTORY-CONSTRAINED-EUCLIDEAN-FISTA-v2'
            || ($model['model_version'] ?? null) !== 'TACTICAL-HISTORY-SEQUENTIAL-POSITION-v2') {
            throw new RuntimeException('Only corrected C1 solver/model versions are permitted.');
        }
    }
}
