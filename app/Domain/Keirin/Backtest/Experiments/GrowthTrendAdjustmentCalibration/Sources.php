<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\GrowthTrendAdjustmentCalibration;

use App\Domain\Keirin\Backtest\Experiments\GrowthTrendScoreSource\OuterSource;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use RuntimeException;

class Sources
{
    public const OUTCOME_TRUST_ANCHOR = 'LITERAL_REVIEWED_PER_YEAR_SEALS';

    public function __construct(private readonly OuterSource $outer) {}

    protected function outcomeFiles(): array
    {
        return [
            2024 => [
                'labels' => [
                    'relative' => 'run-01/labels-2024.jsonl',
                    'body' => ['rows' => 25212, 'bytes' => 72960991, 'sha256' => 'b297c567bb26aa4cbf5263f488ebc55efdd37634c99e60a9cd29a5842a1ccd29'],
                    'sidecar' => ['bytes' => 127, 'sha256' => 'a0f1aae588edaabf64f3921f348f6e7936bccfbbae16f24b56ca83fc282923d4'],
                ],
                'contributions' => [
                    'relative' => 'comparison-run-01/contributions-2024.jsonl',
                    'body' => ['rows' => 25212, 'bytes' => 174358551, 'sha256' => 'a481bfe1f3acaed22bae09244e0473183f513127cef56db4e43a9fa364313350'],
                    'sidecar' => ['bytes' => 128, 'sha256' => '5b794154cc4dde3e1829c816d804b10896bcad012a2167dc8d9b53c42558c911'],
                ],
            ],
            2025 => [
                'labels' => [
                    'relative' => 'run-01/labels-2025.jsonl',
                    'body' => ['rows' => 24866, 'bytes' => 72086838, 'sha256' => '509cf6e4c823c07f73f11cbae31a6844a376ced6b48b4e09d3f750ea4058beca'],
                    'sidecar' => ['bytes' => 127, 'sha256' => '42cc7619b78344166c80c9ecc1ac0f84b39bb7a018de9b69b9a2914ea4da31ce'],
                ],
                'contributions' => [
                    'relative' => 'comparison-run-01/contributions-2025.jsonl',
                    'body' => ['rows' => 24866, 'bytes' => 171962784, 'sha256' => '71af1a23fed0defa8fee0506680d329ad84926666f74a7794fa711a0efbcc635'],
                    'sidecar' => ['bytes' => 128, 'sha256' => 'c957e329fafb93278db4ed248a093d0242969c6089305a90f0c325593a9f3829'],
                ],
            ],
        ];
    }

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
        // Authorization precedes all runtime outcome access, including sidecar identity.
        $access->authorize($year, 'OUTCOME_IDENTITY_RESOLVE');
        $seals = $this->outcomeFiles()[$year];
        Contract::path($root);
        $files = $paths = [];
        foreach ($seals as $kind => $seal) {
            $path = $paths[$kind] = $root.'/'.$seal['relative'];
            $sidecar = $path.'.manifest.json';
            Files::verify($sidecar, $seal['sidecar']);
            Files::same($seal['body'], Files::json($sidecar), 'reviewed outcome sidecar');
            Files::verify($path, $seal['body']);
            $files[$sidecar] = $seal['sidecar'];
            $files[$path] = $seal['body'];
        }

        return ['paths' => $paths, 'files' => $files, 'trust_anchor_type' => self::OUTCOME_TRUST_ANCHOR,
            'fixed_seals' => $seals];
    }
}
