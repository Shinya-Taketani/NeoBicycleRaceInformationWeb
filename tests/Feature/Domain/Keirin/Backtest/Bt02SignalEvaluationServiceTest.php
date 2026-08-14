<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Keirin\Backtest;

use App\Domain\Keirin\Backtest\Contracts\Bt02OutcomeContextSnapshot;
use App\Domain\Keirin\Backtest\DTO\Bt02PreflightSummaryDto;
use App\Domain\Keirin\Backtest\Repositories\BacktestFeatureRepository;
use App\Domain\Keirin\Backtest\Repositories\Bt02AuditRepository;
use App\Domain\Keirin\Backtest\Services\Bt01SourceManifest;
use App\Domain\Keirin\Backtest\Services\Bt02BaselineFingerprintPreflightService;
use App\Domain\Keirin\Backtest\Services\Bt02EntrySignalEvaluator;
use App\Domain\Keirin\Backtest\Services\Bt02FingerprintPreflightService;
use App\Domain\Keirin\Backtest\Services\Bt02FoldProvider;
use App\Domain\Keirin\Backtest\Services\Bt02OutcomeContextSnapshotBuilder;
use App\Domain\Keirin\Backtest\Services\Bt02OutcomeContextSnapshotSession;
use App\Domain\Keirin\Backtest\Services\Bt02SignalEvaluationService;
use App\Domain\Keirin\Backtest\Services\Bt02SignalRegistry;
use App\Domain\Keirin\Backtest\Services\Bt02SourceManifest;
use App\Domain\Keirin\Backtest\Services\FinalHoldoutGuard;
use App\Models\BacktestFold;
use App\Models\BacktestModel;
use App\Models\BacktestRun;
use App\Models\BacktestSignalMetric;
use App\Models\BacktestSignalSpec;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class Bt02SignalEvaluationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_preflight_failure_happens_before_outcome_access_or_backtest_writes(): void
    {
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });
        $preflight = Mockery::mock(Bt02FingerprintPreflightService::class);
        $preflight->shouldReceive('run')->once()->andThrow(new RuntimeException('fingerprint mismatch'));
        $snapshotBuilder = Mockery::mock(Bt02OutcomeContextSnapshotBuilder::class);
        $snapshotBuilder->shouldNotReceive('build');
        $evaluator = Mockery::mock(Bt02EntrySignalEvaluator::class);
        $evaluator->shouldNotReceive('evaluate');

        $this->expectException(RuntimeException::class);
        try {
            $this->service($preflight, $evaluator, snapshotBuilder: $snapshotBuilder)->execute();
        } finally {
            $this->assertSame(0, BacktestRun::query()->count());
            $this->assertSame([], array_values(array_filter($queries, fn (string $sql): bool => str_contains($sql, 'race_results'))));
            $this->assertSame([], array_values(array_filter($queries, fn (string $sql): bool => preg_match('/\bfrom\s+"?races"?\b/', $sql) === 1)));
            $this->assertSame([], array_values(array_filter($queries, fn (string $sql): bool => str_contains($sql, 'race_payouts'))));
        }
    }

    public function test_baseline_fingerprint_failure_happens_before_signal_preflight_outcome_or_backtest_writes(): void
    {
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });
        $baselinePreflight = Mockery::mock(Bt02BaselineFingerprintPreflightService::class);
        $baselinePreflight->shouldReceive('run')->once()->andThrow(new RuntimeException('baseline content mismatch'));
        $signalPreflight = Mockery::mock(Bt02FingerprintPreflightService::class);
        $signalPreflight->shouldNotReceive('run');
        $snapshotBuilder = Mockery::mock(Bt02OutcomeContextSnapshotBuilder::class);
        $snapshotBuilder->shouldNotReceive('build');
        $evaluator = Mockery::mock(Bt02EntrySignalEvaluator::class);
        $evaluator->shouldNotReceive('evaluate');

        $this->expectException(RuntimeException::class);
        try {
            $this->service($signalPreflight, $evaluator, $baselinePreflight, $snapshotBuilder)->execute();
        } finally {
            $this->assertSame(0, BacktestRun::query()->count());
            $this->assertSame([], array_values(array_filter($queries, fn (string $sql): bool => str_contains($sql, 'race_results'))));
            $this->assertSame([], array_values(array_filter($queries, fn (string $sql): bool => preg_match('/\bfrom\s+"?races"?\b/', $sql) === 1)));
            $this->assertSame([], array_values(array_filter($queries, fn (string $sql): bool => str_contains($sql, 'race_payouts'))));
        }
    }

    public function test_snapshot_failure_happens_before_backtest_run_creation(): void
    {
        $preflight = Mockery::mock(Bt02FingerprintPreflightService::class);
        $preflight->shouldReceive('run')->once()->andReturn(new Bt02PreflightSummaryDto(56, 56, 56, Bt02SourceManifest::HASH));
        $baselinePreflight = Mockery::mock(Bt02BaselineFingerprintPreflightService::class);
        $baselinePreflight->shouldReceive('run')->once();
        $snapshotBuilder = Mockery::mock(Bt02OutcomeContextSnapshotBuilder::class);
        $snapshotBuilder->shouldReceive('build')->once()->andThrow(new RuntimeException('snapshot count mismatch'));
        $evaluator = Mockery::mock(Bt02EntrySignalEvaluator::class);
        $evaluator->shouldNotReceive('evaluate');

        $this->expectException(RuntimeException::class);
        try {
            $this->service($preflight, $evaluator, $baselinePreflight, $snapshotBuilder)->execute();
        } finally {
            $this->assertSame(0, BacktestRun::query()->count());
        }
    }

    public function test_execution_uses_only_fixed_folds_and_twelve_entry_signals(): void
    {
        $preflight = Mockery::mock(Bt02FingerprintPreflightService::class);
        $preflight->shouldReceive('run')->twice()->andReturn(new Bt02PreflightSummaryDto(56, 56, 56, Bt02SourceManifest::HASH));
        $calls = [];
        $evaluator = Mockery::mock(Bt02EntrySignalEvaluator::class);
        $evaluator->shouldReceive('evaluate')->times(36)->andReturnUsing(function (
            BacktestRun $run,
            BacktestFold $fold,
            $definition,
            $signal,
            BacktestSignalSpec $spec,
            callable $progress,
        ) use (&$calls): array {
            $calls[] = [$definition->code, $signal->statCode, $signal->analysisRole->value];
            $raceIds = ((int) substr($signal->statCode, 5)) % 2 === 0 ? [3, 4, 5] : [1, 2, 3, 4];
            $progress($raceIds, 'STRICT', 'IS_WIN', hash('sha256', 'baseline-'.$definition->code.$signal->statCode), hash('sha256', 'incremental-'.$definition->code.$signal->statCode));

            return ['models' => 12, 'metrics' => 18, 'races' => count($raceIds), 'rows' => 35, 'race_ids' => $raceIds, 'manifest_hash' => hash('sha256', $definition->code.$signal->statCode)];
        });

        $summary = $this->service($preflight, $evaluator)->execute();

        $this->assertSame(3, $summary->foldCount);
        $this->assertSame(12, $summary->signalCount);
        $this->assertSame(432, $summary->modelCount);
        $this->assertSame(648, $summary->metricCount);
        $this->assertSame(['WF_2023', 'WF_2024', 'WF_2025'], BacktestFold::query()->orderBy('sequence')->pluck('fold_code')->all());
        $this->assertSame(['SUCCEEDED'], BacktestFold::query()->distinct()->pluck('status')->all());
        $this->assertNotContains('STAT-33', array_column($calls, 1));
        $this->assertNotContains('STAT-41', array_column($calls, 1));
        $this->assertSame(['ENTRY_INCREMENTAL'], array_values(array_unique(array_column($calls, 2))));
        $this->assertSame(14, BacktestSignalSpec::query()->count());
        $this->assertSame('SUCCEEDED', BacktestRun::query()->sole()->status);
        $folds = BacktestFold::query()->orderBy('sequence')->get();
        $this->assertSame([25561, 25624, 25273], $folds->pluck('target_race_count')->all());
        $this->assertSame([5, 5, 5], $folds->pluck('predicted_race_count')->all());
        $this->assertSame([25556, 25619, 25268], $folds->pluck('excluded_race_count')->all());
        $run = BacktestRun::query()->sole();
        $this->assertSame(76458, $run->target_race_count);
        $this->assertSame(15, $run->predicted_race_count);
        $this->assertSame(76443, $run->excluded_race_count);
        $this->assertSame(str_repeat('d', 64), $run->parameters['outcome_snapshot_manifest_hash']);
    }

    public function test_exception_marks_fold_and_run_failed_without_deleting_audit_history(): void
    {
        $preflight = Mockery::mock(Bt02FingerprintPreflightService::class);
        $preflight->shouldReceive('run')->once()->andReturn(new Bt02PreflightSummaryDto(56, 56, 56, Bt02SourceManifest::HASH));
        $evaluator = Mockery::mock(Bt02EntrySignalEvaluator::class);
        $evaluator->shouldReceive('evaluate')->once()->andReturnUsing(function (...$arguments): array {
            $arguments[5]([1, 2, 3, 4], 'STRICT', 'IS_WIN', hash('sha256', 'first-base'), hash('sha256', 'first-inc'));

            return ['models' => 12, 'metrics' => 18, 'races' => 4, 'rows' => 28, 'race_ids' => [1, 2, 3, 4], 'manifest_hash' => hash('sha256', 'first')];
        });
        $evaluator->shouldReceive('evaluate')->once()->andReturnUsing(function (...$arguments): array {
            $arguments[5]([3, 4, 5], 'STRICT', 'IS_WIN', hash('sha256', 'second-base'), hash('sha256', 'second-inc'));

            return ['models' => 12, 'metrics' => 18, 'races' => 3, 'rows' => 21, 'race_ids' => [3, 4, 5], 'manifest_hash' => hash('sha256', 'second')];
        });
        $evaluator->shouldReceive('evaluate')->once()->andReturnUsing(function (...$arguments): never {
            $arguments[5]([6, 7], 'STRICT', 'IS_WIN', hash('sha256', 'partial-base'), hash('sha256', 'partial-inc'));
            throw new RuntimeException('model fit failed');
        });

        try {
            $this->service($preflight, $evaluator)->execute();
            $this->fail('Expected BT-02 execution failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('model fit failed', $exception->getMessage());
        }

        $this->assertSame('FAILED', BacktestRun::query()->sole()->status);
        $this->assertNotNull(BacktestRun::query()->sole()->finished_at);
        $this->assertSame('FAILED', BacktestFold::query()->sole()->status);
        $this->assertNotNull(BacktestFold::query()->sole()->finished_at);
        $this->assertSame(25561, BacktestFold::query()->sole()->target_race_count);
        $this->assertSame(7, BacktestFold::query()->sole()->predicted_race_count);
        $this->assertSame(25554, BacktestFold::query()->sole()->excluded_race_count);
        $this->assertSame(76458, BacktestRun::query()->sole()->target_race_count);
        $this->assertSame(7, BacktestRun::query()->sole()->predicted_race_count);
        $this->assertSame(76451, BacktestRun::query()->sole()->excluded_race_count);
        $this->assertNotNull(BacktestFold::query()->sole()->prediction_manifest_hash);
        $this->assertSame(14, BacktestSignalSpec::query()->count());
    }

    public function test_end_fingerprint_drift_marks_run_failed_and_keeps_committed_artifacts(): void
    {
        $baselinePreflight = Mockery::mock(Bt02BaselineFingerprintPreflightService::class);
        $baselinePreflight->shouldReceive('run')->twice();
        $signalPreflight = Mockery::mock(Bt02FingerprintPreflightService::class);
        $signalPreflight->shouldReceive('run')->once()->ordered()->andReturn(new Bt02PreflightSummaryDto(56, 56, 56, Bt02SourceManifest::HASH));
        $signalPreflight->shouldReceive('run')->once()->ordered()->andThrow(new RuntimeException('content changed'));
        $calls = 0;
        $evaluator = Mockery::mock(Bt02EntrySignalEvaluator::class);
        $evaluator->shouldReceive('evaluate')->times(36)->andReturnUsing(function (...$arguments) use (&$calls): array {
            $calls++;
            if ($calls === 1) {
                (new Bt02AuditRepository)->storePairedEvaluationArtifacts(
                    $arguments[0],
                    $arguments[1],
                    $arguments[4],
                    [
                        $this->modelArtifact('BASELINE_MATCHED', ['STAT01_RACE_SCORE']),
                        $this->modelArtifact('INCREMENTAL', ['STAT01_RACE_SCORE', 'SIGNAL']),
                    ],
                    [$this->metricArtifact('AUC'), $this->metricArtifact('LOG_LOSS'), $this->metricArtifact('BRIER')],
                );
            }
            $arguments[5]([1, 2], 'STRICT', 'IS_WIN', hash('sha256', "base-{$calls}"), hash('sha256', "inc-{$calls}"));

            return ['models' => 2, 'metrics' => 3, 'races' => 2, 'rows' => 10, 'race_ids' => [1, 2], 'manifest_hash' => hash('sha256', "signal-{$calls}")];
        });

        try {
            $this->service($signalPreflight, $evaluator, $baselinePreflight)->execute();
            $this->fail('Expected end fingerprint drift.');
        } catch (RuntimeException $exception) {
            $this->assertStringStartsWith('SOURCE_FINGERPRINT_DRIFT_AFTER_EVALUATION:', $exception->getMessage());
        }

        $run = BacktestRun::query()->sole();
        $this->assertSame('FAILED', $run->status);
        $this->assertStringContainsString('SOURCE_FINGERPRINT_DRIFT_AFTER_EVALUATION', (string) $run->error_summary);
        $this->assertSame(2, BacktestModel::query()->count());
        $this->assertSame(3, BacktestSignalMetric::query()->count());
        $this->assertSame(['SUCCEEDED'], BacktestFold::query()->distinct()->pluck('status')->all());
    }

    private function service(
        Bt02FingerprintPreflightService $preflight,
        Bt02EntrySignalEvaluator $evaluator,
        ?Bt02BaselineFingerprintPreflightService $baselinePreflight = null,
        ?Bt02OutcomeContextSnapshotBuilder $snapshotBuilder = null,
    ): Bt02SignalEvaluationService {
        $features = Mockery::mock(BacktestFeatureRepository::class);
        $features->shouldReceive('validateSources')->once()->andReturn([]);
        if ($baselinePreflight === null) {
            $baselinePreflight = Mockery::mock(Bt02BaselineFingerprintPreflightService::class);
            $baselinePreflight->shouldReceive('run')->zeroOrMoreTimes();
        }
        $snapshot = Mockery::mock(Bt02OutcomeContextSnapshot::class);
        $snapshot->shouldReceive('manifestHash')->zeroOrMoreTimes()->andReturn(str_repeat('d', 64));
        $snapshot->shouldReceive('auditParameters')->zeroOrMoreTimes()->andReturn([
            'outcome_snapshot_manifest_hash' => str_repeat('d', 64),
        ]);
        if ($snapshotBuilder === null) {
            $snapshotBuilder = Mockery::mock(Bt02OutcomeContextSnapshotBuilder::class);
            $snapshotBuilder->shouldReceive('build')->once()->andReturn($snapshot);
        }
        $snapshotSession = new Bt02OutcomeContextSnapshotSession;

        return new Bt02SignalEvaluationService(
            $this->app->make(Bt01SourceManifest::class),
            $features,
            new Bt02FoldProvider,
            new FinalHoldoutGuard,
            $baselinePreflight,
            $preflight,
            new Bt02SourceManifest,
            new Bt02SignalRegistry,
            new Bt02AuditRepository,
            $snapshotBuilder,
            $snapshotSession,
            $evaluator,
        );
    }

    /** @param list<string> $features @return array<string, mixed> */
    private function modelArtifact(string $role, array $features): array
    {
        return [
            'model_role' => $role, 'label_code' => 'IS_WIN', 'cohort_code' => 'STRICT',
            'training_from' => '2022-01-01', 'training_to' => '2022-12-31',
            'inner_fit_from' => '2022-01-01', 'inner_fit_to' => '2022-09-30',
            'inner_validation_from' => '2022-10-01', 'inner_validation_to' => '2022-12-31',
            'feature_names' => $features, 'scaler_mean' => array_fill(0, count($features), 0.0),
            'scaler_sd' => array_fill(0, count($features), 1.0), 'lambda_candidates' => [0.1],
            'selected_lambda' => 0.1, 'intercept' => 0.0, 'coefficients' => array_fill(0, count($features), 0.1),
            'objective_version' => 'TEST-v1', 'optimizer_version' => 'TEST-v1',
            'probability_semantics' => 'ENTRY_BINARY_NOT_RACE_NORMALIZED', 'convergence_status' => 'CONVERGED',
            'iterations' => 1, 'final_objective' => 1.0, 'model_hash' => hash('sha256', $role),
            'prediction_manifest_hash' => hash('sha256', $role.'-prediction'),
        ];
    }

    /** @return array<string, mixed> */
    private function metricArtifact(string $code): array
    {
        return [
            'label_code' => 'IS_WIN', 'cohort_code' => 'STRICT', 'metric_code' => $code,
            'baseline_value' => 0.5, 'incremental_value' => 0.4, 'delta_value' => -0.1,
            'ci_lower' => -0.2, 'ci_upper' => 0.0, 'sample_count' => 10, 'race_count' => 2,
            'bootstrap_iterations' => 2000, 'bootstrap_seed' => 20260812,
            'metadata' => ['outcome_manifest_hash' => str_repeat('a', 64)],
        ];
    }
}
