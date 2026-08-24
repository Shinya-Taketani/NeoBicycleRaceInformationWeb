<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Services;

use App\Domain\Keirin\Backtest\Support\Bt03e02RaceSpool;
use App\Domain\Keirin\Backtest\Support\CanonicalHasher;
use RuntimeException;
use Throwable;

final class Bt03e03ArtifactWriter
{
    public function __construct(
        private readonly Bt03eArtifactFilesystem $filesystem,
        private readonly CanonicalHasher $hasher,
    ) {}

    /**
     * @param  array<string,mixed>  $summary
     * @param  array<int,Bt03e02RaceSpool>  $predictions
     * @return array<string,string>
     */
    public function write(string $directory, array $summary, array $predictions): array
    {
        $root = rtrim($directory, '/') ?: '/';
        $this->filesystem->ensureDirectory($root);
        $name = 'bt03e03-development-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(16));
        $temporary = $root.'/.'.$name.'.tmp';
        $final = $root.'/'.$name;
        if ($this->filesystem->exists($temporary) || $this->filesystem->exists($final)) {
            throw new RuntimeException('BT-03E-03 artifact bundle already existed.');
        }
        $this->filesystem->createDirectory($temporary);
        try {
            $reproducibilityHash = $summary['reproducibility_hash'] ?? null;
            if (! is_string($reproducibilityHash) || preg_match('/\A[a-f0-9]{64}\z/', $reproducibilityHash) !== 1) {
                throw new RuntimeException('BT-03E-03 reproducibility hash was invalid.');
            }
            $resultJson = $this->json($summary);
            $resultPath = $temporary.'/result.json';
            $this->filesystem->writeExact($resultPath, $resultJson);
            $probabilityPath = $temporary.'/probabilities.csv';
            $handle = $this->filesystem->openExclusive($probabilityPath);
            try {
                $this->filesystem->writeCsvRow($handle, [
                    'year', 'race_id', 'bike_number', 'position_1_probability', 'position_2_probability',
                    'position_3_probability', 'top2_probability', 'top3_probability', 'predicted_position', 'is_map_top3',
                ]);
                ksort($predictions, SORT_NUMERIC);
                foreach ($predictions as $year => $spool) {
                    foreach ($spool->races() as $race) {
                        foreach ($race['entries'] as $entry) {
                            $this->filesystem->writeCsvRow($handle, [
                                $year,
                                $race['race_id'],
                                $entry['bike'],
                                sprintf('%.17g', $entry['position_1_probability']),
                                sprintf('%.17g', $entry['position_2_probability']),
                                sprintf('%.17g', $entry['position_3_probability']),
                                sprintf('%.17g', $entry['top2_probability']),
                                sprintf('%.17g', $entry['top3_probability']),
                                $entry['predicted_position'],
                                $entry['is_map_top3'] ? 1 : 0,
                            ]);
                        }
                    }
                }
                $this->filesystem->flushAndSync($handle);
            } finally {
                $this->filesystem->close($handle);
            }
            $mapPath = $temporary.'/map_predictions.csv';
            $handle = $this->filesystem->openExclusive($mapPath);
            try {
                $this->filesystem->writeCsvRow($handle, [
                    'year', 'race_id', 'map_ordered_top3', 'map_ordered_probability',
                    'map_top3_set', 'map_top3_set_probability',
                ]);
                foreach ($predictions as $year => $spool) {
                    foreach ($spool->races() as $race) {
                        $entry = $race['entries'][0] ?? null;
                        if (! is_array($entry)) {
                            throw new RuntimeException('BT-03E-03 MAP artifact race was empty.');
                        }
                        $this->filesystem->writeCsvRow($handle, [
                            $year,
                            $race['race_id'],
                            implode('-', $entry['map_ordered_top3']),
                            sprintf('%.17g', $entry['map_ordered_probability']),
                            implode('-', $entry['map_top3_set']),
                            sprintf('%.17g', $entry['map_top3_set_probability']),
                        ]);
                    }
                }
                $this->filesystem->flushAndSync($handle);
            } finally {
                $this->filesystem->close($handle);
            }
            $files = [];
            foreach (['result.json' => $resultPath, 'probabilities.csv' => $probabilityPath, 'map_predictions.csv' => $mapPath] as $file => $path) {
                $hash = hash_file('sha256', $path);
                if ($hash === false) {
                    throw new RuntimeException("BT-03E-03 {$file} hash could not be calculated.");
                }
                $files[] = ['name' => $file, 'bytes' => $this->filesystem->size($path), 'sha256' => $hash];
            }
            $manifest = ['artifact_version' => Bt03e03Contract::ARTIFACT_VERSION, 'files' => $files];
            $manifest['manifest_sha256'] = $this->hasher->hash($manifest);
            $manifestPath = $temporary.'/manifest.json';
            $this->filesystem->writeExact($manifestPath, $this->json($manifest));
            $this->filesystem->publish($temporary, $final);
        } catch (Throwable $throwable) {
            try {
                $this->filesystem->removeDirectory($temporary);
            } catch (Throwable) {
                // Preserve the publication failure.
            }
            throw $throwable;
        }

        return [
            'bundle_directory' => $final,
            'result_json' => $final.'/result.json',
            'probabilities_csv' => $final.'/probabilities.csv',
            'map_predictions_csv' => $final.'/map_predictions.csv',
            'manifest_json' => $final.'/manifest.json',
            'reproducibility_hash' => $reproducibilityHash,
            'manifest_sha256' => $manifest['manifest_sha256'],
        ];
    }

    /** @param array<string,mixed> $value */
    private function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)."\n";
    }
}
