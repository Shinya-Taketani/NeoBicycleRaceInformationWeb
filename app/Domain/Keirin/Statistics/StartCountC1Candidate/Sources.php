<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\StartCountC1Candidate;

use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Statistics\AgariC1Context\Contract as Context;
use App\Domain\Keirin\Statistics\AgariC1Input\Contract as C1;
use RuntimeException;

final class Sources
{
    public const FIXED = [
        'snapshots' => ['path' => '/home/shinya/neo-keirin-artifacts/stat36-start-count-01/run-20261001-nK8sZXFb/build',
            'seal' => ['bytes' => 5297, 'sha256' => 'bcadbe02aecb3eaee510b2646fc38b305db08f11633d3bf397be1e193190386d']],
        'c1' => ['path' => '/home/shinya/neo-keirin-artifacts/stat35-c1-input-02/run-20260930-054458-8739da4b/result',
            'seal' => ['sha256' => '7f4356b93f90203dc727dc95d6215a8d8ce3f44869c4441c3981087ff1099c26']],
        'mapping' => ['path' => '/home/shinya/neo-keirin-artifacts/stat35-c1-context-01/run-20260928-215453-d152edab/mapping',
            'seal' => ['bytes' => 3564, 'sha256' => '5facd83259a5b2b147115ff1632632a6f7a11f96e07f9f4d3078286743eb839b']],
    ];

    public function __construct(private readonly array $pins = self::FIXED) {}

    public function open(): array
    {
        $seals = $manifests = [];
        foreach ($this->pins as $kind => $pin) {
            $path = $pin['path'];
            if (realpath($path) !== $path) {
                throw new RuntimeException('Canonical fixed source directory required.');
            }
            $seal = Files::identity($path.'/manifest.json');
            if ($seal['sha256'] !== $pin['seal']['sha256'] || (isset($pin['seal']['bytes']) && $seal['bytes'] !== $pin['seal']['bytes'])) {
                throw new RuntimeException('Fixed source pin mismatch.');
            }
            Files::same($seal, Files::json($path.'/COMPLETE.json'), 'source COMPLETE');
            $m = Files::json($path.'/manifest.json');
            $names = match ($kind) {
                'snapshots' => $this->snapshotNames($m),
                'c1' => $this->c1Names($m),
                'mapping' => $this->mappingNames($m),
                default => throw new RuntimeException('Unknown source kind.'),
            };
            foreach (['manifest.json', 'COMPLETE.json', ...$names] as $name) {
                $file = $path.'/'.$name;
                $expected = match ($name) {
                    'manifest.json' => $seal,
                    'COMPLETE.json' => Files::identity($file),
                    default => $m['files'][$name] ?? throw new RuntimeException('Missing sealed dependency.'),
                };
                Files::verify($file, $expected);
                $seals[$file] = ['bytes' => $expected['bytes'], 'sha256' => $expected['sha256']];
            }
            $manifests[$kind] = $m;
        }
        $p = Files::json($this->path('mapping', 'provenance.json'));
        $c1 = $manifests['c1'];
        $original = $c1['source']['c1'].'/manifest.json';
        Files::same($p['source']['manifest'], $c1['source']['seals'][$original] ?? [], 'mapping target fixed C1 origin');
        foreach (Contract::YEARS as $year) {
            if ($p['source']['years'][$year]['races'] !== $c1['source']['expected_rows'][$year]
                || $p['source']['years'][$year]['entries'] !== $c1['source']['expected_targets'][$year]
                || $p['source']['years'][$year]['non_result_sha256'] !== $c1['files']['c1-'.$year.'.jsonl']['sha256']) {
                throw new RuntimeException('C1/mapping projection provenance mismatch.');
            }
        }

        return ['pins' => array_map(fn ($p) => Files::identity($p['path'].'/manifest.json'), $this->pins),
            'seals' => $seals, 'manifests' => $manifests];
    }

    public function path(string $kind, string $name): string
    {
        return $this->pins[$kind]['path'].'/'.$name;
    }

    public static function verify(array $source): void
    {
        foreach ($source['seals'] as $path => $seal) {
            Files::verify($path, $seal);
        }
    }

    public function protect(string $output): void
    {
        foreach ($this->pins as $pin) {
            if ($output === $pin['path'] || str_starts_with($output.'/', $pin['path'].'/') || str_starts_with($pin['path'].'/', $output.'/')) {
                throw new RuntimeException('Output overlaps fixed source.');
            }
        }
    }

    private function snapshotNames(array $m): array
    {
        if (($m['version'] ?? null) !== 'STAT36-START-COUNT-v1' || ($m['kind'] ?? null) !== 'SNAPSHOTS') {
            throw new RuntimeException('Unknown S snapshot contract.');
        }
        $names = ['fetch-audit.jsonl', 'coverage.json', 'contract.json'];
        foreach (Contract::YEARS as $year) {
            $names[] = 'snapshots-'.$year.'.jsonl';
            $names[] = 'unresolved-'.$year.'.jsonl';
        }
        if (array_diff(array_keys($m['files'] ?? []), [...$names, 'coverage.csv', 'examples.json', 'verification.json']) !== []) {
            throw new RuntimeException('Unknown snapshot partitions/fields.');
        }

        return $names;
    }

    private function c1Names(array $m): array
    {
        if (($m['contract']['version'] ?? null) !== C1::VERSION || ($m['status'] ?? null) !== 'INPUTS_PREPARED'
            || ($m['contract']['years'] ?? null) !== Contract::YEARS || ($m['contract']['format'] ?? null) !== 'OUTCOME_FREE_C1_PLUS_SEPARATE_SIDECAR'
            || array_keys($m['source']['expected_rows'] ?? []) !== Contract::YEARS
            || array_keys($m['source']['expected_targets'] ?? []) !== Contract::YEARS) {
            throw new RuntimeException('Unknown outcome-free C1 contract.');
        }

        return array_map(fn ($y) => 'c1-'.$y.'.jsonl', Contract::YEARS);
    }

    private function mappingNames(array $m): array
    {
        if (($m['version'] ?? null) !== Context::VERSION || ($m['kind'] ?? null) !== 'MAPPING'
            || ($m['historical_as_of_available'] ?? null) !== false) {
            throw new RuntimeException('Unknown mapping contract.');
        }

        return ['mapping-audit.jsonl', 'provenance.json'];
    }
}
