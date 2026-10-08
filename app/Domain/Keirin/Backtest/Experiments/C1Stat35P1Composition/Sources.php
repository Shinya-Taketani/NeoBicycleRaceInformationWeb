<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\C1Stat35P1Composition;

use App\Domain\Keirin\Backtest\Experiments\C1MarginalP23Decoder\Sources as BaselineSources;
use App\Domain\Keirin\Backtest\Experiments\Stat35C1Comparison\Contract as Comparison;
use App\Domain\Keirin\Backtest\Experiments\Stat35C1Diagnostic\Contract as Diagnostic;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use RuntimeException;

final class Sources
{
    public function __construct(private readonly BaselineSources $baseline, private readonly array $compareSeal = Diagnostic::COMPARE_SEAL) {}

    public function open(string $input, string $baseline, string $compare): array
    {
        if ($this->compareSeal === Diagnostic::COMPARE_SEAL) {
            if ($compare !== Diagnostic::COMPARE) {
                throw new RuntimeException('Fixed C2 comparison path required.');
            }
        } elseif (! app()->environment('testing') || ! str_starts_with((string) realpath($compare), sys_get_temp_dir().'/')) {
            throw new RuntimeException('Synthetic C2 pins are testing-only.');
        }
        $source = $this->baseline->open($input, $baseline);
        Files::verify($compare.'/manifest.json', $this->compareSeal);
        Files::same($this->compareSeal, Files::json($compare.'/COMPLETE.json'), 'C2 COMPLETE');
        $source['seals'][$compare.'/manifest.json'] = $this->compareSeal;
        $source['seals'][$compare.'/COMPLETE.json'] = Files::identity($compare.'/COMPLETE.json');
        $manifest = Files::json($compare.'/manifest.json');
        Files::same(Comparison::plan(), $manifest['contract'] ?? [], 'saved C2 contract');
        if (($manifest['status'] ?? null) !== 'COMPLETED_NOT_ADOPTED' || empty($manifest['code'])) {
            throw new RuntimeException('Invalid C2 completion/provenance.');
        }
        $source['parent_training_code'] = ['C2' => $manifest['code']];
        foreach ([2024, 2025] as $year) {
            $paths = $source['paths'][$year];
            foreach (['input' => 'input', 'baseline' => 'prediction', 'model' => 'model', 'teacher' => 'labels'] as $old => $new) {
                if (($manifest['source']['paths'][$year][$old] ?? null) !== $paths[$new]) {
                    throw new RuntimeException('C2 source does not refer to the fixed C1 baseline/input.');
                }
                Files::same($source['seals'][$paths[$new]] ?? $source['outcome_seals'][$paths[$new]],
                    $manifest['source']['seals'][$paths[$new]] ?? [], 'C2 baseline child');
            }
            Files::same([$source['expected_rows'][$year], $source['expected_entries'][$year]],
                [$manifest['source']['expected_rows'][$year], $manifest['source']['expected_entries'][$year]], 'C2 source counts');
            $dir = $compare.'/run-01/C2-fit-'.$year;
            foreach (['model.json', 'layout.json', 'selection.json', 'refit-path.json', 'sealed.json', 'predictions.jsonl', 'predictions.jsonl.manifest.json'] as $name) {
                $seal = $manifest['runs']['run-01']['C2-fit-'.$year.'/'.$name] ?? throw new RuntimeException('Missing saved C2 child seal.');
                Files::verify($dir.'/'.$name, $seal);
                $source['seals'][$dir.'/'.$name] = ['bytes' => $seal['bytes'], 'sha256' => $seal['sha256']];
            }
            $model = Files::json($dir.'/model.json');
            Files::same($model['layout'], Files::json($dir.'/layout.json'), 'C2 layout');
            if (($model['model_version'] ?? null) !== Comparison::MODEL_VERSION || ($model['experiment'] ?? null) !== Comparison::VERSION
                || $model['lambda'] !== Files::json($dir.'/selection.json')['lambda']) {
                throw new RuntimeException('Invalid C2 model identity/selection.');
            }
            foreach (['POSITION_1', 'POSITION_2', 'POSITION_3'] as $position) {
                if (($model['optimizer_diagnostics'][$position]['status'] ?? null) !== 'CONVERGED') {
                    throw new RuntimeException('Saved C2 model was not converged.');
                }
            }
            $sealed = Files::json($dir.'/sealed.json');
            foreach (['model' => 'model.json', 'predictions' => 'predictions.jsonl'] as $key => $name) {
                Files::same($source['seals'][$dir.'/'.$name], $sealed[$key], 'C2 publication seal');
            }
            $child = Files::json($dir.'/predictions.jsonl.manifest.json');
            Files::same($source['seals'][$dir.'/predictions.jsonl'], ['bytes' => $child['bytes'], 'sha256' => $child['sha256']], 'C2 prediction sidecar');
            if ($child['rows'] !== $source['expected_rows'][$year]) {
                throw new RuntimeException('C2 prediction count mismatch.');
            }
            $source['paths'][$year]['c2_prediction'] = $dir.'/predictions.jsonl';
            $source['paths'][$year]['c2_model'] = $dir.'/model.json';
        }
        BaselineSources::verify($source, false);

        return $source;
    }
}
