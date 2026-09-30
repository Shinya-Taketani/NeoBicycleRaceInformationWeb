<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\Stat35C1Comparison;

use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Contract as FinalContract;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Statistics\AgariC1Input\Contract as InputContract;
use RuntimeException;

final class Sources
{
    public function __construct(
        private readonly string $inputSha = Contract::INPUT_SHA,
        private readonly string $exportSha = Contract::EXPORT_SHA,
        private readonly string $baselineContractSha = Contract::BASELINE_CONTRACT_SHA,
    ) {}

    public function open(string $input, string $baseline): array
    {
        $seals = [];
        foreach ([$input.'/manifest.json' => $this->inputSha, $baseline.'/report-export-manifest.json' => $this->exportSha,
            $baseline.'/frozen-experiment-contract.json' => $this->baselineContractSha] as $path => $sha) {
            $seal = Files::identity($path);
            if ($seal['sha256'] !== $sha) {
                throw new RuntimeException('Unaccepted fixed source: '.$path);
            }
            $seals[$path] = $seal;
        }
        Files::verify($input.'/manifest.json', Files::json($input.'/COMPLETE.json'));
        $seals[$input.'/COMPLETE.json'] = Files::identity($input.'/COMPLETE.json');
        $manifest = Files::json($input.'/manifest.json');
        Files::same(InputContract::plan(), $manifest['contract'], 'fixed input contract');
        if ($manifest['status'] !== 'INPUTS_PREPARED' || empty($manifest['code']) || empty($manifest['source']['seals'])) {
            throw new RuntimeException('Input bundle not prepared or missing provenance.');
        }
        $parent = Files::json($baseline.'/frozen-experiment-contract.json');
        foreach (FinalContract::parentSettings() as $key => $value) {
            Files::same([$value], [$parent[$key] ?? null], 'baseline setting '.$key);
        }
        $export = Files::json($baseline.'/report-export-manifest.json');
        $records = $export['included'] + $export['omitted'];
        $needed = ['inputs-v2/manifest.json', 'frozen-experiment-contract.json'];
        foreach (InputContract::YEARS as $year) {
            $needed[] = 'inputs-v2/inputs-'.$year.'.jsonl';
            $needed[] = 'inputs-v2/inputs-'.$year.'.jsonl.manifest.json';
            foreach (['c1', 'stat35'] as $kind) {
                $name = $kind.'-'.$year.'.jsonl';
                $seals[$input.'/'.$name] = $manifest['files'][$name] ?? throw new RuntimeException('Missing input seal.');
            }
        }
        foreach ([2024, 2025] as $year) {
            foreach (['model.json', 'layout.json', 'selection.json', 'refit-path.json', 'predictions.jsonl', 'predictions.jsonl.manifest.json'] as $name) {
                $needed[] = 'run-01/C1-fit-'.$year.'/'.$name;
            }
            $needed[] = 'run-01/labels-'.$year.'.jsonl';
            $needed[] = 'run-01/labels-'.$year.'.jsonl.manifest.json';
        }
        foreach ($needed as $name) {
            $record = $records[$name] ?? throw new RuntimeException('Missing saved export evidence: '.$name);
            $seals[$baseline.'/'.$name] = ['bytes' => $record['bytes'], 'sha256' => $record['sha256']];
        }
        Files::same($seals[$baseline.'/inputs-v2/manifest.json'], $manifest['source']['seals'][$baseline.'/inputs-v2/manifest.json'] ?? [], 'fixed C1 parent');
        $original = Files::json($baseline.'/inputs-v2/manifest.json');
        if ($original['calculation_version'] !== InputContract::C1_VERSION) {
            throw new RuntimeException('Unsupported baseline input version.');
        }
        $paths = [];
        foreach (InputContract::YEARS as $year) {
            $path = $baseline.'/inputs-v2/inputs-'.$year.'.jsonl';
            Files::same($original['manifests'][$year]['inputs'], Files::json($path.'.manifest.json'), 'original C1 child seal');
            $paths[$year] = ['input' => $input.'/c1-'.$year.'.jsonl', 'sidecar' => $input.'/stat35-'.$year.'.jsonl', 'original' => $path,
                'teacher' => $year < 2024 ? $path : $baseline.'/run-01/labels-'.$year.'.jsonl'];
            if ($year >= 2024) {
                $paths[$year]['baseline'] = $baseline.'/run-01/C1-fit-'.$year.'/predictions.jsonl';
                $paths[$year]['model'] = $baseline.'/run-01/C1-fit-'.$year.'/model.json';
                foreach (['baseline', 'teacher'] as $kind) {
                    $p = $paths[$year][$kind];
                    $seal = Files::json($p.'.manifest.json');
                    Files::same($seals[$p], ['bytes' => $seal['bytes'], 'sha256' => $seal['sha256']], 'baseline stream seal');
                }
            }
        }
        $source = ['seals' => $seals, 'paths' => $paths, 'input_provenance' => ['source' => $manifest['source'], 'code' => $manifest['code']],
            'expected_rows' => $manifest['source']['expected_rows'], 'expected_entries' => $manifest['source']['expected_targets']];
        foreach (['summary.json', 'invariance.json'] as $name) {
            if (isset($manifest['files'][$name])) {
                $source['seals'][$input.'/'.$name] = $manifest['files'][$name];
                Files::verify($input.'/'.$name, $manifest['files'][$name]);
                $source[$name === 'summary.json' ? 'input_summary' : 'input_invariance'] = Files::json($input.'/'.$name);
            } elseif ($this->inputSha === Contract::INPUT_SHA) {
                throw new RuntimeException('Fixed production input lacks summary/invariance seal.');
            }
        }
        self::verify($source);

        return $source;
    }

    public static function verify(array $source): void
    {
        foreach ($source['seals'] as $path => $seal) {
            Files::verify($path, $seal);
        }
    }
}
