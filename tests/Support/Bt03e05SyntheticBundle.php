<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Keirin\Backtest\Services\Bt03e03ReproducibilityVerifier;
use App\Domain\Keirin\Backtest\Services\Bt03e05Contract;
use App\Domain\Keirin\Backtest\Support\CanonicalHasher;

final class Bt03e05SyntheticBundle
{
    private CanonicalHasher $hasher;

    public function __construct(
        public readonly string $directory,
        private readonly int $racesPerYear = 1,
        private readonly int $entrantCount = 5,
    ) {
        $this->hasher = new CanonicalHasher;
        mkdir($directory, 0775, true);
        $this->writeCsv();
        $this->writeResult($this->result());
        $this->sealManifest();
    }

    /** @param callable(array<string,mixed>):array<string,mixed> $mutator */
    public function mutateResult(callable $mutator, bool $rehash = true): void
    {
        $result = $mutator($this->readResult());
        if ($rehash) {
            $hash = (new Bt03e03ReproducibilityVerifier($this->hasher))->hash($result);
            $result['reproducibility_hash'] = $hash;
            $result['reproducibility_verification']['previous_hash'] = $hash;
            $result['reproducibility_verification']['current_hash'] = $hash;
        }
        $this->writeResult($result);
        $this->sealManifest();
    }

    public function sealManifest(string $artifactVersion = Bt03e05Contract::SOURCE_ARTIFACT_VERSION): void
    {
        $files = [];
        foreach (['result.json', 'probabilities.csv', 'map_predictions.csv'] as $name) {
            $path = $this->directory.'/'.$name;
            $files[] = [
                'name' => $name,
                'bytes' => filesize($path),
                'sha256' => hash_file('sha256', $path),
            ];
        }
        $manifest = ['artifact_version' => $artifactVersion, 'files' => $files];
        $manifest['manifest_sha256'] = $this->hasher->hash($manifest);
        file_put_contents($this->directory.'/manifest.json', $this->json($manifest));
    }

    /** @return array<string,mixed> */
    public function readResult(): array
    {
        return json_decode((string) file_get_contents($this->directory.'/result.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    public function cleanup(): void
    {
        if (! is_dir($this->directory)) {
            return;
        }
        foreach (array_diff(scandir($this->directory) ?: [], ['.', '..']) as $file) {
            unlink($this->directory.'/'.$file);
        }
        rmdir($this->directory);
    }

    private function writeCsv(): void
    {
        $probabilities = fopen($this->directory.'/probabilities.csv', 'wb');
        $maps = fopen($this->directory.'/map_predictions.csv', 'wb');
        fputcsv($probabilities, [
            'year', 'race_id', 'bike_number', 'position_1_probability', 'position_2_probability',
            'position_3_probability', 'top2_probability', 'top3_probability', 'predicted_position', 'is_map_top3',
        ], escape: '');
        fputcsv($maps, [
            'year', 'race_id', 'map_ordered_top3', 'map_ordered_probability',
            'map_top3_set', 'map_top3_set_probability',
        ], escape: '');
        $probability = 1 / $this->entrantCount;
        foreach ([2024, 2025] as $year) {
            foreach (range(1, $this->racesPerYear) as $offset) {
                $raceId = $this->racesPerYear === 1
                    ? ($year === 2024 ? 100 : 200) + $offset
                    : ($year - 2024) * $this->racesPerYear + $offset;
                foreach (range(1, $this->entrantCount) as $bike) {
                    fputcsv($probabilities, [
                        $year,
                        $raceId,
                        $bike,
                        sprintf('%.17g', $probability),
                        sprintf('%.17g', $probability),
                        sprintf('%.17g', $probability),
                        sprintf('%.17g', 2 * $probability),
                        sprintf('%.17g', 3 * $probability),
                        $bike,
                        $bike <= 3 ? 1 : 0,
                    ], escape: '');
                }
                fputcsv($maps, [$year, $raceId, '1-2-3', '0.02', '1-2-3', '0.12'], escape: '');
            }
        }
        fclose($probabilities);
        fclose($maps);
    }

    /** @return array<string,mixed> */
    private function result(): array
    {
        $manifest = [
            'version' => Bt03e05Contract::SOURCE_PREDICTION_MANIFEST_VERSION,
            'race_count' => $this->racesPerYear,
            'entry_count' => $this->racesPerYear * $this->entrantCount,
            'semantic_sha256' => str_repeat('a', 64),
        ];
        $outer = [
            'model' => [
                'optimizer_version' => Bt03e05Contract::SOURCE_OPTIMIZER_VERSION,
                'probability_version' => Bt03e05Contract::SOURCE_PROBABILITY_VERSION,
                'tie_rule_version' => 'BT03E03-ORDERED-TOP3-TIE-v1',
            ],
            'prediction_manifest' => $manifest,
        ];
        $fingerprints = [];
        foreach ([2024 => 1, 2025 => 27] as $year => $runId) {
            $fingerprints[] = [
                'year' => $year,
                'stat_code' => 'STAT-01',
                'feature_run_id' => $runId,
                'source_fingerprint_sha256' => str_repeat((string) ($year - 2023), 64),
                'content_fingerprint_sha256' => str_repeat((string) ($year - 2022), 64),
            ];
        }
        $result = [
            'calculation_version' => Bt03e05Contract::SOURCE_CALCULATION_VERSION,
            'contract' => [
                'contract' => Bt03e05Contract::SOURCE_CONTRACT_NAME,
                'calculation_version' => Bt03e05Contract::SOURCE_CALCULATION_VERSION,
                'optimizer_version' => Bt03e05Contract::SOURCE_OPTIMIZER_VERSION,
                'iteration_semantics_version' => Bt03e05Contract::SOURCE_ITERATION_SEMANTICS_VERSION,
                'probability_version' => Bt03e05Contract::SOURCE_PROBABILITY_VERSION,
                'tie_rule_version' => 'BT03E03-ORDERED-TOP3-TIE-v1',
                'artifact_version' => Bt03e05Contract::SOURCE_ARTIFACT_VERSION,
                'prediction_manifest_version' => Bt03e05Contract::SOURCE_PREDICTION_MANIFEST_VERSION,
            ],
            'source_integrity' => ['start' => ['fingerprints' => $fingerprints], 'end' => ['fingerprints' => $fingerprints], 'unchanged' => true],
            'outcome_snapshot' => [
                'start' => ['outcome_snapshot_manifest_hash' => str_repeat('b', 64)],
                'end' => ['outcome_snapshot_manifest_hash' => str_repeat('b', 64)],
                'unchanged' => true,
            ],
            'outer_2024' => $outer,
            'outer_2025' => $outer,
            'paired_bootstrap_ci' => [],
            'acceptance_gate_input' => [],
            'acceptance_gate' => ['gates' => ['integrity' => true]],
            'audit' => ['2026_access_count' => 0, '2026_query_or_binding_count' => 0],
        ];
        $hash = (new Bt03e03ReproducibilityVerifier($this->hasher))->hash($result);
        $result['reproducibility_hash'] = $hash;
        $result['reproducibility_verification'] = [
            'status' => 'VERIFIED',
            'verified' => true,
            'previous_artifact' => '/tmp/previous/result.json',
            'previous_hash' => $hash,
            'current_hash' => $hash,
        ];

        return $result;
    }

    /** @param array<string,mixed> $result */
    private function writeResult(array $result): void
    {
        file_put_contents($this->directory.'/result.json', $this->json($result));
    }

    /** @param array<string,mixed> $value */
    private function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)."\n";
    }
}
