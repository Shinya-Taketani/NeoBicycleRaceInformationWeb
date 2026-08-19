<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Keirin\Backtest;

use App\Domain\Keirin\Backtest\Calculators\Bt03BinEffectCalculator;
use App\Domain\Keirin\Backtest\DTO\Bt02PreflightSummaryDto;
use App\Domain\Keirin\Backtest\DTO\Bt03BinAssignmentDto;
use App\Domain\Keirin\Backtest\DTO\Bt03BinEffectResultDto;
use App\Domain\Keirin\Backtest\DTO\Bt03ComputedBinEffectDto;
use App\Domain\Keirin\Backtest\DTO\Bt03EvaluationReplaySummaryDto;
use App\Domain\Keirin\Backtest\DTO\Bt03ModelPairDto;
use App\Domain\Keirin\Backtest\DTO\Bt03PreflightSummaryDto;
use App\Domain\Keirin\Backtest\DTO\Bt03SourceArtifactFingerprintsDto;
use App\Domain\Keirin\Backtest\DTO\Bt03SourceVerificationDto;
use App\Domain\Keirin\Backtest\DTO\Bt03StoredModelDto;
use App\Domain\Keirin\Backtest\Repositories\Bt03BinEffectAuditRepository;
use App\Domain\Keirin\Backtest\Services\Bt03ProductionContract;
use App\Domain\Keirin\Backtest\Services\Bt03SourceManifest;
use App\Domain\Keirin\Backtest\Support\Bt03EffectHasher;
use App\Models\BacktestBinEffectScope;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class Bt03BinEffectAuditRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private int $nextRunId = 1;

    public function test_create_run_builds_fixed_target_audit_and_resume_validation_fails_closed(): void
    {
        $this->productionSourceFixture();
        $repository = app(Bt03BinEffectAuditRepository::class);
        $preflight = $this->preflight(72);

        $run = $repository->createRun($preflight);

        $this->assertSame('BT-03', $run->backtest_code);
        $this->assertSame('RUNNING', $run->status);
        $this->assertSame(0, (int) $run->target_race_count);
        $this->assertSame(0, (int) $run->predicted_race_count);
        $this->assertSame(0, (int) $run->excluded_race_count);
        $this->assertSame(Bt03ProductionContract::RACE_COUNT_SEMANTICS, $run->parameters['contract']['race_count_semantics']);
        $this->assertTrue($run->parameters['contract']['2026_blocked']);
        $this->assertSame(3, DB::table('backtest_folds')->where('backtest_run_id', $run->id)->count());
        $this->assertSame(12, DB::table('backtest_signal_specs')->where('backtest_run_id', $run->id)->count());
        $this->assertSame(72, DB::table('backtest_bin_effect_scopes')->where('backtest_run_id', $run->id)->count());
        $this->assertSame(72, DB::table('backtest_bin_effect_scopes')->where('backtest_run_id', $run->id)->where('status', 'PENDING')->count());
        $this->assertSame(0, DB::table('backtest_signal_specs')->where('backtest_run_id', $run->id)->whereIn('stat_code', ['STAT-33', 'STAT-41'])->count());

        $resumed = $repository->resumeRun((int) $run->id, $preflight);
        $this->assertSame(2, $resumed->parameters['runtime']['execution_attempt']);
        $this->assertSame(1, $resumed->parameters['runtime']['resume_count']);

        foreach ([
            ['backtest_code' => 'BT-02'],
            ['source_manifest_hash' => str_repeat('0', 64)],
            ['status' => 'SUCCEEDED'],
        ] as $mutation) {
            $run->refresh()->forceFill($mutation)->save();
            $this->assertResumeRejected($repository, (int) $run->id);
            $run->refresh()->forceFill([
                'backtest_code' => 'BT-03',
                'source_manifest_hash' => Bt03SourceManifest::HASH,
                'status' => 'RUNNING',
            ])->save();
        }
        $parameters = $run->refresh()->parameters;
        $parameters['runtime']['resume_allowed'] = false;
        $parameters['runtime']['resume_block_reason'] = 'SOURCE_DRIFT_AFTER_REPLAY';
        $run->forceFill(['parameters' => $parameters])->save();
        $this->assertResumeRejected($repository, (int) $run->id);
    }

    public function test_repository_persists_nontrivial_doubles_and_verifies_round_trip_hash_and_manifest(): void
    {
        [$scope, $summary] = $this->fixture();
        $repository = app(Bt03BinEffectAuditRepository::class);
        $scope = $repository->startScope($scope);

        $repository->persistScope($scope, 'WF_2023', 'STAT-07', $summary);

        $scope = $scope->refresh();
        $this->assertSame('SUCCEEDED', $scope->status);
        $this->assertSame(3, (int) $scope->effect_count);
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', (string) $scope->effect_manifest_hash);
        $this->assertSame(3, $repository->verifySucceededScope($scope));
        $this->assertSame(
            collect($summary->effects)->pluck('effectHash')->sort()->values()->all(),
            DB::table('backtest_bin_effects')->pluck('effect_hash')->sort()->values()->all(),
        );
        $stored = DB::table('backtest_bin_effects')->where('label_code', 'IS_WIN')->first();
        $this->assertSame(-0.012345678901234568, (float) $stored->baseline_residual_mean);
        $this->assertSame(0.02345678901234568, (float) $stored->probability_shift_mean);
        $this->assertSame(-0.03456789012345679, (float) $stored->log_loss_delta);
        $this->assertSame(-0.04567890123456789, (float) $stored->brier_delta);
    }

    public function test_mid_insert_failure_rolls_back_every_effect_then_failed_scope_is_retryable(): void
    {
        [$scope, $summary] = $this->fixture();
        $repository = app(Bt03BinEffectAuditRepository::class);
        $scope = $repository->startScope($scope);
        $duplicate = new Bt03EvaluationReplaySummaryDto(
            $summary->foldCode,
            $summary->statCode,
            $summary->cohortCode,
            $summary->evaluationRowCount,
            $summary->evaluationRaceCount,
            $summary->trainingBinCount,
            $summary->unseenRowCount,
            $summary->spoolFileCount,
            $summary->spoolByteCount,
            $summary->maximumBinSampleCount,
            $summary->maximumBinRaceCount,
            [$summary->effects[0], $summary->effects[0]],
        );

        try {
            $repository->persistScope($scope, 'WF_2023', 'STAT-07', $duplicate);
            $this->fail('The duplicate effect must fail during the scope transaction.');
        } catch (QueryException) {
            $this->assertSame(0, DB::table('backtest_bin_effects')->count());
        }
        $repository->failScope($scope, new RuntimeException('insert failed'));
        $failed = $scope->refresh();
        $this->assertSame('FAILED', $failed->status);
        $this->assertSame(1, (int) $failed->attempt_count);

        $retried = $repository->startScope($failed);
        $this->assertSame('RUNNING', $retried->status);
        $this->assertSame(2, (int) $retried->attempt_count);
        $this->assertSame(0, DB::table('backtest_bin_effects')->count());
    }

    public function test_succeeded_effect_tampering_is_detected_without_automatic_repair(): void
    {
        [$scope, $summary] = $this->fixture();
        $repository = app(Bt03BinEffectAuditRepository::class);
        $scope = $repository->startScope($scope);
        $repository->persistScope($scope, 'WF_2023', 'STAT-07', $summary);
        $scope = $scope->refresh();
        DB::table('backtest_bin_effects')->where('label_code', 'IS_WIN')->update([
            'effect_hash' => str_repeat('0', 64),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('effect hash mismatched');
        $repository->verifySucceededScope($scope);
    }

    public function test_partial_effect_rows_block_resume_and_are_not_deleted(): void
    {
        [$scope, $summary] = $this->fixture();
        $repository = app(Bt03BinEffectAuditRepository::class);
        $scope = $repository->startScope($scope);
        $repository->persistScope($scope, 'WF_2023', 'STAT-07', $summary);
        DB::table('backtest_bin_effects')->where('label_code', '!=', 'IS_WIN')->delete();
        $scope->forceFill([
            'status' => 'RUNNING',
            'effect_count' => 0,
            'effect_manifest_hash' => null,
            'finished_at' => null,
        ])->save();

        try {
            $repository->startScope($scope->refresh());
            $this->fail('A partial scope must not be retried or repaired.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('not safe to start', $exception->getMessage());
        }
        $this->assertSame(1, DB::table('backtest_bin_effects')->count());
    }

    public function test_stale_running_scope_without_effects_records_interruption_and_retries(): void
    {
        [$scope] = $this->fixture();
        $repository = app(Bt03BinEffectAuditRepository::class);
        $scope = $repository->startScope($scope);

        $retried = $repository->startScope($scope);

        $this->assertSame(2, (int) $retried->attempt_count);
        $this->assertStringContainsString('INTERRUPTED_BEFORE_RESUME', (string) $retried->last_interruption_summary);
        $this->assertSame(0, DB::table('backtest_bin_effects')->count());
    }

    /** @return array{BacktestBinEffectScope, Bt03EvaluationReplaySummaryDto} */
    private function fixture(): array
    {
        $now = now();
        for ($id = 1; $id <= 4; $id++) {
            $this->insertRun("00000000-0000-4000-8000-00000000010{$id}", 'BT-01', 'SUCCEEDED');
        }
        $sourceRun = $this->insertRun(Bt03SourceManifest::SOURCE_BT02_RUN_UUID, 'BT-02', 'SUCCEEDED');
        $this->assertSame(5, $sourceRun);
        $targetRun = $this->insertRun('00000000-0000-4000-8000-000000000106', 'BT-03', 'RUNNING', [
            'runtime' => [
                'completed_scope_count' => 0,
                'resume_allowed' => true,
                'resume_block_reason' => null,
                'last_scope' => null,
                'last_failure' => null,
            ],
        ]);
        $sourceFold = $this->insertFold($sourceRun, 'WF_2023', 'SUCCEEDED');
        $targetFold = $this->insertFold($targetRun, 'WF_2023', 'RUNNING');
        $sourceSpec = $this->insertSpec($sourceRun);
        $targetSpec = $this->insertSpec($targetRun);
        $boundariesHash = str_repeat('7', 64);
        $sourceBin = (int) DB::table('backtest_effect_bins')->insertGetId([
            'backtest_run_id' => $sourceRun,
            'backtest_fold_id' => $sourceFold,
            'backtest_signal_spec_id' => $sourceSpec,
            'cohort_code' => 'STRICT',
            'bin_index' => 1,
            'bin_kind' => 'NUMERIC_RANGE',
            'lower_bound' => -0.12345678901234566,
            'upper_bound' => 0.9876543210987654,
            'category_value' => null,
            'training_sample_count' => 123,
            'boundaries_hash' => $boundariesHash,
            'metadata' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $scope = BacktestBinEffectScope::query()->create([
            'backtest_run_id' => $targetRun,
            'backtest_fold_id' => $targetFold,
            'backtest_signal_spec_id' => $targetSpec,
            'source_backtest_run_id' => $sourceRun,
            'source_backtest_fold_id' => $sourceFold,
            'source_backtest_signal_spec_id' => $sourceSpec,
            'cohort_code' => 'STRICT',
            'status' => 'PENDING',
            'attempt_count' => 0,
            'expected_training_bin_count' => 1,
            'source_boundaries_hash' => $boundariesHash,
            'bootstrap_iterations' => 2000,
            'bootstrap_seed' => 20260812,
        ]);
        $effects = [];
        foreach (Bt03ProductionContract::LABELS as $label) {
            $baseline = $this->insertModel($sourceRun, $sourceFold, $sourceSpec, $label, 'BASELINE_MATCHED');
            $incremental = $this->insertModel($sourceRun, $sourceFold, $sourceSpec, $label, 'INCREMENTAL');
            $effects[] = $this->effect(
                $sourceRun,
                $sourceFold,
                $sourceSpec,
                $sourceBin,
                $boundariesHash,
                $label,
                $baseline,
                $incremental,
            );
        }

        return [$scope, new Bt03EvaluationReplaySummaryDto(
            'WF_2023', 'STAT-07', 'STRICT', 17, 5, 1, 0, 3, 987, 17, 5, $effects,
        )];
    }

    private function productionSourceFixture(): void
    {
        for ($id = 1; $id <= 4; $id++) {
            $this->insertRun("00000000-0000-4000-8000-00000000020{$id}", 'BT-01', 'SUCCEEDED');
        }
        $sourceRun = $this->insertRun(Bt03SourceManifest::SOURCE_BT02_RUN_UUID, 'BT-02', 'SUCCEEDED');
        $this->assertSame(Bt03SourceManifest::SOURCE_BT02_RUN_ID, $sourceRun);
        $foldIds = [];
        foreach (Bt03ProductionContract::FOLDS as $index => $foldCode) {
            $foldIds[$foldCode] = $this->insertFoldWithSequence($sourceRun, $foldCode, $index + 1);
        }
        $specIds = [];
        foreach (Bt03SourceManifest::ENTRY_STAT_CODES as $statCode) {
            $specIds[$statCode] = $this->insertSpecWithCode($sourceRun, $statCode);
        }
        $now = now();
        foreach ($foldIds as $foldCode => $foldId) {
            foreach ($specIds as $statCode => $specId) {
                foreach (Bt03ProductionContract::COHORTS as $cohort) {
                    DB::table('backtest_effect_bins')->insert([
                        'backtest_run_id' => $sourceRun,
                        'backtest_fold_id' => $foldId,
                        'backtest_signal_spec_id' => $specId,
                        'cohort_code' => $cohort,
                        'bin_index' => 1,
                        'bin_kind' => 'CATEGORY',
                        'lower_bound' => null,
                        'upper_bound' => null,
                        'category_value' => 'KNOWN',
                        'training_sample_count' => 10,
                        'boundaries_hash' => hash('sha256', "{$foldCode}:{$statCode}:{$cohort}"),
                        'metadata' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }
        }
    }

    private function preflight(int $effectBinCount): Bt03PreflightSummaryDto
    {
        $hash = str_repeat('a', 64);

        return new Bt03PreflightSummaryDto(
            new Bt03SourceVerificationDto(
                5, 3, 14, 432, 648, $effectBinCount, 432, 432,
                new Bt03SourceArtifactFingerprintsDto($hash, $hash, $hash, $hash, $hash, $hash),
                'private/backtest/bt02/outcome-context/'.$hash,
            ),
            Bt03SourceManifest::OUTCOME_SNAPSHOT_MANIFEST_HASH,
            4,
            new Bt02PreflightSummaryDto(56, 56, 56, $hash),
            Bt03SourceManifest::HASH,
        );
    }

    private function assertResumeRejected(Bt03BinEffectAuditRepository $repository, int $runId): void
    {
        try {
            $repository->assertResumeAllowed($runId);
            $this->fail('The mutated Production run must not be resumable.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('not eligible for resume', $exception->getMessage());
        }
    }

    private function effect(
        int $runId,
        int $foldId,
        int $specId,
        int $binId,
        string $boundariesHash,
        string $label,
        array $baseline,
        array $incremental,
    ): Bt03ComputedBinEffectDto {
        $bin = new Bt03BinAssignmentDto(
            1, 'TRAINING_BIN', 'NUMERIC_RANGE', -0.12345678901234566, 0.9876543210987654,
            null, 123, $binId, $boundariesHash,
        );
        $result = new Bt03BinEffectResultDto(
            'OBSERVED', 17, 5, 4,
            0.23529411764705882, 0.12345678901234566, 0.3456789012345679,
            0.2111111111111111, 0.2345678901234568,
            -0.012345678901234568, -0.02345678901234568, -0.0012345678901234567,
            0.011111111111111112, 0.0011111111111111111, 0.021111111111111112,
            0.02345678901234568, 0.012345678901234568, 0.03456789012345679,
            -0.03456789012345679, -0.04567890123456789, -0.02345678901234568,
            -0.04567890123456789, -0.0567890123456789, -0.03456789012345679,
            2000, 20260812,
        );
        $models = new Bt03ModelPairDto(
            $this->modelDto($baseline, $runId, $foldId, $specId, $label, 'BASELINE_MATCHED'),
            $this->modelDto($incremental, $runId, $foldId, $specId, $label, 'INCREMENTAL'),
        );
        $artifact = [
            'source_bt02_run_id' => $runId,
            'source_bt02_run_uuid' => Bt03SourceManifest::SOURCE_BT02_RUN_UUID,
            'source_fold_id' => $foldId,
            'source_signal_spec_id' => $specId,
            'source_baseline_model_hash' => $baseline['hash'],
            'source_incremental_model_hash' => $incremental['hash'],
            'source_boundaries_hash' => $boundariesHash,
            'source_backtest_effect_bin_id' => $binId,
            'cohort_code' => 'STRICT',
            'label_code' => $label,
            'bin_index' => 1,
            'bin_origin' => 'TRAINING_BIN',
            'bin_kind' => 'NUMERIC_RANGE',
            'lower_bound' => $bin->lowerBound,
            'upper_bound' => $bin->upperBound,
            'category_value' => null,
            'training_sample_count' => 123,
            'evaluation_status' => $result->evaluationStatus,
            'evaluation_sample_count' => $result->evaluationSampleCount,
            'evaluation_race_count' => $result->evaluationRaceCount,
            'positive_count' => $result->positiveCount,
            'observed_rate' => $result->observedRate,
            'observed_rate_ci_lower' => $result->observedRateCiLower,
            'observed_rate_ci_upper' => $result->observedRateCiUpper,
            'baseline_mean_probability' => $result->baselineMeanProbability,
            'incremental_mean_probability' => $result->incrementalMeanProbability,
            'baseline_residual_mean' => $result->baselineResidualMean,
            'baseline_residual_ci_lower' => $result->baselineResidualCiLower,
            'baseline_residual_ci_upper' => $result->baselineResidualCiUpper,
            'incremental_residual_mean' => $result->incrementalResidualMean,
            'incremental_residual_ci_lower' => $result->incrementalResidualCiLower,
            'incremental_residual_ci_upper' => $result->incrementalResidualCiUpper,
            'probability_shift_mean' => $result->probabilityShiftMean,
            'probability_shift_ci_lower' => $result->probabilityShiftCiLower,
            'probability_shift_ci_upper' => $result->probabilityShiftCiUpper,
            'log_loss_delta' => $result->logLossDelta,
            'log_loss_delta_ci_lower' => $result->logLossDeltaCiLower,
            'log_loss_delta_ci_upper' => $result->logLossDeltaCiUpper,
            'brier_delta' => $result->brierDelta,
            'brier_delta_ci_lower' => $result->brierDeltaCiLower,
            'brier_delta_ci_upper' => $result->brierDeltaCiUpper,
            'bootstrap_iterations' => 2000,
            'bootstrap_seed' => 20260812,
            'calculation_version' => Bt03BinEffectCalculator::CALCULATION_VERSION,
        ];

        return new Bt03ComputedBinEffectDto($bin, $label, $models, $result, app(Bt03EffectHasher::class)->hash($artifact));
    }

    /** @param array{id: int, hash: string} $row */
    private function modelDto(array $row, int $runId, int $foldId, int $specId, string $label, string $role): Bt03StoredModelDto
    {
        return new Bt03StoredModelDto(
            $row['id'], $runId, $foldId, $specId, 'WF_2023', 'STAT-07', 'TEST_SIGNAL',
            'STRICT', $label, $role, ['STAT01_RACE_SCORE'], ['STAT01_RACE_SCORE' => 80.0],
            ['STAT01_RACE_SCORE' => 10.0], [0.1], 0.1, 0.123, [0.456], 'test-objective',
            'test-optimizer', 'ENTRY_BINARY_NOT_RACE_NORMALIZED', 'CONVERGED_GRADIENT', $row['hash'], str_repeat('9', 64),
        );
    }

    /** @return array{id: int, hash: string} */
    private function insertModel(int $runId, int $foldId, int $specId, string $label, string $role): array
    {
        $now = now();
        $hash = hash('sha256', "{$label}:{$role}");
        $id = (int) DB::table('backtest_models')->insertGetId([
            'backtest_run_id' => $runId,
            'backtest_fold_id' => $foldId,
            'backtest_signal_spec_id' => $specId,
            'model_role' => $role,
            'label_code' => $label,
            'cohort_code' => 'STRICT',
            'training_from' => '2022-01-01',
            'training_to' => '2022-12-31',
            'inner_fit_from' => '2022-01-01',
            'inner_fit_to' => '2022-09-30',
            'inner_validation_from' => '2022-10-01',
            'inner_validation_to' => '2022-12-31',
            'feature_names' => '["STAT01_RACE_SCORE"]',
            'scaler_mean' => '{"STAT01_RACE_SCORE":80}',
            'scaler_sd' => '{"STAT01_RACE_SCORE":10}',
            'lambda_candidates' => '[0.1]',
            'selected_lambda' => 0.1,
            'intercept' => 0.123,
            'coefficients' => '[0.456]',
            'objective_version' => 'test-objective',
            'optimizer_version' => 'test-optimizer',
            'probability_semantics' => 'ENTRY_BINARY_NOT_RACE_NORMALIZED',
            'convergence_status' => 'CONVERGED_GRADIENT',
            'iterations' => 1,
            'final_objective' => 0.5,
            'model_hash' => $hash,
            'prediction_manifest_hash' => str_repeat('9', 64),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return ['id' => $id, 'hash' => $hash];
    }

    private function insertRun(string $uuid, string $code, string $status, array $parameters = []): int
    {
        $now = now();
        $id = $this->nextRunId++;

        DB::table('backtest_runs')->insert([
            'id' => $id,
            'run_uuid' => $uuid,
            'backtest_code' => $code,
            'calculation_version' => $code === 'BT-03' ? Bt03BinEffectCalculator::CALCULATION_VERSION : 'test',
            'status' => $status,
            'holdout_policy' => Bt03ProductionContract::HOLDOUT_POLICY,
            'source_manifest_version' => Bt03SourceManifest::VERSION,
            'source_manifest_hash' => Bt03SourceManifest::HASH,
            'prediction_rule_version' => Bt03ProductionContract::PREDICTION_RULE_VERSION,
            'parameters' => json_encode($parameters, JSON_THROW_ON_ERROR),
            'started_at' => $now,
            'finished_at' => $status === 'SUCCEEDED' ? $now : null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $id;
    }

    private function insertFold(int $runId, string $code, string $status): int
    {
        $now = now();

        return (int) DB::table('backtest_folds')->insertGetId([
            'backtest_run_id' => $runId,
            'fold_code' => $code,
            'sequence' => 1,
            'train_from' => '2022-01-01',
            'train_to' => '2022-12-31',
            'evaluation_from' => '2023-01-01',
            'evaluation_to' => '2023-12-31',
            'status' => $status,
            'started_at' => $now,
            'finished_at' => $status === 'SUCCEEDED' ? $now : null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function insertFoldWithSequence(int $runId, string $code, int $sequence): int
    {
        $now = now();

        return (int) DB::table('backtest_folds')->insertGetId([
            'backtest_run_id' => $runId,
            'fold_code' => $code,
            'sequence' => $sequence,
            'train_from' => '2021-01-01',
            'train_to' => '2022-12-31',
            'evaluation_from' => substr($code, -4).'-01-01',
            'evaluation_to' => substr($code, -4).'-12-31',
            'status' => 'SUCCEEDED',
            'started_at' => $now,
            'finished_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function insertSpec(int $runId): int
    {
        $now = now();

        return (int) DB::table('backtest_signal_specs')->insertGetId([
            'backtest_run_id' => $runId,
            'stat_code' => 'STAT-07',
            'subject_type' => 'ENTRY',
            'analysis_role' => 'ENTRY_INCREMENTAL',
            'primary_feature_code' => 'TEST_SIGNAL',
            'primary_feature_path' => null,
            'transform_code' => 'IDENTITY',
            'strict_policy_version' => 'test',
            'operational_policy_version' => 'test',
            'operational_allowed_quality_reasons' => '[]',
            'source_manifest_version' => Bt03SourceManifest::VERSION,
            'source_manifest_hash' => Bt03SourceManifest::HASH,
            'parameters' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function insertSpecWithCode(int $runId, string $statCode): int
    {
        $now = now();

        return (int) DB::table('backtest_signal_specs')->insertGetId([
            'backtest_run_id' => $runId,
            'stat_code' => $statCode,
            'subject_type' => 'ENTRY',
            'analysis_role' => 'ENTRY_INCREMENTAL',
            'primary_feature_code' => 'TEST_'.$statCode,
            'primary_feature_path' => null,
            'transform_code' => 'IDENTITY',
            'strict_policy_version' => 'test',
            'operational_policy_version' => 'test',
            'operational_allowed_quality_reasons' => '[]',
            'source_manifest_version' => Bt03SourceManifest::VERSION,
            'source_manifest_hash' => Bt03SourceManifest::HASH,
            'parameters' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
