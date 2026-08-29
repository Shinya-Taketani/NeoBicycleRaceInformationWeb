<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Services;

use App\Domain\Keirin\Backtest\Support\Bt03e06RaceSpool;
use App\Domain\Keirin\Backtest\Support\Bt03e07PredictionManifestAccumulator;
use App\Domain\Keirin\Backtest\Support\CanonicalHasher;
use RuntimeException;
use Throwable;

final class Bt03e07ArtifactWriter
{
    public function __construct(
        private readonly Bt03eArtifactFilesystem $filesystem,
        private readonly CanonicalHasher $hasher,
    ) {}

    /** @param array<string,mixed> $summary @param array<int,Bt03e06RaceSpool> $predictions @return array<string,string> */
    public function write(string $directory, array $summary, array $predictions): array
    {
        $root = rtrim($directory, '/') ?: '/';
        $this->filesystem->ensureDirectory($root);
        $name = 'bt03e07-development-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(16));
        $temporary = $root.'/.'.$name.'.tmp';
        $final = $root.'/'.$name;
        if ($this->filesystem->exists($temporary) || $this->filesystem->exists($final)) {
            throw new RuntimeException('BT-03E-07 artifact bundle already existed.');
        }
        $this->filesystem->createDirectory($temporary);
        try {
            $reproducibilityHash = $summary['reproducibility_hash'] ?? null;
            if (! is_string($reproducibilityHash) || preg_match('/\A[a-f0-9]{64}\z/', $reproducibilityHash) !== 1) {
                throw new RuntimeException('BT-03E-07 reproducibility hash was invalid.');
            }
            $resultPath = $temporary.'/result.json';
            $this->filesystem->writeExact($resultPath, $this->json($summary));
            $predictionPath = $temporary.'/predictions.csv';
            $handle = $this->filesystem->openExclusive($predictionPath);
            try {
                $this->filesystem->writeCsvRow($handle, [
                    'year', 'race_id', 'primary_position_1_bike', 'primary_position_2_bike',
                    'primary_position_3_bike', 'source_p1', 'selected_d2', 'selected_d3',
                    'primary_second_third_objective_score', 'direct_p2_distribution_sha256',
                    'direct_p3_distribution_sha256',
                    'map_ordered_top3', 'map_ordered_probability', 'map_top3_set', 'map_top3_set_probability',
                    'top2_marginal_bikes', 'top3_marginal_bikes', 'expected_ndcg_top3',
                    'winner_tie_count', 'second_third_tie_count', 'primary_technical_tiebreak_used',
                    'p1_freeze_verified',
                ]);
                if (array_keys($predictions) !== Bt03e07Contract::OUTER_YEARS) {
                    throw new RuntimeException('BT-03E-07 artifact required exactly 2024/2025 prediction streams.');
                }
                foreach ($predictions as $year => $spool) {
                    $expected = $summary['prediction_manifests'][$year] ?? null;
                    if (! is_array($expected) || ! is_array($expected['source_e03_identity'] ?? null)) {
                        throw new RuntimeException("BT-03E-07 {$year} prediction manifest was unavailable.");
                    }
                    $manifest = new Bt03e07PredictionManifestAccumulator($year, $expected['source_e03_identity'], $this->hasher);
                    foreach ($spool->races() as $decision) {
                        $manifest->append($decision);
                        $this->filesystem->writeCsvRow($handle, [
                            $year,
                            $decision['race_id'],
                            $decision['primary_position_1_bike'],
                            $decision['primary_position_2_bike'],
                            $decision['primary_position_3_bike'],
                            sprintf('%.17g', $decision['source_p1']),
                            sprintf('%.17g', $decision['selected_d2']),
                            sprintf('%.17g', $decision['selected_d3']),
                            sprintf('%.17g', $decision['primary_second_third_objective_score']),
                            $decision['direct_p2_distribution_sha256'],
                            $decision['direct_p3_distribution_sha256'],
                            implode('-', $decision['map_ordered_top3']),
                            sprintf('%.17g', $decision['map_ordered_probability']),
                            implode('-', $decision['map_top3_set']),
                            sprintf('%.17g', $decision['map_top3_set_probability']),
                            implode('-', $decision['top2_marginal_bikes']),
                            implode('-', $decision['top3_marginal_bikes']),
                            implode('-', $decision['expected_ndcg_top3']),
                            $decision['winner_tie_count'],
                            $decision['second_third_tie_count'],
                            $decision['primary_technical_tiebreak_used'] ? 1 : 0,
                            $decision['p1_freeze_verified'] ? 1 : 0,
                        ]);
                    }
                    if ($manifest->seal() !== $expected) {
                        throw new RuntimeException("BT-03E-07 {$year} prediction semantic manifest mismatched its artifact stream.");
                    }
                }
                $this->filesystem->flushAndSync($handle);
            } finally {
                $this->filesystem->close($handle);
            }
            $files = [];
            foreach (['result.json' => $resultPath, 'predictions.csv' => $predictionPath] as $file => $path) {
                $hash = hash_file('sha256', $path);
                if ($hash === false) {
                    throw new RuntimeException("BT-03E-07 {$file} hash could not be calculated.");
                }
                $files[] = ['name' => $file, 'bytes' => $this->filesystem->size($path), 'sha256' => $hash];
            }
            $manifest = ['artifact_version' => Bt03e07Contract::ARTIFACT_VERSION, 'files' => $files];
            $manifest['manifest_sha256'] = $this->hasher->hash($manifest);
            $manifestPath = $temporary.'/manifest.json';
            $this->filesystem->writeExact($manifestPath, $this->json($manifest));
            $this->filesystem->publish($temporary, $final);
        } catch (Throwable $throwable) {
            try {
                $this->filesystem->removeDirectory($temporary);
            } catch (Throwable) {
                // Preserve the primary publication failure.
            }
            throw $throwable;
        }

        return [
            'bundle_directory' => $final,
            'result_json' => $final.'/result.json',
            'predictions_csv' => $final.'/predictions.csv',
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
