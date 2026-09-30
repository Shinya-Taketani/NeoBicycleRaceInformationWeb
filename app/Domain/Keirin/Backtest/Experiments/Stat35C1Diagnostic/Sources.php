<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\Stat35C1Diagnostic;

use App\Domain\Keirin\Backtest\Experiments\Stat35C1Comparison\Contract as Comparison;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Statistics\AgariC1Input\Contract as Input;
use RuntimeException;

final class Sources
{
    public function __construct(private readonly array $compareSeal = Contract::COMPARE_SEAL,
        private readonly string $inputHash = Comparison::INPUT_SHA) {}

    public function open(string $compare, string $input, string $baseline): array
    {
        foreach ([$compare, $input, $baseline] as $root) {
            if (realpath($root) !== $root || ! is_dir($root)) {
                throw new RuntimeException('Source must be a canonical directory.');
            }
        }
        $seals = [];
        $comparison = $this->manifest($compare, $this->compareSeal, $seals);
        $inputSeal = Files::identity($input.'/manifest.json');
        if ($inputSeal['sha256'] !== $this->inputHash) {
            throw new RuntimeException('Unaccepted fixed input manifest.');
        }
        $inputs = $this->manifest($input, $inputSeal, $seals);
        if (($comparison['status'] ?? null) !== 'COMPLETED_NOT_ADOPTED' || ($inputs['status'] ?? null) !== 'INPUTS_PREPARED') {
            throw new RuntimeException('Source completion status is invalid.');
        }
        Files::same(Comparison::plan(), $comparison['contract'] ?? [], 'comparison contract');
        Files::same(Input::plan(), $inputs['contract'] ?? [], 'input contract');
        $paths = $counts = [];
        foreach (Contract::YEARS as $year) {
            $old = $comparison['source']['paths'][$year];
            foreach (['input' => 'c1', 'sidecar' => 'stat35'] as $key => $prefix) {
                $path = $old[$key];
                if ($path !== $input.'/'.$prefix.'-'.$year.'.jsonl') {
                    throw new RuntimeException('Input path is outside the fixed year/source.');
                }
                $seal = $inputs['files'][basename($path)];
                Files::same($seal, $comparison['source']['seals'][$path], 'input/comparison child seal');
                $paths[$year][$key] = $this->child($path, $seal, $seals);
            }
            foreach (['c1_model' => ['model', 'model.json'], 'c1_prediction' => ['baseline', 'predictions.jsonl']] as $key => [$oldKey, $name]) {
                $path = $old[$oldKey];
                if ($path !== $baseline.'/run-01/C1-fit-'.$year.'/'.$name) {
                    throw new RuntimeException('Only the saved run-01 C1 Outer model/prediction is allowed.');
                }
                $paths[$year][$key] = $this->child($path, $comparison['source']['seals'][$path], $seals);
            }
            foreach (['c2_model' => 'C2-fit-'.$year.'/model.json', 'c2_prediction' => 'C2-fit-'.$year.'/predictions.jsonl',
                'teacher' => 'teacher-'.$year.'.jsonl', 'contributions' => 'evaluation/contributions-'.$year.'.jsonl'] as $key => $name) {
                if (! isset($comparison['runs']['run-01'][$name])) {
                    throw new RuntimeException('Missing saved run-01 child: '.$name);
                }
                $paths[$year][$key] = $this->child($compare.'/run-01/'.$name, $comparison['runs']['run-01'][$name], $seals);
            }
            $counts[$year] = ['races' => $comparison['source']['expected_rows'][$year], 'entries' => $comparison['source']['expected_entries'][$year]];
            Files::same(array_values($counts[$year]), [$inputs['source']['expected_rows'][$year], $inputs['source']['expected_targets'][$year]], 'source counts');
        }
        $summary = $this->child($compare.'/comparisons.json', $comparison['comparisons'], $seals);

        return ['paths' => $paths, 'seals' => $seals, 'expected' => $counts, 'comparisons' => $summary,
            'ordered_cohorts' => array_intersect_key($comparison['source']['input_invariance'] ?? [], array_flip(Contract::YEARS))];
    }

    public static function verify(array $source): void
    {
        foreach ($source['seals'] as $path => $seal) {
            Files::verify($path, $seal);
        }
    }

    private function manifest(string $root, array $seal, array &$seals): array
    {
        $this->child($root.'/manifest.json', $seal, $seals);
        Files::same($seal, Files::json($root.'/COMPLETE.json'), 'source COMPLETE');
        $seals[$root.'/COMPLETE.json'] = Files::identity($root.'/COMPLETE.json');

        return Files::json($root.'/manifest.json');
    }

    private function child(string $path, array $seal, array &$seals): string
    {
        Files::verify($path, $seal);
        $seals[$path] = ['bytes' => $seal['bytes'], 'sha256' => $seal['sha256']];

        return $path;
    }
}
