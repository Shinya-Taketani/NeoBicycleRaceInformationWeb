<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Backtest;

use App\Domain\Keirin\Backtest\Services\Bt03e08ArtifactWriter;
use App\Domain\Keirin\Backtest\Services\Bt03e08ReproducibilityVerifier;
use App\Domain\Keirin\Backtest\Services\Bt03eArtifactFilesystem;
use App\Domain\Keirin\Backtest\Support\Bt03e06RaceSpool;
use App\Domain\Keirin\Backtest\Support\Bt03e08PredictionManifestAccumulator;
use App\Domain\Keirin\Backtest\Support\CanonicalHasher;
use PHPUnit\Framework\TestCase;

final class Bt03e08ArtifactIntegrityTest extends TestCase
{
    private const FORBIDDEN = ['rank', 'status', 'label', 'actual', 'winner_label', 'actual_top2', 'actual_top3', 'payout', 'result'];

    public function test_writer_publishes_outcome_free_predictions_and_revalidates_semantic_manifest(): void
    {
        $directory = sys_get_temp_dir().'/bt03e08-artifact-'.bin2hex(random_bytes(8));
        mkdir($directory, 0775, true);
        $spools = [];
        try {
            $summary = ['reproducibility_hash' => str_repeat('a', 64), 'prediction_manifests' => []];
            foreach ([2024, 2025] as $year) {
                $decision = $this->decision($year);
                $spool = new Bt03e06RaceSpool('DECODER', sys_get_temp_dir().'/bt03e08-artifact-spool-'.$year.'-'.bin2hex(random_bytes(8)).'.jsonl');
                $spool->append($decision);
                $spool->seal();
                $spools[$year] = $spool;
                $identity = ['year' => $year, 'source' => str_repeat((string) ($year - 2024), 64)];
                $manifest = new Bt03e08PredictionManifestAccumulator($year, $identity, new CanonicalHasher);
                $manifest->append($decision);
                $summary['prediction_manifests'][$year] = $manifest->seal();
            }
            $paths = (new Bt03e08ArtifactWriter(new Bt03eArtifactFilesystem, new CanonicalHasher))->write($directory, $summary, $spools);
            $handle = fopen($paths['predictions_csv'], 'rb');
            $this->assertIsResource($handle);
            $header = fgetcsv($handle, escape: '');
            $this->assertIsArray($header);
            foreach (self::FORBIDDEN as $forbidden) {
                $this->assertNotContains($forbidden, $header);
            }
            while (($row = fgetcsv($handle, escape: '')) !== false) {
                $this->assertCount(count($header), $row);
            } fclose($handle);
            $published = json_decode((string) file_get_contents($paths['result_json']), true, flags: JSON_THROW_ON_ERROR);
            $this->assertNoOutcomeKey($published['prediction_manifests']);
            $this->assertFileExists($paths['manifest_json']);
        } finally {
            foreach ($spools as $spool) {
                $spool->cleanup();
            } $this->remove($directory);
        }
    }

    public function test_reproducibility_excludes_runtime_identity_and_requires_identical_semantics(): void
    {
        $verifier = new Bt03e08ReproducibilityVerifier(new CanonicalHasher);
        $first = $this->reproPayload('run-a', '/tmp/a');
        $second = $this->reproPayload('run-b', '/tmp/b');
        $this->assertSame($verifier->hash($first), $verifier->hash($second));
        $second['outer_2025']['model'] = ['changed' => true];
        $this->assertNotSame($verifier->hash($first), $verifier->hash($second));
    }

    /** @return array<string,mixed> */
    private function decision(int $year): array
    {
        return ['year' => $year, 'race_id' => $year, 'primary_position_1_bike' => 1, 'primary_position_2_bike' => 2, 'primary_position_3_bike' => 3, 'source_p1' => 0.4, 'selected_q2' => 0.3, 'selected_r3' => 0.25, 'primary_second_third_objective_score' => 0.55, 'q2_distribution_sha256' => str_repeat('a', 64), 'r3_distribution_sha256' => str_repeat('b', 64), 'map_ordered_top3' => [1, 2, 3], 'map_ordered_probability' => 0.1, 'map_top3_set' => [1, 2, 3], 'map_top3_set_probability' => 0.2, 'top2_marginal_bikes' => [1, 2], 'top3_marginal_bikes' => [1, 2, 3], 'expected_ndcg_top3' => [1, 2, 3], 'winner_tie_count' => 1, 'second_third_tie_count' => 1, 'primary_decision_tied' => false, 'primary_technical_tiebreak_used' => false, 'decoder_tie_diagnostics' => [], 'p1_freeze_verified' => true, 'q2_freeze_verified' => true];
    }

    /** @return array<string,mixed> */
    private function reproPayload(string $run, string $path): array
    {
        $result = ['run_identity' => $run, 'source_bundle_runtime' => ['absolute_path' => $path]];
        foreach (['calculation_version', 'contract', 'source_bundle_identity', 'feature_source_integrity', 'outcome_snapshot_identity', 'fold_definitions', 'inner_layout_identities', 'outer_2024', 'outer_2025', 'prediction_manifests', 'paired_bootstrap_ci', 'acceptance_gate_input'] as $key) {
            $result[$key] = [$key];
        }

        return $result;
    }

    /** @param array<mixed> $value */
    private function assertNoOutcomeKey(array $value): void
    {
        foreach ($value as $key => $item) {
            if (is_string($key)) {
                foreach (self::FORBIDDEN as $forbidden) {
                    $this->assertStringNotContainsString($forbidden, strtolower($key));
                }
            } if (is_array($item)) {
                $this->assertNoOutcomeKey($item);
            }
        }
    }

    private function remove(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        } foreach (array_diff(scandir($directory) ?: [], ['.', '..']) as $file) {
            $path = $directory.'/'.$file;
            if (is_dir($path)) {
                $this->remove($path);
            } else {
                unlink($path);
            }
        } rmdir($directory);
    }
}
