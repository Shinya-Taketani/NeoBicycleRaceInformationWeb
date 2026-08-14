<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Backtest;

use App\Domain\Keirin\Backtest\Calculators\EffectBinBuilder;
use App\Domain\Keirin\Backtest\Calculators\InMemoryEffectBinBoundaryProvider;
use App\Domain\Keirin\Backtest\Calculators\PairedRaceClusterMetricEvaluator;
use App\Domain\Keirin\Backtest\Calculators\RaceClusterBootstrap;
use App\Domain\Keirin\Backtest\Calculators\RidgeLogisticRegression;
use App\Domain\Keirin\Backtest\Calculators\TemporalLambdaSelector;
use App\Domain\Keirin\Backtest\Calculators\TrainingStandardizer;
use App\Domain\Keirin\Backtest\Calculators\Type7Quantile;
use App\Domain\Keirin\Backtest\Contracts\Bt02EvaluationDataset;
use App\Domain\Keirin\Backtest\DTO\Bt02BinaryLabelsDto;
use App\Domain\Keirin\Backtest\DTO\Bt02EvaluationRowDto;
use App\Domain\Keirin\Backtest\Enums\Bt02SignalCohort;
use App\Domain\Keirin\Backtest\Repositories\Bt02AuditRepository;
use App\Domain\Keirin\Backtest\Services\Bt02EntrySignalEvaluator;
use App\Domain\Keirin\Backtest\Services\Bt02FoldProvider;
use App\Domain\Keirin\Backtest\Services\Bt02SignalRegistry;
use App\Domain\Keirin\Backtest\Support\Bt02ModelArtifactHasher;
use App\Domain\Keirin\Backtest\Support\Bt02TrainingSpoolFactory;
use App\Models\BacktestFold;
use App\Models\BacktestRun;
use App\Models\BacktestSignalSpec;
use DateTimeImmutable;
use Mockery;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class Bt02EntrySignalEvaluatorTest extends TestCase
{
    public function test_paired_walk_forward_evaluation_persists_fixed_models_metrics_and_cleans_spools(): void
    {
        $rows = [
            new Bt02EvaluationRowDto(1, 11, -2.0, -1.0, new Bt02BinaryLabelsDto(true, true, true)),
            new Bt02EvaluationRowDto(1, 12, -1.0, 0.5, new Bt02BinaryLabelsDto(false, true, true)),
            new Bt02EvaluationRowDto(2, 21, 0.0, -0.5, new Bt02BinaryLabelsDto(false, false, true)),
            new Bt02EvaluationRowDto(2, 22, 1.0, 1.5, new Bt02BinaryLabelsDto(false, false, false)),
            new Bt02EvaluationRowDto(3, 31, 2.0, 1.0, new Bt02BinaryLabelsDto(false, false, false)),
        ];
        $dataset = new class($rows) implements Bt02EvaluationDataset
        {
            /** @var list<array{string, string, string, string}> */
            public array $calls = [];

            public function __construct(private readonly array $fixture) {}

            public function rows(DateTimeImmutable $from, DateTimeImmutable $to, string $statCode, Bt02SignalCohort $cohort): iterable
            {
                $this->calls[] = [$from->format('Y-m-d'), $to->format('Y-m-d'), $statCode, $cohort->value];
                yield from $this->fixture;
            }
        };
        $storedModels = [];
        $storedMetrics = [];
        $committedLabels = 0;
        $audit = Mockery::mock(Bt02AuditRepository::class);
        $audit->shouldReceive('storeEffectBins')->twice();
        $audit->shouldReceive('storePairedEvaluationArtifacts')->times(6)->andReturnUsing(function (...$arguments) use (&$storedModels, &$storedMetrics, &$committedLabels): void {
            array_push($storedModels, ...$arguments[3]);
            array_push($storedMetrics, ...$arguments[4]);
            $committedLabels++;
        });
        $temporaryDirectory = sys_get_temp_dir().'/bt02-evaluator-'.bin2hex(random_bytes(6));
        mkdir($temporaryDirectory, 0700, true);
        $quantile = new Type7Quantile;
        $evaluator = new Bt02EntrySignalEvaluator(
            $dataset,
            new TrainingStandardizer,
            new RidgeLogisticRegression,
            new TemporalLambdaSelector,
            new EffectBinBuilder(new InMemoryEffectBinBoundaryProvider($quantile)),
            new Bt02TrainingSpoolFactory($temporaryDirectory),
            new Bt02ModelArtifactHasher,
            $audit,
            new PairedRaceClusterMetricEvaluator(new RaceClusterBootstrap, $quantile, $temporaryDirectory),
            8,
            $temporaryDirectory,
        );
        $run = new BacktestRun(['id' => 1]);
        $run->id = 1;
        $fold = new BacktestFold(['id' => 1, 'backtest_run_id' => 1]);
        $fold->id = 1;
        $spec = new BacktestSignalSpec(['id' => 1, 'backtest_run_id' => 1]);
        $spec->id = 1;
        $definition = (new Bt02FoldProvider)->folds()[0];
        $signal = (new Bt02SignalRegistry)->get('STAT-07');

        try {
            $progressCalls = 0;
            $result = $evaluator->evaluate(
                $run,
                $fold,
                $definition,
                $signal,
                $spec,
                function () use (&$progressCalls, &$committedLabels): void {
                    $progressCalls++;
                    $this->assertSame($committedLabels, $progressCalls, 'Progress must follow the corresponding atomic commit.');
                },
            );

            $this->assertSame(12, $result['models']);
            $this->assertSame(18, $result['metrics']);
            $this->assertSame(3, $result['races']);
            $this->assertSame(5, $result['rows']);
            $this->assertSame(64, strlen($result['manifest_hash']));
            $this->assertSame(6, $progressCalls);
            $this->assertCount(6, array_filter($storedModels, fn (array $model): bool => $model['model_role'] === 'BASELINE_MATCHED'));
            $this->assertCount(6, array_filter($storedModels, fn (array $model): bool => $model['model_role'] === 'INCREMENTAL'));
            $this->assertSame([1], array_values(array_unique(array_map(fn (array $model): int => count($model['feature_names']), array_filter($storedModels, fn (array $model): bool => $model['model_role'] === 'BASELINE_MATCHED')))));
            $this->assertSame([2], array_values(array_unique(array_map(fn (array $model): int => count($model['feature_names']), array_filter($storedModels, fn (array $model): bool => $model['model_role'] === 'INCREMENTAL')))));
            $this->assertSame([RidgeLogisticRegression::OPTIMIZER_VERSION], array_values(array_unique(array_column($storedModels, 'optimizer_version'))));
            $modelHasher = new Bt02ModelArtifactHasher;
            foreach ($storedModels as $model) {
                $hashPayload = [
                    'feature_names' => $model['feature_names'],
                    'scaler_mean' => $model['scaler_mean'],
                    'scaler_sd' => $model['scaler_sd'],
                    'selected_lambda' => $model['selected_lambda'],
                    'intercept' => $model['intercept'],
                    'coefficients' => $model['coefficients'],
                    'objective_version' => $model['objective_version'],
                    'optimizer_version' => RidgeLogisticRegression::OPTIMIZER_VERSION,
                    'probability_semantics' => $model['probability_semantics'],
                ];
                $this->assertSame($model['model_hash'], $modelHasher->hash($hashPayload));
                $this->assertNotSame($model['model_hash'], $modelHasher->hash([
                    ...$hashPayload,
                    'optimizer_version' => 'DAMPED-NEWTON-CHOLESKY-v1',
                ]));
            }
            $this->assertSame([8], array_values(array_unique(array_column($storedMetrics, 'bootstrap_iterations'))));
            $this->assertSame([RaceClusterBootstrap::SEED], array_values(array_unique(array_column($storedMetrics, 'bootstrap_seed'))));
            $this->assertSame([5], array_values(array_unique(array_column($storedMetrics, 'sample_count'))));
            $this->assertSame(['SHARED_BY_AUC_LOG_LOSS_BRIER'], array_values(array_unique(array_column(array_column($storedMetrics, 'metadata'), 'bootstrap_replicate_contract'))));
            foreach (array_chunk($storedMetrics, 3) as $metricGroup) {
                $this->assertSame(1, count(array_unique(array_column(array_column($metricGroup, 'metadata'), 'outcome_manifest_hash'))));
            }
            $this->assertCount(8, $dataset->calls, 'Each cohort must scan each fixed period once, independent of label.');
            $this->assertSame([], array_values(array_filter($dataset->calls, fn (array $call): bool => str_starts_with($call[0], '2026') || str_starts_with($call[1], '2026'))));
            $this->assertSame([], array_values(array_diff(scandir($temporaryDirectory), ['.', '..'])));
        } finally {
            @rmdir($temporaryDirectory);
        }
    }

    public function test_failed_atomic_label_save_does_not_advance_progress_or_manifest_callback(): void
    {
        $rows = [
            new Bt02EvaluationRowDto(1, 11, -2.0, -1.0, new Bt02BinaryLabelsDto(true, true, true)),
            new Bt02EvaluationRowDto(1, 12, -1.0, 0.5, new Bt02BinaryLabelsDto(false, true, true)),
            new Bt02EvaluationRowDto(2, 21, 0.0, -0.5, new Bt02BinaryLabelsDto(false, false, true)),
            new Bt02EvaluationRowDto(2, 22, 1.0, 1.5, new Bt02BinaryLabelsDto(false, false, false)),
            new Bt02EvaluationRowDto(3, 31, 2.0, 1.0, new Bt02BinaryLabelsDto(false, false, false)),
        ];
        $dataset = new class($rows) implements Bt02EvaluationDataset
        {
            public function __construct(private readonly array $fixture) {}

            public function rows(DateTimeImmutable $from, DateTimeImmutable $to, string $statCode, Bt02SignalCohort $cohort): iterable
            {
                yield from $this->fixture;
            }
        };
        $atomicAttempts = 0;
        $audit = Mockery::mock(Bt02AuditRepository::class);
        $audit->shouldReceive('storeEffectBins')->once();
        $audit->shouldReceive('storePairedEvaluationArtifacts')->times(3)->andReturnUsing(function () use (&$atomicAttempts): void {
            $atomicAttempts++;
            if ($atomicAttempts === 3) {
                throw new RuntimeException('late metric insert failed');
            }
        });
        $directory = sys_get_temp_dir().'/bt02-evaluator-failure-'.bin2hex(random_bytes(6));
        mkdir($directory, 0700, true);
        $quantile = new Type7Quantile;
        $evaluator = new Bt02EntrySignalEvaluator(
            $dataset,
            new TrainingStandardizer,
            new RidgeLogisticRegression,
            new TemporalLambdaSelector,
            new EffectBinBuilder(new InMemoryEffectBinBoundaryProvider($quantile)),
            new Bt02TrainingSpoolFactory($directory),
            new Bt02ModelArtifactHasher,
            $audit,
            new PairedRaceClusterMetricEvaluator(new RaceClusterBootstrap, $quantile, $directory),
            4,
            $directory,
        );
        $run = new BacktestRun(['id' => 1]);
        $run->id = 1;
        $fold = new BacktestFold(['id' => 1, 'backtest_run_id' => 1]);
        $fold->id = 1;
        $spec = new BacktestSignalSpec(['id' => 1, 'backtest_run_id' => 1]);
        $spec->id = 1;
        $progress = [];

        try {
            $evaluator->evaluate(
                $run,
                $fold,
                (new Bt02FoldProvider)->folds()[0],
                (new Bt02SignalRegistry)->get('STAT-07'),
                $spec,
                function (array $raceIds, string $cohort, string $label) use (&$progress): void {
                    $progress[] = [$raceIds, $cohort, $label];
                },
            );
            $this->fail('Expected the third atomic label save to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('late metric insert failed', $exception->getMessage());
        } finally {
            $this->assertSame(3, $atomicAttempts);
            $this->assertSame(['IS_WIN', 'IS_TOP2'], array_column($progress, 2));
            $this->assertSame([], array_values(array_diff(scandir($directory), ['.', '..'])));
            @rmdir($directory);
        }
    }
}
