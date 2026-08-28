<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Backtest;

use App\Domain\Keirin\Backtest\Calculators\Bt03e05AcceptanceGate;
use App\Domain\Keirin\Backtest\Calculators\Bt03e05DecisionDecoder;
use App\Domain\Keirin\Backtest\Calculators\Bt03e05MetricEvaluator;
use App\Domain\Keirin\Backtest\Calculators\Bt03e05PairedBootstrap;
use App\Domain\Keirin\Backtest\Calculators\Type7Quantile;
use App\Domain\Keirin\Backtest\Services\Bt03e05ArtifactWriter;
use App\Domain\Keirin\Backtest\Services\Bt03e05Contract;
use App\Domain\Keirin\Backtest\Services\Bt03e05DevelopmentEvaluationService;
use App\Domain\Keirin\Backtest\Services\Bt03e05ReproducibilityVerifier;
use App\Domain\Keirin\Backtest\Services\Bt03e05SourceBundleLoader;
use App\Domain\Keirin\Backtest\Services\Bt03eArtifactFilesystem;
use App\Domain\Keirin\Backtest\Support\Bt03e05DecoderManifestAccumulator;
use App\Domain\Keirin\Backtest\Support\Bt03e05MetricContributionSpool;
use App\Domain\Keirin\Backtest\Support\Bt03e05RaceSpool;
use App\Domain\Keirin\Backtest\Support\CanonicalHasher;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

class Bt03e05IntegrityTest extends TestCase
{
    public function test_contract_freezes_versions_decoders_bootstrap_and_2026_guard(): void
    {
        $plan = Bt03e05Contract::plan();

        $this->assertSame('BT03E05-WINNER-PRESERVING-DECODER-v1', $plan['calculation_version']);
        $this->assertSame('BT03E05-WINNER-PRESERVING-LEXICOGRAPHIC-v1', $plan['decoder_version']);
        $this->assertSame('BT03E05-DECODER-TIE-v1', $plan['tie_rule_version']);
        $this->assertSame('BT03E05-DEVELOPMENT-ARTIFACT-v1', $plan['artifact_version']);
        $this->assertSame('BT03E05-DECODER-SEMANTIC-MANIFEST-v1', $plan['decoder_manifest_version']);
        $this->assertSame([2024, 2025], $plan['development_years']);
        $this->assertSame('FORBIDDEN', $plan['2026_access']);
        $this->assertSame('FORBIDDEN', $plan['model_fitting']);
        $this->assertSame(2000, $plan['bootstrap']['iterations']);
        $this->assertSame(20260812, $plan['bootstrap']['seed']);
        $this->assertSame('WINNER_PRESERVING_LEXICOGRAPHIC', $plan['metric_to_decoder']['WINNER_HIT_AT_1']);
    }

    public function test_source_model_contract_is_literal_frozen_to_verified_e03_v2(): void
    {
        $expected = [
            'contract' => 'BT-03E-03-POSITION-SPECIFIC-PROBABILITY',
            'calculation_version' => 'BT03E03-POSITION-PROBABILITY-v2',
            'optimizer_version' => 'BT03E03-FISTA-POSITION-SOFTMAX-v2',
            'iteration_semantics_version' => 'BT03E03-ACCEPTED-UPDATE-BUDGET-v1',
            'probability_version' => 'BT03E03-SEQUENTIAL-MARGINAL-v1',
            'artifact_version' => 'BT03E03-DEVELOPMENT-ARTIFACT-v2',
            'prediction_manifest_version' => 'BT03E03-PREDICTION-SEMANTIC-MANIFEST-v1',
            'reproducibility' => 'VERIFIED',
            'integrity' => 'PASS',
        ];

        $this->assertSame($expected['contract'], Bt03e05Contract::SOURCE_CONTRACT_NAME);
        $this->assertSame($expected['calculation_version'], Bt03e05Contract::SOURCE_CALCULATION_VERSION);
        $this->assertSame($expected['optimizer_version'], Bt03e05Contract::SOURCE_OPTIMIZER_VERSION);
        $this->assertSame($expected['iteration_semantics_version'], Bt03e05Contract::SOURCE_ITERATION_SEMANTICS_VERSION);
        $this->assertSame($expected['probability_version'], Bt03e05Contract::SOURCE_PROBABILITY_VERSION);
        $this->assertSame($expected['artifact_version'], Bt03e05Contract::SOURCE_ARTIFACT_VERSION);
        $this->assertSame($expected['prediction_manifest_version'], Bt03e05Contract::SOURCE_PREDICTION_MANIFEST_VERSION);
        $this->assertSame($expected['reproducibility'], Bt03e05Contract::SOURCE_REPRODUCIBILITY_STATUS);
        $this->assertSame($expected['integrity'], Bt03e05Contract::SOURCE_INTEGRITY_STATUS);
        $this->assertSame($expected, Bt03e05Contract::plan()['source_model_contract']);
    }

    public function test_e05_source_version_contract_does_not_dynamically_reference_e03_contract(): void
    {
        foreach ([Bt03e05Contract::class, Bt03e05SourceBundleLoader::class] as $class) {
            $path = (new ReflectionClass($class))->getFileName();
            $this->assertIsString($path);
            $source = file_get_contents($path);
            $this->assertIsString($source);
            $this->assertStringNotContainsString('Bt03e03Contract::', $source);
        }
    }

    public function test_bootstrap_is_paired_year_equal_and_bit_exact_deterministic(): void
    {
        $spools = [2024 => $this->metricSpool(2024), 2025 => $this->metricSpool(2025)];
        try {
            $bootstrap = new Bt03e05PairedBootstrap(new Type7Quantile);
            $first = $bootstrap->evaluate($spools);
            $second = $bootstrap->evaluate($spools);

            $this->assertSame($first, $second);
            $this->assertSame(array_keys(array_fill_keys(Bt03e05MetricEvaluator::METRIC_CODES, null)), array_keys($first));
        } finally {
            foreach ($spools as $spool) {
                $spool->cleanup();
            }
        }
    }

    public function test_gate_uses_winner_preserving_primary_thresholds_unchanged(): void
    {
        [$outer, $intervals] = $this->passingGateInput();
        $passed = (new Bt03e05AcceptanceGate)->evaluate($outer, $intervals, true);

        $this->assertSame('PASS / GO_TO_FREEZE', $passed['status']);
        $intervals['WINNER_HIT_AT_1']['ci_lower'] = -0.0015;
        $failed = (new Bt03e05AcceptanceGate)->evaluate($outer, $intervals, true);
        $this->assertFalse($failed['gates']['non_inferiority']);
    }

    public function test_every_gate_numeric_boundary_keeps_the_frozen_inclusive_or_exclusive_semantics(): void
    {
        $gate = new Bt03e05AcceptanceGate;
        [$outer, $intervals] = $this->passingGateInput();

        $intervals['POSITION_HIT_RATE_AT_3']['ci_lower'] = 0.0;
        $this->assertFalse($gate->evaluate($outer, $intervals, true)['gates']['superiority']);

        [$outer, $intervals] = $this->passingGateInput();
        $outer[2024]['delta']['WINNER_HIT_AT_1'] = -0.003;
        $this->assertTrue($gate->evaluate($outer, $intervals, true)['gates']['temporal_stability']);
        $outer[2024]['delta']['WINNER_HIT_AT_1'] = -0.0030000001;
        $this->assertFalse($gate->evaluate($outer, $intervals, true)['gates']['temporal_stability']);

        [$outer, $intervals] = $this->passingGateInput();
        $supporting = ['EXACT_ORDERED_TOP3_RATE', 'EXACT_TOP3_SET_RATE', 'TOP3_COVERAGE_AT_3', 'EXACT_TOP2_SET_RATE', 'TOP2_COVERAGE_AT_2', 'NDCG_AT_3'];
        foreach ($outer as &$year) {
            foreach ($supporting as $offset => $metric) {
                $year['delta'][$metric] = $offset < 4 ? 0.0 : -0.002;
            }
        }
        unset($year);
        $this->assertTrue($gate->evaluate($outer, $intervals, true)['gates']['supporting']);
        $outer[2024]['delta']['NDCG_AT_3'] = -0.0020000001;
        $outer[2025]['delta']['NDCG_AT_3'] = -0.0020000001;
        $this->assertFalse($gate->evaluate($outer, $intervals, true)['gates']['supporting']);

        [$outer, $intervals] = $this->passingGateInput();
        foreach ($outer as &$year) {
            $year['tie_diagnostics']['primary_score_tied_races'] = 1;
            $year['tie_diagnostics']['baseline_exact_score_tied_races'] = 1;
            $year['tie_diagnostics']['technical_tiebreak_races'] = 1;
        }
        unset($year);
        $this->assertTrue($gate->evaluate($outer, $intervals, true)['gates']['tie_quality']);
        $outer[2024]['tie_diagnostics']['technical_tiebreak_races'] = 2;
        $outer[2025]['tie_diagnostics']['technical_tiebreak_races'] = 2;
        $this->assertFalse($gate->evaluate($outer, $intervals, true)['gates']['tie_quality']);

        [$outer, $intervals] = $this->passingGateInput();
        foreach ($outer as &$year) {
            $year['delta']['POSITION_2_ACCURACY'] = 0.0;
        }
        unset($year);
        $this->assertTrue($gate->evaluate($outer, $intervals, true)['gates']['position_redesign']);
        foreach (['WINNER_HIT_AT_1', 'POSITION_3_ACCURACY', 'POSITION_HIT_RATE_AT_3'] as $metric) {
            [$boundaryOuter, $boundaryIntervals] = $this->passingGateInput();
            foreach ($boundaryOuter as &$year) {
                $year['delta'][$metric] = 0.0;
            }
            unset($year);
            $this->assertFalse($gate->evaluate($boundaryOuter, $boundaryIntervals, true)['gates']['position_redesign'], $metric);
        }

        [$outer, $intervals] = $this->passingGateInput();
        $outer[2024]['delta']['WINNER_HIT_AT_1'] = 0.0;
        $this->assertTrue($gate->evaluate($outer, $intervals, true)['gates']['win_preservation']);
        $outer[2024]['delta']['WINNER_HIT_AT_1'] = -PHP_FLOAT_EPSILON;
        $this->assertFalse($gate->evaluate($outer, $intervals, true)['gates']['win_preservation']);
    }

    public function test_reproducibility_excludes_paths_and_runtime_but_pins_decisions_and_source(): void
    {
        $verifier = new Bt03e05ReproducibilityVerifier(new CanonicalHasher);
        $base = $this->summary();
        $changedRuntime = $base;
        $changedRuntime['run_identity'] = 'other';
        $changedRuntime['runtime'] = ['seconds' => 99];
        $changedRuntime['source_bundle_runtime'] = ['absolute_path' => '/different'];
        $this->assertSame($verifier->hash($base), $verifier->hash($changedRuntime));

        $changedDecision = $base;
        $changedDecision['decoder_manifests'][2024]['semantic_sha256'] = str_repeat('f', 64);
        $this->assertNotSame($verifier->hash($base), $verifier->hash($changedDecision));
        $changedSource = $base;
        $changedSource['source_bundle_identity']['probabilities_csv_sha256'] = str_repeat('e', 64);
        $this->assertNotSame($verifier->hash($base), $verifier->hash($changedSource));
        $changedDiagnostics = $base;
        $changedDiagnostics['diagnostics'] = ['winner_tie_count' => 2];
        $this->assertNotSame($verifier->hash($base), $verifier->hash($changedDiagnostics));
        $changedMetric = $base;
        $changedMetric['outer_2024']['metrics'] = ['WINNER_HIT_AT_1' => 1.0];
        $this->assertNotSame($verifier->hash($base), $verifier->hash($changedMetric));
    }

    public function test_decoder_manifest_rejects_outcome_labels(): void
    {
        $decision = (new Bt03e05DecisionDecoder)->decode($this->uniformSource(2024));
        $decision['winner_label'] = 1;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('contained outcome data');
        (new Bt03e05DecoderManifestAccumulator(new CanonicalHasher))->append($decision);
    }

    public function test_reproducibility_requires_a_second_matching_result_and_fails_on_semantic_drift(): void
    {
        $verifier = new Bt03e05ReproducibilityVerifier(new CanonicalHasher);
        $result = $this->summary();
        $hash = $verifier->hash($result);
        $this->assertSame('REPRODUCIBILITY VERIFICATION REQUIRED', $verifier->verify(null, $hash)['status']);
        $result['reproducibility_hash'] = $hash;
        $path = sys_get_temp_dir().'/bt03e05-repro-'.bin2hex(random_bytes(8)).'.json';
        file_put_contents($path, json_encode($result, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
        try {
            $this->assertSame('VERIFIED', $verifier->verify($path, $hash)['status']);
            $changed = $result;
            $changed['decoder_manifests'][2024]['semantic_sha256'] = str_repeat('f', 64);
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('mismatched');
            $verifier->verify($path, $verifier->hash($changed));
        } finally {
            unlink($path);
        }
    }

    public function test_decoder_artifact_contains_no_outcomes_and_revalidates_semantic_manifest(): void
    {
        $directory = sys_get_temp_dir().'/bt03e05-artifact-'.bin2hex(random_bytes(8));
        mkdir($directory, 0775, true);
        $spools = [2024 => $this->decoderSpool(2024), 2025 => $this->decoderSpool(2025)];
        try {
            $summary = $this->summary();
            foreach ($spools as $year => $spool) {
                $manifest = new Bt03e05DecoderManifestAccumulator(new CanonicalHasher);
                foreach ($spool->races() as $decision) {
                    $manifest->append($decision);
                }
                $summary['decoder_manifests'][$year] = $manifest->seal();
            }
            $summary['reproducibility_hash'] = (new Bt03e05ReproducibilityVerifier(new CanonicalHasher))->hash($summary);
            $paths = (new Bt03e05ArtifactWriter(new Bt03eArtifactFilesystem, new CanonicalHasher))->write($directory, $summary, $spools);
            $handle = fopen($paths['decoder_predictions_csv'], 'rb');
            $header = is_resource($handle) ? fgetcsv($handle, escape: '') : false;
            if (is_resource($handle)) {
                fclose($handle);
            }

            $this->assertNotContains('rank', $header);
            $this->assertNotContains('status', $header);
            $this->assertNotContains('official_result', $header);
            $this->assertFileExists($paths['manifest_json']);
        } finally {
            foreach ($spools as $spool) {
                $spool->cleanup();
            }
            $this->removeDirectory($directory);
        }
    }

    public function test_artifact_manifest_mismatch_fails_without_final_publication(): void
    {
        $directory = sys_get_temp_dir().'/bt03e05-artifact-fail-'.bin2hex(random_bytes(8));
        mkdir($directory, 0775, true);
        $spools = [2024 => $this->decoderSpool(2024), 2025 => $this->decoderSpool(2025)];
        try {
            $summary = $this->summary();
            $summary['reproducibility_hash'] = (new Bt03e05ReproducibilityVerifier(new CanonicalHasher))->hash($summary);
            try {
                (new Bt03e05ArtifactWriter(new Bt03eArtifactFilesystem, new CanonicalHasher))->write($directory, $summary, $spools);
                $this->fail('A mismatched decoder manifest must not publish.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('manifest mismatched', $exception->getMessage());
            }
            $this->assertSame([], array_values(array_filter(scandir($directory) ?: [], static fn (string $name): bool => ! in_array($name, ['.', '..'], true))));
        } finally {
            foreach ($spools as $spool) {
                $spool->cleanup();
            }
            $this->removeDirectory($directory);
        }
    }

    public function test_artifact_write_and_atomic_publish_failures_leave_no_partial_bundle(): void
    {
        foreach (['write', 'publish'] as $failure) {
            $directory = sys_get_temp_dir().'/bt03e05-artifact-'.$failure.'-'.bin2hex(random_bytes(8));
            mkdir($directory, 0775, true);
            $spools = [2024 => $this->decoderSpool(2024), 2025 => $this->decoderSpool(2025)];
            try {
                $summary = $this->summary();
                foreach ($spools as $year => $spool) {
                    $manifest = new Bt03e05DecoderManifestAccumulator(new CanonicalHasher);
                    foreach ($spool->races() as $decision) {
                        $manifest->append($decision);
                    }
                    $summary['decoder_manifests'][$year] = $manifest->seal();
                }
                $summary['reproducibility_hash'] = (new Bt03e05ReproducibilityVerifier(new CanonicalHasher))->hash($summary);
                $filesystem = new class($failure) extends Bt03eArtifactFilesystem
                {
                    public function __construct(private readonly string $failure) {}

                    public function writeExact(string $path, string $contents): void
                    {
                        if ($this->failure === 'write' && str_ends_with($path, '/result.json')) {
                            throw new RuntimeException('synthetic write failure');
                        }
                        parent::writeExact($path, $contents);
                    }

                    public function publish(string $temporaryDirectory, string $finalDirectory): void
                    {
                        if ($this->failure === 'publish') {
                            throw new RuntimeException('synthetic publish failure');
                        }
                        parent::publish($temporaryDirectory, $finalDirectory);
                    }
                };
                try {
                    (new Bt03e05ArtifactWriter($filesystem, new CanonicalHasher))->write($directory, $summary, $spools);
                    $this->fail("A synthetic {$failure} failure must not publish.");
                } catch (RuntimeException $exception) {
                    $this->assertStringContainsString("synthetic {$failure} failure", $exception->getMessage());
                }
                $this->assertSame([], array_values(array_filter(scandir($directory) ?: [], static fn (string $name): bool => ! in_array($name, ['.', '..'], true))));
            } finally {
                foreach ($spools as $spool) {
                    $spool->cleanup();
                }
                $this->removeDirectory($directory);
            }
        }
    }

    public function test_two_artifacts_created_in_the_same_second_have_distinct_bundle_names(): void
    {
        $directory = sys_get_temp_dir().'/bt03e05-artifact-collision-'.bin2hex(random_bytes(8));
        mkdir($directory, 0775, true);
        $spools = [2024 => $this->decoderSpool(2024), 2025 => $this->decoderSpool(2025)];
        try {
            $summary = $this->summary();
            foreach ($spools as $year => $spool) {
                $manifest = new Bt03e05DecoderManifestAccumulator(new CanonicalHasher);
                foreach ($spool->races() as $decision) {
                    $manifest->append($decision);
                }
                $summary['decoder_manifests'][$year] = $manifest->seal();
            }
            $summary['reproducibility_hash'] = (new Bt03e05ReproducibilityVerifier(new CanonicalHasher))->hash($summary);
            $writer = new Bt03e05ArtifactWriter(new Bt03eArtifactFilesystem, new CanonicalHasher);

            $first = $writer->write($directory, $summary, $spools);
            $second = $writer->write($directory, $summary, $spools);

            $this->assertNotSame($first['bundle_directory'], $second['bundle_directory']);
            $this->assertDirectoryExists($first['bundle_directory']);
            $this->assertDirectoryExists($second['bundle_directory']);
        } finally {
            foreach ($spools as $spool) {
                $spool->cleanup();
            }
            $this->removeDirectory($directory);
        }
    }

    public function test_runtime_service_has_no_model_fitting_dependency(): void
    {
        $constructor = (new ReflectionClass(Bt03e05DevelopmentEvaluationService::class))->getConstructor();
        $types = array_map(
            static fn (\ReflectionParameter $parameter): string => (string) $parameter->getType(),
            $constructor?->getParameters() ?? [],
        );
        $source = (string) file_get_contents((new ReflectionClass(Bt03e05DevelopmentEvaluationService::class))->getFileName());

        foreach (['Fista', 'OneSe', 'ConditionalSoftmaxObjective', 'ParameterLayout', 'buildBinned'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, implode('|', $types).$source);
        }
        foreach (['Bt02SignalFeatureRepository', 'Bt02SignalEligibilityEvaluator', 'STAT_CODES'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, implode('|', $types).$source);
        }
    }

    private function metricSpool(int $year): Bt03e05MetricContributionSpool
    {
        $spool = new Bt03e05MetricContributionSpool(sys_get_temp_dir().'/bt03e05-bootstrap-'.$year.'-'.bin2hex(random_bytes(8)).'.bin');
        $comparison = ['candidate' => [], 'baseline' => []];
        foreach (Bt03e05MetricEvaluator::METRIC_CODES as $offset => $metric) {
            $comparison['candidate'][$metric] = ['numerator' => (float) (($offset + $year) % 2), 'denominator' => 1.0];
            $comparison['baseline'][$metric] = ['numerator' => 0.0, 'denominator' => 1.0];
        }
        foreach (range(1, 5) as $_) {
            $spool->append($comparison);
        }
        $spool->seal();

        return $spool;
    }

    /** @return array{array<int,array<string,mixed>>,array<string,array{ci_lower:float,ci_upper:float}>} */
    private function passingGateInput(): array
    {
        $outer = [];
        foreach ([2024, 2025] as $year) {
            $outer[$year] = [
                'delta' => array_fill_keys(Bt03e05MetricEvaluator::METRIC_CODES, 0.01),
                'tie_diagnostics' => [
                    'primary_score_tied_races' => 0,
                    'baseline_exact_score_tied_races' => 1,
                    'technical_tiebreak_races' => 0,
                ],
                'race_count' => 1000,
            ];
        }

        return [
            $outer,
            array_fill_keys(Bt03e05MetricEvaluator::METRIC_CODES, ['ci_lower' => 0.001, 'ci_upper' => 0.02]),
        ];
    }

    private function decoderSpool(int $year): Bt03e05RaceSpool
    {
        $spool = new Bt03e05RaceSpool('DECODER', sys_get_temp_dir().'/bt03e05-decoder-'.$year.'-'.bin2hex(random_bytes(8)).'.jsonl');
        $source = $this->uniformSource($year);
        $spool->append((new Bt03e05DecisionDecoder)->decode($source));
        $spool->seal();

        return $spool;
    }

    /** @return array<string,mixed> */
    private function uniformSource(int $year): array
    {
        $entries = [];
        foreach (range(1, 5) as $bike) {
            $entries[] = [
                'bike' => $bike,
                'position_1_probability' => 0.2,
                'position_2_probability' => 0.2,
                'position_3_probability' => 0.2,
                'top2_probability' => 0.4,
                'top3_probability' => 0.6,
            ];
        }

        return [
            'year' => $year,
            'race_id' => $year,
            'entries' => $entries,
            'map_ordered_top3' => [1, 2, 3],
            'map_ordered_probability' => 0.01,
            'map_top3_set' => [1, 2, 3],
            'map_top3_set_probability' => 0.06,
        ];
    }

    /** @return array<string,mixed> */
    private function summary(): array
    {
        $manifest = ['version' => Bt03e05Contract::DECODER_MANIFEST_VERSION, 'race_count' => 1, 'semantic_sha256' => str_repeat('a', 64)];

        return [
            'run_identity' => 'ignored',
            'calculation_version' => Bt03e05Contract::CALCULATION_VERSION,
            'contract' => Bt03e05Contract::plan(),
            'source_bundle_identity' => [
                'source_reproducibility_hash' => str_repeat('1', 64),
                'probabilities_csv_sha256' => str_repeat('2', 64),
            ],
            'source_bundle_runtime' => ['absolute_path' => '/tmp/source'],
            'baseline_source_integrity' => ['unchanged' => true],
            'outcome_snapshot_identity' => ['unchanged' => true],
            'outer_2024' => ['metrics' => []],
            'outer_2025' => ['metrics' => []],
            'diagnostics' => [],
            'decoder_manifests' => [2024 => $manifest, 2025 => $manifest],
            'paired_bootstrap_ci' => [],
            'acceptance_gate_input' => [],
            'runtime' => ['seconds' => 1],
        ];
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }
        foreach (array_diff(scandir($directory) ?: [], ['.', '..']) as $file) {
            $path = $directory.'/'.$file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($directory);
    }
}
