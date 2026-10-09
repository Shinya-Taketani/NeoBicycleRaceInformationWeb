<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\C1Stat35CompositionFinal;

use App\Domain\Keirin\Backtest\Experiments\C1Stat35P1Composition\Contract as Prior;
use App\Domain\Keirin\Backtest\Experiments\Stat35C1Comparison\Contract as C2;
use App\Domain\Keirin\Backtest\Experiments\Stat35C1Comparison\Sources as InputSources;
use App\Domain\Keirin\Backtest\Experiments\Stat35C1Diagnostic\Contract as Diagnostic;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Contract as FinalC1;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\ModelLoader;
use RuntimeException;

final class Sources
{
    public function __construct(private readonly InputSources $inputs, private readonly ModelLoader $c1) {}

    public function open(): array
    {
        $source = $this->inputs->open(C2::INPUT, C2::BASELINE);
        $compare = Diagnostic::COMPARE;
        Files::verify($compare.'/manifest.json', Diagnostic::COMPARE_SEAL);
        Files::same(Diagnostic::COMPARE_SEAL, Files::json($compare.'/COMPLETE.json'), 'C2 parent COMPLETE');
        $manifest = Files::json($compare.'/manifest.json');
        Files::same(C2::plan(), $manifest['contract'], 'C2 format/contract');
        if ($manifest['status'] !== 'COMPLETED_NOT_ADOPTED' || $manifest['runs']['run-01'] !== $manifest['runs']['run-02']) {
            throw new RuntimeException('C2 parent/reproduction invalid.');
        }
        foreach ([$compare.'/manifest.json', $compare.'/COMPLETE.json'] as $path) {
            $source['seals'][$path] = Files::identity($path);
        }
        Files::same($source['paths'], $manifest['source']['paths'], 'C2 parent fixed input provenance');
        foreach (['C2-inner-A', 'C2-inner-B', 'C2-fit-2024', 'C2-fit-2025'] as $directory) {
            foreach ($manifest['runs']['run-01'] as $name => $seal) {
                if (! str_starts_with($name, $directory.'/')) {
                    continue;
                }
                $path = $compare.'/run-01/'.$name;
                Files::verify($path, $seal);
                $source['seals'][$path] = ['bytes' => $seal['bytes'], 'sha256' => $seal['sha256']];
            }
        }
        foreach ([2022, 2023, 2024, 2025] as $year) {
            foreach (['teacher-'.$year.'.jsonl', 'teacher-'.$year.'.jsonl.manifest.json'] as $name) {
                $path = $compare.'/run-01/'.$name;
                $seal = $manifest['runs']['run-01'][$name] ?? throw new RuntimeException('Missing C2 teacher seal.');
                Files::verify($path, $seal);
                $source['seals'][$path] = ['bytes' => $seal['bytes'], 'sha256' => $seal['sha256']];
            }
            $source['paths'][$year]['c2_teacher'] = $compare.'/run-01/teacher-'.$year.'.jsonl';
        }
        $source['reused_folds'] = [2023 => $compare.'/run-01/C2-inner-A', 2024 => $compare.'/run-01/C2-inner-B'];
        $source['outer_c2'] = [2024 => $compare.'/run-01/C2-fit-2024/model.json', 2025 => $compare.'/run-01/C2-fit-2025/model.json'];
        $reference = Contract::REFERENCE;
        $referenceSeal = Files::identity($reference.'/manifest.json');
        if ($referenceSeal['sha256'] !== Contract::REFERENCE_SHA) {
            throw new RuntimeException('PR92 reference manifest mismatch.');
        }
        Files::same($referenceSeal, Files::json($reference.'/COMPLETE.json'), 'PR92 COMPLETE');
        $prior = Files::json($reference.'/manifest.json');
        Files::same(Prior::plan(), $prior['contract'], 'PR92 composition contract');
        if ($prior['status'] !== 'COMPLETED_DEVELOPMENT_COMPARISON_AWAITING_REVIEW') {
            throw new RuntimeException('PR92 comparison was not complete.');
        }
        foreach (['manifest.json', 'COMPLETE.json'] as $name) {
            $source['seals'][$reference.'/'.$name] = Files::identity($reference.'/'.$name);
        }
        foreach (['comparison.json', 'reproduction.json', 'run-01/predictions-2024.jsonl', 'run-01/predictions-2024.jsonl.manifest.json',
            'run-01/predictions-2025.jsonl', 'run-01/predictions-2025.jsonl.manifest.json'] as $name) {
            $seal = $prior['files'][$name] ?? throw new RuntimeException('Missing PR92 result seal.');
            Files::verify($reference.'/'.$name, $seal);
            $source['seals'][$reference.'/'.$name] = $seal;
        }
        if ((Files::json($reference.'/comparison.json')['incremental_gate']['status'] ?? null) !== 'PASS_DEVELOPMENT_INCREMENTAL_EFFECT_ONLY'
            || (Files::json($reference.'/reproduction.json')['identical'] ?? null) !== true) {
            throw new RuntimeException('PR92 Gate/reproduction invalid.');
        }
        foreach ($prior['source']['seals'] as $path => $seal) {
            Files::verify($path, $seal);
            $source['seals'][$path] = $seal;
        }
        $source['reference'] = $reference;
        $source['c1_artifact'] = Contract::C1;
        $c1Root = dirname(Contract::C1);
        $loaded = $this->c1->published(Contract::C1);
        if (Files::identity($c1Root.'/model.json')['sha256'] !== Contract::C1_SHA || $loaded->fit->lambda !== 0.1) {
            throw new RuntimeException('Fixed existing final C1 model mismatch.');
        }
        $fitRoot = dirname($c1Root, 2);
        $result = Files::json($fitRoot.'/result.json');
        Files::same(FinalC1::plan(), $result['contract'], 'existing final C1 generation contract');
        Files::same($result['model'], Files::identity($c1Root.'/model.json'), 'existing final C1 generation model');
        foreach ([2022, 2023, 2024, 2025] as $year) {
            Files::same($result['training_cohorts'][$year], ['races' => $source['expected_rows'][$year], 'entries' => $source['expected_entries'][$year]], 'C1 four-year source cohort');
        }
        if ($result['status'] !== 'FINAL_FIT_REPRODUCED_AWAITING_REVIEW' || $result['start_end_integrity'] !== 'VERIFIED') {
            throw new RuntimeException('Existing C1 final provenance incomplete.');
        }
        foreach (['artifact.json', 'model.json', 'layout.json', 'training.jsonl', 'training.jsonl.manifest.json', 'refit-path.json'] as $name) {
            $path = $c1Root.'/'.$name;
            if ($name !== 'artifact.json') {
                Files::verify($path, $result['semantic_files']['final/'.$name]);
            }
            $source['seals'][$path] = Files::identity($path);
        }
        foreach (['result.json', 'reproducibility.json', 'execution-contract.json', 'parent-start.json'] as $name) {
            $source['seals'][$fitRoot.'/'.$name] = Files::identity($fitRoot.'/'.$name);
        }
        $source['parent_training_code'] = ['C2' => $manifest['code'], 'C1' => $result['code']];
        InputSources::verify($source);

        return $source;
    }
}
