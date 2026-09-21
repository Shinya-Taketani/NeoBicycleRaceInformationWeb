<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Audit\Stat35DataReadiness;

use App\Domain\Keirin\Backtest\Experiments\GrowthTrendScoreSource\OuterSource;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use Generator;
use RuntimeException;

class Targets extends OuterSource
{
    public function open(string $root): array
    {
        if (realpath($root) !== $root) {
            throw new RuntimeException('Canonical Outer root required.');
        }
        $files = $years = [];
        $fixed = $this->fixedFiles();
        foreach ([2024, 2025] as $year) {
            $relative = "inputs-v2/inputs-$year.jsonl";
            foreach ([$relative, $relative.'.manifest.json'] as $name) {
                Files::verify($root.'/'.$name, $fixed[$name]);
                $files[$root.'/'.$name] = $fixed[$name];
            }
            $years[$year] = ['input' => $root.'/'.$relative];
        }

        return ['files' => $files, 'years' => $years, 'counts' => [2024 => 25212, 2025 => 24866],
            'entries' => [2024 => 179089, 2025 => 177120]];
    }

    public function identities(array $source): Generator
    {
        // Only identity projection; no predictions, models, outcome manifests or labels opened.
        yield from $this->targets($source);
    }
}
