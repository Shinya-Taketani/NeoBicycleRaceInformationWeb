<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal;

use App\Domain\Keirin\Backtest\Calculators\EffectBinBuilder;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\Dataset;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\JsonlArtifact;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\LayoutBuilder;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\Objective;
use App\Domain\Keirin\Backtest\Services\Bt03e03Contract;
use App\Domain\Keirin\Backtest\Support\Bt03e03ValidationLossSpool;
use RuntimeException;

final class ReuseBundle
{
    public function __construct(private readonly Dataset $dataset, private readonly LayoutBuilder $layouts,
        private readonly EffectBinBuilder $bins, private readonly ModelLoader $loader, private readonly Objective $objective) {}

    public function verify(string $root): array
    {
        $export = Files::json($root.'/report-export-manifest.json');
        $inventory = [...$export['included'], ...$export['omitted']];
        $required = ['input-run-result.json', 'inputs-v2/manifest.json', 'inputs-v2/history-cache.sqlite', 'inputs-v2/history-entry-index.json',
            'frozen-experiment-contract.json', 'source-before-fit.json', 'source-after-run.json', 'comparisons.json',
            'reproducibility.json', 'evaluation-reproducibility.json', 'run-01-completion.json', 'run-02-completion.json'];
        foreach ([2022, 2023, 2024, 2025] as $year) {
            foreach (['inputs', 'history'] as $type) {
                $required[] = "inputs-v2/{$type}-{$year}.jsonl";
                $required[] = "inputs-v2/{$type}-{$year}.jsonl.manifest.json";
            }
        }
        $seals = ['report-export-manifest.json' => Files::identity($root.'/report-export-manifest.json')];
        foreach ($required as $name) {
            $seals[$name] = $this->verifyListed($root, $name, $inventory);
        }
        $repro = Files::json($root.'/reproducibility.json');
        if (($repro['status'] ?? null) !== 'VERIFIED_TWO_REAL_FITS' || $repro['run_01_files'] !== $repro['run_02_files']) {
            throw new RuntimeException('Parent fit reproducibility was not verified.');
        }
        foreach (['run-01', 'run-02'] as $run) {
            foreach ($repro['run_01_files'] as $name => $seal) {
                $path = $run.'/'.$name;
                $seals[$path] = $this->verifyListed($root, $path, $inventory);
                Files::verify($root.'/'.$path, $seal);
            }
        }
        $comparison = Files::json($root.'/comparisons.json');
        if (($comparison['status'] ?? null) !== 'EVALUATED_AND_REPRODUCED'
            || ($comparison['incremental_gate']['status'] ?? null) !== 'PASS_DEVELOPMENT_INCREMENTAL_EFFECT_ONLY'
            || ($comparison['stat01_gate']['status'] ?? null) !== 'PASS / GO_TO_FREEZE'
            || (Files::json($root.'/evaluation-reproducibility.json')['status'] ?? null) !== 'VERIFIED') {
            throw new RuntimeException('Parent development gate/evaluation evidence was invalid.');
        }
        $frozen = Files::json($root.'/frozen-experiment-contract.json');
        foreach (Contract::parentSettings() as $key => $expected) {
            Files::same([$expected], [$frozen[$key] ?? null], 'parent frozen contract '.$key);
        }
        if (($frozen['code_files'] ?? []) === []) {
            throw new RuntimeException('Parent execution code evidence was missing.');
        }
        foreach ($frozen['code_files'] as $path => $hash) {
            if (! is_string($path) || str_contains($path, '..') || ! str_starts_with($path, 'app/')
                || hash_file('sha256', base_path($path)) !== $hash) {
                throw new RuntimeException('Parent calculation code changed: '.$path);
            }
        }
        $input = Files::json($root.'/input-run-result.json');
        Files::same($input['inputs'], Files::json($root.'/inputs-v2/manifest.json'), 'parent input manifest');
        foreach (['source-before-fit', 'source-after-run'] as $phase) {
            $check = Files::json($root.'/'.$phase.'.json');
            if (($check['status'] ?? null) !== 'VERIFIED_CURRENT_STATE_AGAINST_INPUT_SNAPSHOT') {
                throw new RuntimeException('Parent source verification did not succeed.');
            }
            Files::same($input['source_start'], $check['features'], 'parent fixed STATs');
        }
        foreach ([2022, 2023, 2024, 2025] as $year) {
            foreach (['inputs', 'history'] as $type) {
                $path = $root."/inputs-v2/{$type}-{$year}.jsonl";
                Files::same($input['inputs']['manifests'][$year][$type], Files::json($path.'.manifest.json'), 'year input seal');
                Files::verify($path, $input['inputs']['manifests'][$year][$type]);
            }
            $labelled = $year <= 2023 ? $root.'/inputs-v2/inputs-'.$year.'.jsonl' : $root.'/run-01/labels-'.$year.'.jsonl';
            $prediction = $year <= 2023 ? null : JsonlArtifact::read($root.'/inputs-v2/inputs-'.$year.'.jsonl');
            $prediction?->rewind();
            $count = $entries = 0;
            foreach (JsonlArtifact::read($labelled) as $race) {
                if ($race['year'] !== $year) {
                    throw new RuntimeException('Sealed training year mismatch.');
                }
                $unlabelled = $race;
                foreach ($unlabelled['entries'] as &$entry) {
                    if (! array_key_exists('rank', $entry) || ! array_key_exists('status', $entry)) {
                        throw new RuntimeException('Frozen label fields were missing.');
                    }
                    unset($entry['rank'], $entry['status']);
                }
                unset($entry);
                if ($prediction !== null) {
                    if (! $prediction->valid()) {
                        throw new RuntimeException('Label universe exceeded predictions.');
                    }
                    Files::same($prediction->current(), $unlabelled, 'label/prediction universe');
                    $prediction->next();
                }
                $count++;
                $entries += count($race['entries']);
            }
            if (($prediction !== null && $prediction->valid()) || $count !== $input['inputs']['manifests'][$year]['inputs']['rows']) {
                throw new RuntimeException('Sealed cohort count mismatch.');
            }
            $cohorts[$year] = ['races' => $count, 'entries' => $entries];
        }

        return ['root' => $root, 'reference_run' => 'run-01', 'run_02_role' => 'REPRODUCIBILITY_EVIDENCE_ONLY',
            'files' => $seals, 'cohorts' => $cohorts, 'source_start' => $input['source_start'],
            'gates' => ['incremental' => $comparison['incremental_gate'], 'stat01' => $comparison['stat01_gate']]];
    }

    public function unchanged(array $evidence): void
    {
        foreach ($evidence['files'] as $path => $seal) {
            Files::verify($evidence['root'].'/'.$path, $seal);
        }
    }

    public function restoreFold(string $parent, array $training, string $validation, string $directory): Bt03e03ValidationLossSpool
    {
        $path = Files::json($parent.'/path.json');
        Files::same(Bt03e03Contract::FIT_EXECUTION_ORDER, $path['fit_order'], 'reused full lambda path');
        $canonical = array_map(Bt03e03ValidationLossSpool::lambdaKey(...), Bt03e03Contract::LAMBDA_GRID);
        if (array_map('strval', array_keys($path['candidate_statuses'])) !== $canonical) {
            throw new RuntimeException('Reused path did not cover all fixed candidates.');
        }
        $raw = fn () => $this->dataset->raw($training, true);
        $layout = $this->layouts->build($raw, true);
        Files::same(Files::json($parent.'/layout.json')['bins'], $layout->canonicalBins(), 'reused training-local bins');
        $manifest = JsonlArtifact::write($directory.'/training-verification.jsonl', $this->dataset->binned($raw, $layout, $this->bins));
        Files::same(Files::json($parent.'/training.jsonl.manifest.json'), $manifest, 'reused training dates/cohort/support');
        $models = [];
        if (array_diff(array_keys($path['models']), array_keys($path['candidate_statuses'])) !== []) {
            throw new RuntimeException('Unknown reused candidate model.');
        }
        foreach ($path['candidate_statuses'] as $key => $status) {
            $present = isset($path['models'][$key]);
            if (($status['status'] === 'CONVERGED') !== $present || ! in_array($status['status'], ['CONVERGED', 'NUMERICALLY_NON_CONVERGED'], true)) {
                throw new RuntimeException('Reused candidate eligibility was invalid.');
            }
            if ($present) {
                $models[$key] = $this->loader->restore($path['models'][$key]);
                Files::same(Files::json($parent.'/layout.json'), $models[$key]->artifact['layout'], 'reused model layout');
                Files::same($layout->supportWeights(), $models[$key]->layout->supportWeights(), 'reused support');
                Files::same($status['positions'], $models[$key]->fit->diagnostics, 'reused convergence diagnostics');
                if ($models[$key]->fit->lambda !== (float) $key) {
                    throw new RuntimeException('Reused model lambda disagreed.');
                }
            }
        }
        $spool = new Bt03e03ValidationLossSpool($directory.'/restored-losses.bin', array_keys($models));
        $saved = JsonlArtifact::read($parent.'/validation-losses.jsonl');
        $saved->rewind();
        $validationRaw = fn () => $this->dataset->raw([$validation], true);
        foreach ($this->dataset->binned($validationRaw, $layout, $this->bins) as $race) {
            $values = [];
            foreach ($models as $key => $model) {
                foreach (Bt03e03Contract::POSITIONS as $position) {
                    $values[$key][$position] = $this->objective->raceLoss($race, $layout, $model->fit->coefficients[$position], $position);
                }
            }
            if (! $saved->valid()) {
                throw new RuntimeException('Reused validation losses were incomplete.');
            }
            Files::same(['race_id' => $race['race_id'], 'losses' => $values], $saved->current(), 'reused validation loss values/order');
            $spool->append($saved->current()['losses']);
            $saved->next();
        }
        if ($saved->valid()) {
            throw new RuntimeException('Reused losses exceeded validation universe.');
        }
        $spool->seal();
        JsonlArtifact::json($directory.'/reuse.json', ['status' => 'VERIFIED_WITHOUT_RETRAINING', 'parent' => $parent,
            'training_files' => array_map(Files::identity(...), $training), 'validation_file' => Files::identity($validation),
            'validation_losses' => Files::identity($parent.'/validation-losses.jsonl'), 'race_count' => $spool->raceCount(),
            'available_lambda_keys' => $spool->availableLambdaKeys()]);

        return $spool;
    }

    private function verifyListed(string $root, string $name, array $inventory): array
    {
        if (str_contains($name, '..') || str_starts_with($name, '/') || ! isset($inventory[$name])) {
            throw new RuntimeException('Required artifact was missing from parent manifest: '.$name);
        }
        Files::verify($root.'/'.$name, $inventory[$name]);

        return ['bytes' => $inventory[$name]['bytes'], 'sha256' => $inventory[$name]['sha256']];
    }
}
