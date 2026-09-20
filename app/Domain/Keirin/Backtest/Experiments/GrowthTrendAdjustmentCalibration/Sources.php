<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\GrowthTrendAdjustmentCalibration;

use App\Domain\Keirin\Backtest\Experiments\GrowthTrendScoreSource\OuterSource;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use RuntimeException;

class Sources
{
    public function __construct(private readonly OuterSource $outer) {}

    protected function modelFiles(): array
    {
        return [2024 => ['bytes' => 80370, 'sha256' => '38a78da4efc01249d3ad579f620c9473e1c231d19dc5eeb2e0b9e5a6bf26c249'],
            2025 => ['bytes' => 81018, 'sha256' => 'f37452a8fe5cef4108f5c0357c1b880222742f7610ea5700fb54caa636bdce43']];
    }

    protected function trendFiles(): array
    {
        return ['trend-input.jsonl' => ['bytes' => 4264956743, 'sha256' => '3817b1a8c981c391c306396c8a4b86967fa8fac849497f9ede6a9cfe02a3eac6'],
            'trend-input.jsonl.manifest.json' => ['bytes' => 130, 'sha256' => '81a4bc3dc0c42a68ac870a961d587a9f5e4a01386f15231238fa45b8197254d1'],
            'trend-input-seal.json' => ['bytes' => 1180, 'sha256' => 'c48116ecfad31dd99d82c1aab5ea0e6f4b351d252b09fe7fe59e105f420d3367'],
            'contract.json' => ['bytes' => 5916, 'sha256' => 'e426e75120f0e901608f2ff8a83ebc7eed9f88364c640e670f833c08384a4f2c']];
    }

    protected function meeting(): array
    {
        return ['root' => '/home/shinya/neo-keirin-artifacts/tactical-meeting-grade-analysis-01-20260918-01/evaluations/outer-c1-meeting-2024-2025-01',
            'files' => ['metadata.jsonl' => ['bytes' => 18138704, 'sha256' => '949e86a4fdd5c509ce890289de96633b48d5c4c589acc184f305e55cdd161b3c'],
                'metadata.jsonl.manifest.json' => ['bytes' => 127, 'sha256' => '7ba87058c083358f7fd5e82d7d8687e682649a1261d1460024413c86475d4374'],
                'meetings.json' => ['bytes' => 767205, 'sha256' => 'e698b563b562ac1a4a8dab039bb5bbdfc05c115199450c4f8f25427924635503']]];
    }

    public function open(string $outerRoot, string $trend): array
    {
        Contract::path($outerRoot);
        Contract::path($trend);
        if (realpath($trend) !== $trend) {
            throw new RuntimeException('Canonical trend bundle required.');
        }
        $outer = $this->outer->openOutcomeFree($outerRoot);
        $files = $outer['files'];
        $years = $outer['years'];
        foreach ($this->modelFiles() as $year => $seal) {
            Contract::year($year);
            $path = $years[$year]['model'] = $outerRoot.'/run-01/C1-fit-'.$year.'/model.json';
            $files[$path] = $seal;
        }
        foreach ($this->trendFiles() as $name => $seal) {
            $files[$trend.'/'.$name] = $seal;
        }
        $meeting = $this->meeting();
        foreach ($meeting['files'] as $name => $seal) {
            $files[$meeting['root'].'/'.$name] = $seal;
        }
        $source = ['kind' => 'OUTCOME_FREE_FIXED_PROJECTION', 'files' => $files, 'years' => $years,
            'outer_root' => $outerRoot, 'trend' => $trend.'/trend-input.jsonl', 'meeting' => $meeting['root'],
            'counts' => $outer['counts'], 'entries' => $outer['entries']];
        $this->verify($source);

        return $source;
    }

    public function verify(array $source): void
    {
        if (array_keys($source['years']) !== Contract::YEARS) {
            throw new RuntimeException('Forbidden source years.');
        }
        foreach (array_keys($source['files']) as $path) {
            Contract::path($path);
        }
        OuterSource::verify($source['files']);
    }

    public function outcome(string $root, int $year, TemporalAccess $access): array
    {
        // Resolve only this year's sidecars. Never open the mixed-year export registry.
        $access->authorize($year, 'OUTCOME_IDENTITY_RESOLVE');
        Contract::path($root);
        $files = $paths = [];
        foreach (['labels' => 'run-01/labels-', 'contributions' => 'comparison-run-01/contributions-'] as $kind => $prefix) {
            $path = $paths[$kind] = $root.'/'.$prefix.$year.'.jsonl';
            $files[$path.'.manifest.json'] = Files::identity($path.'.manifest.json');
            $files[$path] = Files::json($path.'.manifest.json');
        }
        OuterSource::verify($files);

        return ['paths' => $paths, 'files' => $files];
    }
}
