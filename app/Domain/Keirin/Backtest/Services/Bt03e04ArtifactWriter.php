<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Services;

use App\Domain\Keirin\Backtest\Support\Bt03e04DecoderManifestAccumulator;
use App\Domain\Keirin\Backtest\Support\Bt03e04RaceSpool;
use App\Domain\Keirin\Backtest\Support\CanonicalHasher;
use RuntimeException;
use Throwable;

final class Bt03e04ArtifactWriter
{
    public function __construct(
        private readonly Bt03eArtifactFilesystem $filesystem,
        private readonly CanonicalHasher $hasher,
    ) {}

    /** @param array<string,mixed> $summary @param array<int,Bt03e04RaceSpool> $decoders @return array<string,string> */
    public function write(string $directory, array $summary, array $decoders): array
    {
        $root = rtrim($directory, '/') ?: '/';
        $this->filesystem->ensureDirectory($root);
        $name = 'bt03e04-development-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(16));
        $temporary = $root.'/.'.$name.'.tmp';
        $final = $root.'/'.$name;
        if ($this->filesystem->exists($temporary) || $this->filesystem->exists($final)) {
            throw new RuntimeException('BT-03E-04 artifact bundle already existed.');
        }
        $this->filesystem->createDirectory($temporary);
        try {
            $reproducibilityHash = $summary['reproducibility_hash'] ?? null;
            if (! is_string($reproducibilityHash) || preg_match('/\A[a-f0-9]{64}\z/', $reproducibilityHash) !== 1) {
                throw new RuntimeException('BT-03E-04 reproducibility hash was invalid.');
            }
            $resultPath = $temporary.'/result.json';
            $this->filesystem->writeExact($resultPath, $this->json($summary));
            $decoderPath = $temporary.'/decoder_predictions.csv';
            $handle = $this->filesystem->openExclusive($decoderPath);
            try {
                $this->filesystem->writeCsvRow($handle, [
                    'year', 'race_id', 'primary_position_1_bike', 'primary_position_2_bike',
                    'primary_position_3_bike', 'primary_objective_score', 'argmax_p1_bike',
                    'argmax_p1_probability', 'map_ordered_top3', 'map_ordered_probability',
                    'map_top3_set', 'map_top3_set_probability', 'top2_marginal_bikes',
                    'top3_marginal_bikes', 'expected_ndcg_top3', 'primary_tie_count',
                    'primary_technical_tiebreak_used',
                ]);
                if (array_keys($decoders) !== Bt03e04Contract::DEVELOPMENT_YEARS) {
                    throw new RuntimeException('BT-03E-04 artifact required exactly 2024/2025 decoder streams.');
                }
                foreach ($decoders as $year => $spool) {
                    $manifest = new Bt03e04DecoderManifestAccumulator($this->hasher);
                    foreach ($spool->races() as $decision) {
                        $manifest->append($decision);
                        $this->filesystem->writeCsvRow($handle, [
                            $year,
                            $decision['race_id'],
                            $decision['primary_position_1_bike'],
                            $decision['primary_position_2_bike'],
                            $decision['primary_position_3_bike'],
                            sprintf('%.17g', $decision['primary_objective_score']),
                            $decision['argmax_p1_bike'],
                            sprintf('%.17g', $decision['argmax_p1_probability']),
                            implode('-', $decision['map_ordered_top3']),
                            sprintf('%.17g', $decision['map_ordered_probability']),
                            implode('-', $decision['map_top3_set']),
                            sprintf('%.17g', $decision['map_top3_set_probability']),
                            implode('-', $decision['top2_marginal_bikes']),
                            implode('-', $decision['top3_marginal_bikes']),
                            implode('-', $decision['expected_ndcg_top3']),
                            $decision['primary_tie_count'],
                            $decision['primary_technical_tiebreak_used'] ? 1 : 0,
                        ]);
                    }
                    $actual = $manifest->seal();
                    $expected = $summary['decoder_manifests'][$year] ?? null;
                    if (! is_array($expected) || $actual !== $expected) {
                        throw new RuntimeException("BT-03E-04 {$year} decoder semantic manifest mismatched its artifact stream.");
                    }
                }
                $this->filesystem->flushAndSync($handle);
            } finally {
                $this->filesystem->close($handle);
            }
            $files = [];
            foreach (['result.json' => $resultPath, 'decoder_predictions.csv' => $decoderPath] as $name => $path) {
                $hash = hash_file('sha256', $path);
                if ($hash === false) {
                    throw new RuntimeException("BT-03E-04 {$name} hash could not be calculated.");
                }
                $files[] = ['name' => $name, 'bytes' => $this->filesystem->size($path), 'sha256' => $hash];
            }
            $manifest = ['artifact_version' => Bt03e04Contract::ARTIFACT_VERSION, 'files' => $files];
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
            'decoder_predictions_csv' => $final.'/decoder_predictions.csv',
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
