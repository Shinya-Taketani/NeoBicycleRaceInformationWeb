<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\GrowthAdjustmentCalibration;

use App\Domain\Keirin\Backtest\Experiments\GrowthPointAnalysisV2\Store as GrowthStore;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use RuntimeException;

class Sources
{
    public function __construct(private readonly GrowthStore $growth) {}

    public function open(string $outerRoot, string $growthBundle): array
    {
        if (realpath($outerRoot) !== $outerRoot) {
            throw new RuntimeException('Canonical existing Outer root required.');
        }
        $registryPath = $outerRoot.'/report-export-manifest.json';
        $files = [$registryPath => Files::identity($registryPath)];
        if ($files[$registryPath]['sha256'] !== '4268b801b74b77cfb3a94b64832ff483e9eb5e3221ee8e75ceb467e5a5cf92e6') {
            throw new RuntimeException('Expected reviewed Outer run-01 registry.');
        }
        $registry = Files::json($registryPath);
        $add = function (string $relative) use ($outerRoot, $registry, &$files): string {
            $path = $outerRoot.'/'.$relative;
            $seal = $registry['included'][$relative] ?? $registry['omitted'][$relative] ?? null;
            if (! is_array($seal) || realpath($path) !== $path) {
                throw new RuntimeException('Unregistered Outer source.');
            }
            Files::verify($path, $seal);
            $files[$path] = ['bytes' => $seal['bytes'], 'sha256' => $seal['sha256']];

            return $path;
        };
        $run = Files::json($add('run-01/model-run.json'));
        $completion = Files::json($add('run-01-completion.json'));
        Files::same($run['outer_paths'], $completion['outer_paths'], 'Outer completion');
        $inputs = Files::json($add('inputs-v2/manifest.json'));
        $years = [];
        foreach (Contract::YEARS as $year) {
            $paths = [];
            foreach (['input' => "inputs-v2/inputs-$year.jsonl", 'prediction' => "run-01/C1-fit-$year/predictions.jsonl",
                'labels' => "run-01/labels-$year.jsonl", 'contributions' => "comparison-run-01/contributions-$year.jsonl"] as $kind => $relative) {
                $paths[$kind] = $add($relative);
                $add($relative.'.manifest.json');
            }
            if ($paths['prediction'] !== $run['outer_paths'][$year]['C1'] || $paths['labels'] !== $run['outer_paths'][$year]['labels']) {
                throw new RuntimeException('Not the registered Outer C1.');
            }
            Files::same($inputs['manifests'][$year]['inputs'], Files::json($paths['input'].'.manifest.json'), 'Outer input');
            $paths['model'] = $add("run-01/C1-fit-$year/model.json");
            if (Files::json($paths['model'])['lambda'] !== 0.1) {
                throw new RuntimeException('Frozen Outer lambda mismatch.');
            }
            $years[$year] = $paths;
        }
        $growthManifest = $this->growth->verify($growthBundle);
        if (Files::identity($growthBundle.'/manifest.json')['sha256'] !== 'ead819e7075500930fa6d2a2c5320e7f3ee57f728d3967597434ba6737fb8003') {
            throw new RuntimeException('Expected fixed growth-v2 bundle.');
        }
        foreach ($growthManifest['files'] as $name => $seal) {
            $files[$growthBundle.'/'.$name] = $seal;
        }
        foreach (['manifest.json', 'LOCKED.json'] as $name) {
            $files[$growthBundle.'/'.$name] = Files::identity($growthBundle.'/'.$name);
        }
        $growthSources = Files::json($growthBundle.'/sources.json');
        $cohort = $growthSources['path'].'/cohort.jsonl';
        foreach ([$cohort, $cohort.'.manifest.json'] as $path) {
            $files[$path] = $growthSources['files'][$path] ?? throw new RuntimeException('Missing sealed cohort source.');
        }
        $source = ['files' => $files, 'years' => $years, 'growth' => $growthBundle.'/growth-details-v2.jsonl', 'cohort' => $cohort,
            'counts' => Contract::COUNTS, 'entries' => Contract::ENTRIES, 'missing' => Contract::MISSING, 'same' => Contract::SAME];
        $this->verify($source);

        return $source;
    }

    public function verify(array $source): void
    {
        foreach ($source['files'] as $path => $seal) {
            Files::verify($path, $seal);
        }
    }
}
