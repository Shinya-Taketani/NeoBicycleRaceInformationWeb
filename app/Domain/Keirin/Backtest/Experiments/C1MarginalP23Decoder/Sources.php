<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\C1MarginalP23Decoder;

use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Contract as FinalContract;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Statistics\AgariC1Input\Contract as InputContract;
use RuntimeException;

final class Sources
{
    public function __construct(private readonly string $inputSha = Contract::INPUT_SHA,
        private readonly string $exportSha = Contract::EXPORT_SHA, private readonly string $contractSha = Contract::BASELINE_CONTRACT_SHA) {}

    public function open(string $input, string $baseline): array
    {
        if ([$this->inputSha, $this->exportSha, $this->contractSha] === [Contract::INPUT_SHA, Contract::EXPORT_SHA, Contract::BASELINE_CONTRACT_SHA]) {
            if ([$input, $baseline] !== [Contract::INPUT, Contract::BASELINE]) {
                throw new RuntimeException('Fixed experiment paths required.');
            }
        } else {
            foreach ([$input, $baseline] as $path) {
                if (! app()->environment('testing') || ! str_starts_with((string) realpath($path), sys_get_temp_dir().'/')) {
                    throw new RuntimeException('Synthetic pins are testing-only.');
                }
            }
        }
        $seals = $outcomes = $paths = [];
        foreach ([$input.'/manifest.json' => $this->inputSha, $baseline.'/report-export-manifest.json' => $this->exportSha,
            $baseline.'/frozen-experiment-contract.json' => $this->contractSha] as $path => $sha) {
            $seals[$path] = Files::identity($path);
            if ($seals[$path]['sha256'] !== $sha) {
                throw new RuntimeException('Unaccepted fixed source: '.$path);
            }
        }
        Files::verify($input.'/manifest.json', Files::json($input.'/COMPLETE.json'));
        $seals[$input.'/COMPLETE.json'] = Files::identity($input.'/COMPLETE.json');
        $manifest = Files::json($input.'/manifest.json');
        Files::same(InputContract::plan(), $manifest['contract'], 'fixed input contract');
        if ($manifest['status'] !== 'INPUTS_PREPARED' || empty($manifest['code']) || empty($manifest['source']['seals'])) {
            throw new RuntimeException('Incomplete input provenance.');
        }
        $parent = Files::json($baseline.'/frozen-experiment-contract.json');
        foreach (FinalContract::parentSettings() as $key => $value) {
            Files::same([$value], [$parent[$key] ?? null], 'baseline setting '.$key);
        }
        $export = Files::json($baseline.'/report-export-manifest.json');
        $records = $export['included'] + $export['omitted'];
        $originalPath = $baseline.'/inputs-v2/manifest.json';
        $seals[$originalPath] = self::record($records, 'inputs-v2/manifest.json');
        Files::verify($originalPath, $seals[$originalPath]);
        Files::same($seals[$originalPath], $manifest['source']['seals'][$originalPath] ?? [], 'fixed C1 parent');
        $original = Files::json($originalPath);
        if ($original['calculation_version'] !== InputContract::C1_VERSION) {
            throw new RuntimeException('Unsupported original C1 version.');
        }
        foreach ([2024, 2025] as $year) {
            $paths[$year] = ['input' => $input.'/c1-'.$year.'.jsonl', 'prediction' => $baseline.'/run-01/C1-fit-'.$year.'/predictions.jsonl',
                'labels' => $baseline.'/run-01/labels-'.$year.'.jsonl', 'model' => $baseline.'/run-01/C1-fit-'.$year.'/model.json'];
            $seals[$paths[$year]['input']] = $manifest['files']['c1-'.$year.'.jsonl'] ?? throw new RuntimeException('Missing C1 seal.');
            foreach (['model.json', 'layout.json', 'selection.json', 'refit-path.json', 'predictions.jsonl', 'predictions.jsonl.manifest.json'] as $name) {
                $name = 'run-01/C1-fit-'.$year.'/'.$name;
                $seals[$baseline.'/'.$name] = self::record($records, $name);
            }
            $labels = $paths[$year]['labels'];
            $outcomes[$labels] = self::record($records, 'run-01/labels-'.$year.'.jsonl');
            $seals[$labels.'.manifest.json'] = self::record($records, 'run-01/labels-'.$year.'.jsonl.manifest.json');
            foreach (['prediction', 'labels'] as $kind) {
                $path = $paths[$year][$kind];
                Files::verify($path.'.manifest.json', $seals[$path.'.manifest.json']);
                $child = Files::json($path.'.manifest.json');
                Files::same($seals[$path] ?? $outcomes[$path], ['bytes' => $child['bytes'], 'sha256' => $child['sha256']], 'stream seal');
                if ($child['rows'] !== $manifest['source']['expected_rows'][$year]) {
                    throw new RuntimeException('Prediction/label row count disagreed.');
                }
            }
            $dir = dirname($paths[$year]['model']);
            foreach (['model.json', 'layout.json', 'selection.json', 'refit-path.json'] as $name) {
                Files::verify($dir.'/'.$name, $seals[$dir.'/'.$name]);
            }
            $model = Files::json($paths[$year]['model']);
            Files::same($model['layout'], Files::json($dir.'/layout.json'), 'saved layout');
            if ($model['model_version'] !== Contract::plan()['model_version'] || $model['lambda'] !== Files::json($dir.'/selection.json')['lambda']) {
                throw new RuntimeException('Saved model identity/selection disagreed.');
            }
            foreach (['POSITION_1', 'POSITION_2', 'POSITION_3'] as $position) {
                if (($model['optimizer_diagnostics'][$position]['status'] ?? null) !== 'CONVERGED') {
                    throw new RuntimeException('Saved C1 model was not converged.');
                }
            }
        }
        $source = ['seals' => $seals, 'outcome_seals' => $outcomes, 'paths' => $paths,
            'expected_rows' => array_intersect_key($manifest['source']['expected_rows'], array_flip([2024, 2025])),
            'expected_entries' => array_intersect_key($manifest['source']['expected_targets'], array_flip([2024, 2025]))];
        self::verify($source, false);

        return $source;
    }

    public static function verify(array $source, bool $includeOutcomes): void
    {
        foreach ($source['seals'] + ($includeOutcomes ? $source['outcome_seals'] : []) as $path => $seal) {
            Files::verify($path, $seal);
        }
    }

    private static function record(array $records, string $name): array
    {
        $row = $records[$name] ?? throw new RuntimeException('Missing export evidence: '.$name);

        return ['bytes' => $row['bytes'], 'sha256' => $row['sha256']];
    }
}
