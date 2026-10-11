<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\BFixedWinnerJointP23;

use App\Domain\Keirin\Backtest\Experiments\C1MarginalP23Decoder\Contract as SharedContract;
use App\Domain\Keirin\Backtest\Experiments\C1MarginalP23Decoder\Sources as SharedSources;
use App\Domain\Keirin\Backtest\Experiments\C1Stat35P1Composition\Contract as ParentContract;
use App\Domain\Keirin\Backtest\Experiments\C1Stat35P1Composition\Sources as ParentSources;
use App\Domain\Keirin\Backtest\Experiments\Stat35C1Diagnostic\Contract as Diagnostic;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use RuntimeException;

final class Sources
{
    public function __construct(private readonly ParentSources $parents, private readonly string $bSha = Contract::B_SHA) {}

    public function open(string $b = Contract::B, string $input = SharedContract::INPUT,
        string $baseline = SharedContract::BASELINE, string $compare = Diagnostic::COMPARE): array
    {
        if ($this->bSha === Contract::B_SHA) {
            if ($b !== Contract::B) {
                throw new RuntimeException('Fixed PR92 run-01 source required.');
            }
        } elseif (! app()->environment('testing') || ! str_starts_with((string) realpath($b), sys_get_temp_dir().'/')) {
            throw new RuntimeException('Synthetic B pins are testing-only.');
        }
        $seal = Files::identity($b.'/manifest.json');
        if ($seal['sha256'] !== $this->bSha) {
            throw new RuntimeException('Unaccepted B manifest.');
        }
        Files::verify($b.'/manifest.json', Files::json($b.'/COMPLETE.json'));
        $manifest = Files::json($b.'/manifest.json');
        Files::same(ParentContract::plan(), $manifest['contract'], 'PR92 contract');
        if ($manifest['status'] !== 'COMPLETED_DEVELOPMENT_COMPARISON_AWAITING_REVIEW'
            || $manifest['source_start_end_unchanged'] !== true || $manifest['code_start_end_unchanged'] !== true || empty($manifest['code'])) {
            throw new RuntimeException('B completion/integrity missing.');
        }
        $source = $this->parents->open($input, $baseline, $compare);
        Files::same($source, $manifest['source'], 'B frozen source lineage');
        $source['seals'][$b.'/manifest.json'] = $seal;
        $source['seals'][$b.'/COMPLETE.json'] = Files::identity($b.'/COMPLETE.json');
        $source['parent_runtime_code'] = $manifest['code'];
        foreach ([2024, 2025] as $year) {
            foreach (['predictions', 'decisions'] as $name) {
                $relative = 'run-01/'.$name.'-'.$year.'.jsonl';
                foreach ([$relative, $relative.'.manifest.json'] as $file) {
                    $source['seals'][$b.'/'.$file] = $manifest['files'][$file] ?? throw new RuntimeException('Missing B child seal.');
                    Files::verify($b.'/'.$file, $source['seals'][$b.'/'.$file]);
                }
                $path = $b.'/'.$relative;
                $child = Files::json($path.'.manifest.json');
                Files::same($source['seals'][$path], ['bytes' => $child['bytes'], 'sha256' => $child['sha256']], 'B sidecar');
                if ($child['rows'] !== $source['expected_rows'][$year]) {
                    throw new RuntimeException('B source count mismatch.');
                }
                $source['paths'][$year]['b_'.$name] = $path;
            }
        }
        if ($this->bSha === Contract::B_SHA) {
            Files::same([25212, 24866, 179089, 177120], [$source['expected_rows'][2024], $source['expected_rows'][2025],
                $source['expected_entries'][2024], $source['expected_entries'][2025]], 'fixed development population');
        }
        SharedSources::verify($source, false);

        return $source;
    }
}
