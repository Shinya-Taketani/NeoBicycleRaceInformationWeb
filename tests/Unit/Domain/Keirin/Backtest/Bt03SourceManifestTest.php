<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Backtest;

use App\Domain\Keirin\Backtest\DTO\Bt03SourceArtifactFingerprintsDto;
use App\Domain\Keirin\Backtest\Repositories\Bt03SourceArtifactRepository;
use App\Domain\Keirin\Backtest\Services\Bt03SourceArtifactFingerprinter;
use App\Domain\Keirin\Backtest\Services\Bt03SourceManifest;
use App\Domain\Keirin\Backtest\Services\Bt03SourceVerifier;
use App\Domain\Keirin\Backtest\Services\FinalHoldoutGuard;
use App\Domain\Keirin\Backtest\Support\Bt02ModelArtifactHasher;
use DomainException;
use Mockery;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class Bt03SourceManifestTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_fixed_manifest_identity_and_entry_signal_contract_are_self_consistent(): void
    {
        $manifest = new Bt03SourceManifest(new Bt02ModelArtifactHasher);

        $this->assertSame(Bt03SourceManifest::HASH, $manifest->computedHash());
        $this->assertSame(5, Bt03SourceManifest::SOURCE_BT02_RUN_ID);
        $this->assertCount(12, Bt03SourceManifest::ENTRY_STAT_CODES);
        $this->assertNotContains('STAT-33', Bt03SourceManifest::ENTRY_STAT_CODES);
        $this->assertNotContains('STAT-41', Bt03SourceManifest::ENTRY_STAT_CODES);
        foreach ([...$manifest->expectedFingerprints()->canonical(), $manifest->expectedFingerprints()->manifestHash, Bt03SourceManifest::HASH] as $hash) {
            $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $hash);
        }
    }

    public function test_artifact_fingerprint_is_semantic_ordered_and_ignores_runtime_timestamps(): void
    {
        $first = $this->fingerprinter($this->fingerprintRepository('2026-01-01 00:00:00+09', false))->compute();
        $second = $this->fingerprinter($this->fingerprintRepository('2099-01-01 00:00:00+09', true))->compute();

        $this->assertEquals($first, $second);
    }

    public function test_meaningful_artifact_change_changes_component_and_manifest_fingerprints(): void
    {
        $original = $this->fingerprinter($this->fingerprintRepository('2026-01-01 00:00:00+09', false))->compute();
        $changed = $this->fingerprinter($this->fingerprintRepository('2026-01-01 00:00:00+09', false, 0.5000000000000001))->compute();

        $this->assertNotSame($original->modelFingerprint, $changed->modelFingerprint);
        $this->assertNotSame($original->manifestHash, $changed->manifestHash);
        $this->assertSame($original->metricFingerprint, $changed->metricFingerprint);
    }

    public function test_source_verifier_accepts_only_the_complete_fixed_run_five_contract(): void
    {
        $repository = $this->verificationRepository();
        $fingerprinter = Mockery::mock(Bt03SourceArtifactFingerprinter::class);
        $fingerprinter->shouldReceive('compute')->once()->andReturn((new Bt03SourceManifest(new Bt02ModelArtifactHasher))->expectedFingerprints());

        $verified = (new Bt03SourceVerifier(
            new Bt03SourceManifest(new Bt02ModelArtifactHasher),
            $repository,
            $fingerprinter,
            new FinalHoldoutGuard,
        ))->verify();

        $this->assertSame(5, $verified->sourceRunId);
        $this->assertSame(3, $verified->foldCount);
        $this->assertSame(14, $verified->signalSpecCount);
        $this->assertSame(432, $verified->modelCount);
        $this->assertSame(648, $verified->metricCount);
        $this->assertSame(668, $verified->effectBinCount);
    }

    public function test_source_verifier_never_falls_back_to_another_succeeded_run(): void
    {
        $repository = $this->verificationRepository(runId: 6);
        $fingerprinter = Mockery::mock(Bt03SourceArtifactFingerprinter::class);
        $fingerprinter->shouldNotReceive('compute');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('fixed source run identity');
        (new Bt03SourceVerifier(
            new Bt03SourceManifest(new Bt02ModelArtifactHasher),
            $repository,
            $fingerprinter,
            new FinalHoldoutGuard,
        ))->verify();
    }

    public function test_source_verifier_rejects_2026_evaluation(): void
    {
        $repository = $this->verificationRepository(evaluationTo: '2026-12-31');
        $fingerprinter = Mockery::mock(Bt03SourceArtifactFingerprinter::class);
        $fingerprinter->shouldNotReceive('compute');

        $this->expectException(DomainException::class);
        (new Bt03SourceVerifier(
            new Bt03SourceManifest(new Bt02ModelArtifactHasher),
            $repository,
            $fingerprinter,
            new FinalHoldoutGuard,
        ))->verify();
    }

    public function test_source_verifier_rejects_any_component_fingerprint_mismatch(): void
    {
        $expected = (new Bt03SourceManifest(new Bt02ModelArtifactHasher))->expectedFingerprints();
        $fingerprinter = Mockery::mock(Bt03SourceArtifactFingerprinter::class);
        $fingerprinter->shouldReceive('compute')->once()->andReturn(new Bt03SourceArtifactFingerprintsDto(
            $expected->runAndFoldFingerprint,
            $expected->signalSpecFingerprint,
            str_repeat('0', 64),
            $expected->metricFingerprint,
            $expected->effectBinFingerprint,
            $expected->manifestHash,
        ));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('models fingerprint mismatched');
        (new Bt03SourceVerifier(
            new Bt03SourceManifest(new Bt02ModelArtifactHasher),
            $this->verificationRepository(),
            $fingerprinter,
            new FinalHoldoutGuard,
        ))->verify();
    }

    private function fingerprinter(Bt03SourceArtifactRepository $repository): Bt03SourceArtifactFingerprinter
    {
        return new Bt03SourceArtifactFingerprinter($repository, new Bt02ModelArtifactHasher);
    }

    private function fingerprintRepository(string $timestamp, bool $reverseJsonKeys, float $coefficient = 0.5): Bt03SourceArtifactRepository
    {
        $parameters = $reverseJsonKeys ? ['z' => 2, 'a' => 1] : ['a' => 1, 'z' => 2];
        $metadata = $reverseJsonKeys ? ['right' => 2, 'left' => 1] : ['left' => 1, 'right' => 2];
        $run = (object) [
            'id' => 5, 'run_uuid' => 'run', 'backtest_code' => 'BT-02', 'status' => 'SUCCEEDED',
            'parameters' => json_encode($parameters, JSON_THROW_ON_ERROR), 'target_race_count' => 1,
            'predicted_race_count' => 1, 'excluded_race_count' => 0, 'error_count' => 0,
            'started_at' => $timestamp, 'finished_at' => $timestamp, 'created_at' => $timestamp, 'updated_at' => $timestamp,
        ];
        $folds = [(object) [
            'id' => 1, 'backtest_run_id' => 5, 'sequence' => 1, 'fold_code' => 'WF_2023',
            'target_race_count' => 1, 'predicted_race_count' => 1, 'excluded_race_count' => 0,
            'started_at' => $timestamp, 'finished_at' => $timestamp, 'created_at' => $timestamp, 'updated_at' => $timestamp,
        ]];
        $specs = [(object) [
            'id' => 2, 'backtest_run_id' => 5, 'stat_code' => 'STAT-07',
            'operational_allowed_quality_reasons' => '[]', 'parameters' => json_encode($parameters, JSON_THROW_ON_ERROR),
            'created_at' => $timestamp, 'updated_at' => $timestamp,
        ]];
        $models = [(object) [
            'id' => 3, 'backtest_run_id' => 5, 'backtest_fold_id' => 1, 'backtest_signal_spec_id' => 2,
            'feature_names' => '["A"]', 'scaler_mean' => '{"A":0}', 'scaler_sd' => '{"A":1}',
            'lambda_candidates' => '[0.1]', 'coefficients' => json_encode([$coefficient], JSON_THROW_ON_ERROR),
            'selected_lambda' => 0.1, 'intercept' => 0.0, 'final_objective' => 1.0, 'iterations' => 1,
            'created_at' => $timestamp, 'updated_at' => $timestamp,
        ]];
        $metrics = [(object) [
            'id' => 4, 'backtest_run_id' => 5, 'backtest_fold_id' => 1, 'backtest_signal_spec_id' => 2,
            'baseline_value' => 0.5, 'incremental_value' => 0.4, 'delta_value' => -0.1,
            'ci_lower' => -0.2, 'ci_upper' => 0.0, 'sample_count' => 5, 'race_count' => 1,
            'bootstrap_iterations' => 2, 'bootstrap_seed' => 1,
            'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR), 'calculated_at' => $timestamp,
            'created_at' => $timestamp, 'updated_at' => $timestamp,
        ]];
        $bins = [(object) [
            'id' => 5, 'backtest_run_id' => 5, 'backtest_fold_id' => 1, 'backtest_signal_spec_id' => 2,
            'bin_index' => 1, 'training_sample_count' => 5, 'lower_bound' => null, 'upper_bound' => 1.0,
            'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR), 'created_at' => $timestamp, 'updated_at' => $timestamp,
        ]];

        return new class($run, $folds, $specs, $models, $metrics, $bins) extends Bt03SourceArtifactRepository
        {
            public function __construct(
                private readonly object $sourceRun,
                private readonly array $sourceFolds,
                private readonly array $sourceSpecs,
                private readonly array $sourceModels,
                private readonly array $sourceMetrics,
                private readonly array $sourceBins,
            ) {}

            public function run(): ?object
            {
                return $this->sourceRun;
            }

            public function folds(): iterable
            {
                return $this->sourceFolds;
            }

            public function signalSpecs(): iterable
            {
                return $this->sourceSpecs;
            }

            public function models(): iterable
            {
                return $this->sourceModels;
            }

            public function metrics(): iterable
            {
                return $this->sourceMetrics;
            }

            public function effectBins(): iterable
            {
                return $this->sourceBins;
            }
        };
    }

    private function verificationRepository(int $runId = 5, string $evaluationTo = '2025-12-31'): Bt03SourceArtifactRepository
    {
        $run = (object) [
            'id' => $runId,
            'run_uuid' => Bt03SourceManifest::SOURCE_BT02_RUN_UUID,
            'backtest_code' => 'BT-02',
            'status' => 'SUCCEEDED',
            'error_count' => 0,
            'source_manifest_hash' => Bt03SourceManifest::SOURCE_BT02_MANIFEST_HASH,
            'target_race_count' => 76458,
            'predicted_race_count' => 75275,
            'excluded_race_count' => 1183,
            'parameters' => json_encode([
                'outcome_snapshot_manifest_hash' => Bt03SourceManifest::OUTCOME_SNAPSHOT_MANIFEST_HASH,
                'outcome_snapshot_path' => 'private/backtest/bt02/outcome-context/'.Bt03SourceManifest::OUTCOME_SNAPSHOT_MANIFEST_HASH,
            ], JSON_THROW_ON_ERROR),
        ];
        $folds = [];
        foreach ([['WF_2023', 1, '2023-12-31'], ['WF_2024', 2, '2024-12-31'], ['WF_2025', 3, $evaluationTo]] as [$code, $sequence, $to]) {
            $year = 2021 + $sequence;
            $folds[] = (object) [
                'backtest_run_id' => 5, 'fold_code' => $code, 'sequence' => $sequence, 'status' => 'SUCCEEDED',
                'train_from' => "{$year}-01-01", 'train_to' => "{$year}-12-31",
                'evaluation_from' => ((int) substr($to, 0, 4)).'-01-01', 'evaluation_to' => $to,
            ];
        }
        $specs = [];
        foreach (Bt03SourceManifest::ENTRY_STAT_CODES as $stat) {
            $specs[] = (object) ['backtest_run_id' => 5, 'stat_code' => $stat, 'analysis_role' => 'ENTRY_INCREMENTAL'];
        }
        $specs[] = (object) ['backtest_run_id' => 5, 'stat_code' => 'STAT-33', 'analysis_role' => 'DIAGNOSTIC_ONLY'];
        $specs[] = (object) ['backtest_run_id' => 5, 'stat_code' => 'STAT-41', 'analysis_role' => 'RACE_STRATIFIER'];
        $models = [];
        foreach (['BASELINE_MATCHED', 'INCREMENTAL'] as $role) {
            foreach (range(1, 216) as $_) {
                $models[] = (object) [
                    'backtest_run_id' => 5, 'model_role' => $role,
                    'probability_semantics' => Bt03SourceManifest::PROBABILITY_SEMANTICS,
                    'convergence_status' => 'CONVERGED_GRADIENT',
                    'objective_version' => Bt03SourceManifest::OBJECTIVE_VERSION,
                    'optimizer_version' => Bt03SourceManifest::OPTIMIZER_VERSION,
                ];
            }
        }
        $metrics = array_fill(0, 648, (object) ['backtest_run_id' => 5]);
        $bins = array_fill(0, 668, (object) ['backtest_run_id' => 5]);

        return new class($run, $folds, $specs, $models, $metrics, $bins) extends Bt03SourceArtifactRepository
        {
            public function __construct(
                private readonly object $sourceRun,
                private readonly array $sourceFolds,
                private readonly array $sourceSpecs,
                private readonly array $sourceModels,
                private readonly array $sourceMetrics,
                private readonly array $sourceBins,
            ) {}

            public function run(): ?object
            {
                return $this->sourceRun;
            }

            public function folds(): iterable
            {
                return $this->sourceFolds;
            }

            public function signalSpecs(): iterable
            {
                return $this->sourceSpecs;
            }

            public function models(): iterable
            {
                return $this->sourceModels;
            }

            public function metrics(): iterable
            {
                return $this->sourceMetrics;
            }

            public function effectBins(): iterable
            {
                return $this->sourceBins;
            }
        };
    }
}
