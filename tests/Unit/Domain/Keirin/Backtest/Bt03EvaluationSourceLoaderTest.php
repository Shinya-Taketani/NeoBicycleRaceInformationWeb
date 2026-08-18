<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Backtest;

use App\Domain\Keirin\Backtest\Calculators\Bt03StoredModelReplayer;
use App\Domain\Keirin\Backtest\Calculators\RidgeLogisticRegression;
use App\Domain\Keirin\Backtest\Repositories\Bt03EvaluationSourceRepository;
use App\Domain\Keirin\Backtest\Services\Bt03EvaluationSourceLoader;
use App\Domain\Keirin\Backtest\Services\Bt03SourceManifest;
use App\Domain\Keirin\Backtest\Services\FinalHoldoutGuard;
use App\Domain\Keirin\Backtest\Support\Bt02ModelArtifactHasher;
use DomainException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class Bt03EvaluationSourceLoaderTest extends TestCase
{
    public function test_it_loads_exact_model_pairs_and_canonical_fixed_bins(): void
    {
        $source = $this->loader()->load('WF_2023', 'STAT-07', 'STRICT');

        $this->assertSame(5, $source->sourceRunId);
        $this->assertSame(81, $source->sourceFoldId);
        $this->assertSame(701, $source->sourceSignalSpecId);
        $this->assertSame(['IS_WIN', 'IS_TOP2', 'IS_TOP3'], array_keys($source->modelPairs));
        foreach ($source->modelPairs as $label => $pair) {
            $this->assertSame($label, $pair->baseline->labelCode);
            $this->assertSame('BASELINE_MATCHED', $pair->baseline->modelRole);
            $this->assertSame('INCREMENTAL', $pair->incremental->modelRole);
        }
        $this->assertSame([1, 2], array_column($source->bins, 'index'));
        $this->assertSame([9001, 9002], array_column($source->bins, 'sourceEffectBinId'));
        $this->assertSame(str_repeat('b', 64), $source->bins[0]->boundariesHash);
    }

    public function test_missing_or_duplicate_model_pair_fails_closed(): void
    {
        $models = $this->modelRows();
        array_pop($models);

        try {
            $this->loader(models: $models)->load('WF_2023', 'STAT-07', 'STRICT');
            $this->fail('A missing stored model must be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('exactly one stored model pair', $exception->getMessage());
        }

        $models = $this->modelRows();
        $models[] = clone $models[0];
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ownership or uniqueness');
        $this->loader(models: $models)->load('WF_2023', 'STAT-07', 'STRICT');
    }

    public function test_wrong_source_model_ownership_is_rejected(): void
    {
        $models = $this->modelRows();
        $models[0]->backtest_run_id = 4;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ownership or uniqueness');
        $this->loader(models: $models)->load('WF_2023', 'STAT-07', 'STRICT');
    }

    public function test_noncanonical_or_invalid_fixed_bins_are_rejected(): void
    {
        $bins = array_reverse($this->binRows());

        try {
            $this->loader(bins: $bins)->load('WF_2023', 'STAT-07', 'STRICT');
            $this->fail('Out-of-order source bins must be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('fixed source bin contract', $exception->getMessage());
        }

        $bins = $this->binRows();
        $bins[1]->training_sample_count = 0;
        $this->expectException(RuntimeException::class);
        $this->loader(bins: $bins)->load('WF_2023', 'STAT-07', 'STRICT');
    }

    public function test_2026_evaluation_period_is_rejected(): void
    {
        $fold = $this->fold();
        $fold->evaluation_from = '2026-01-01';
        $fold->evaluation_to = '2026-12-31';

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('blocks evaluation after 2025-12-31');
        $this->loader(folds: [$fold])->load('WF_2023', 'STAT-07', 'STRICT');
    }

    /** @param list<object>|null $folds @param list<object>|null $models @param list<object>|null $bins */
    private function loader(?array $folds = null, ?array $models = null, ?array $bins = null): Bt03EvaluationSourceLoader
    {
        $repository = new class($folds ?? [$this->fold()], [$this->spec()], $models ?? $this->modelRows(), $bins ?? $this->binRows()) extends Bt03EvaluationSourceRepository
        {
            /** @param list<object> $folds @param list<object> $specs @param list<object> $models @param list<object> $bins */
            public function __construct(
                private readonly array $foldRows,
                private readonly array $specRows,
                private readonly array $modelRows,
                private readonly array $binRows,
            ) {}

            public function folds(string $foldCode): array
            {
                return $this->foldRows;
            }

            public function signalSpecs(string $statCode): array
            {
                return $this->specRows;
            }

            public function models(int $foldId, int $signalSpecId, string $cohortCode): array
            {
                return $this->modelRows;
            }

            public function bins(int $foldId, int $signalSpecId, string $cohortCode): array
            {
                return $this->binRows;
            }
        };
        $hasher = new Bt02ModelArtifactHasher;

        return new Bt03EvaluationSourceLoader(
            $repository,
            new Bt03StoredModelReplayer(new RidgeLogisticRegression, $hasher),
            new FinalHoldoutGuard,
        );
    }

    private function fold(): object
    {
        return (object) [
            'id' => 81,
            'backtest_run_id' => 5,
            'fold_code' => 'WF_2023',
            'status' => 'SUCCEEDED',
            'sequence' => 1,
            'train_from' => '2022-01-01',
            'train_to' => '2022-12-31',
            'evaluation_from' => '2023-01-01',
            'evaluation_to' => '2023-12-31',
        ];
    }

    private function spec(): object
    {
        return (object) [
            'id' => 701,
            'backtest_run_id' => 5,
            'stat_code' => 'STAT-07',
            'analysis_role' => 'ENTRY_INCREMENTAL',
            'primary_feature_code' => 'STAT07_WIN_RATE',
        ];
    }

    /** @return list<object> */
    private function modelRows(): array
    {
        $rows = [];
        $id = 1000;
        foreach (['IS_WIN', 'IS_TOP2', 'IS_TOP3'] as $label) {
            foreach (['BASELINE_MATCHED', 'INCREMENTAL'] as $role) {
                $features = $role === 'BASELINE_MATCHED'
                    ? ['STAT01_RACE_SCORE']
                    : ['STAT01_RACE_SCORE', 'STAT07_WIN_RATE'];
                $artifact = [
                    'feature_names' => $features,
                    'scaler_mean' => array_fill_keys($features, 0.0),
                    'scaler_sd' => array_fill_keys($features, 1.0),
                    'selected_lambda' => 0.1,
                    'intercept' => 0.0,
                    'coefficients' => array_fill(0, count($features), 0.1),
                    'objective_version' => Bt03SourceManifest::OBJECTIVE_VERSION,
                    'optimizer_version' => Bt03SourceManifest::OPTIMIZER_VERSION,
                    'probability_semantics' => Bt03SourceManifest::PROBABILITY_SEMANTICS,
                ];
                $rows[] = (object) [
                    'id' => ++$id,
                    'backtest_run_id' => 5,
                    'backtest_fold_id' => 81,
                    'backtest_signal_spec_id' => 701,
                    'cohort_code' => 'STRICT',
                    'label_code' => $label,
                    'model_role' => $role,
                    'feature_names' => json_encode($features, JSON_THROW_ON_ERROR),
                    'scaler_mean' => json_encode($artifact['scaler_mean'], JSON_THROW_ON_ERROR),
                    'scaler_sd' => json_encode($artifact['scaler_sd'], JSON_THROW_ON_ERROR),
                    'lambda_candidates' => json_encode([0.1], JSON_THROW_ON_ERROR),
                    'selected_lambda' => 0.1,
                    'intercept' => 0.0,
                    'coefficients' => json_encode($artifact['coefficients'], JSON_THROW_ON_ERROR),
                    'objective_version' => Bt03SourceManifest::OBJECTIVE_VERSION,
                    'optimizer_version' => Bt03SourceManifest::OPTIMIZER_VERSION,
                    'probability_semantics' => Bt03SourceManifest::PROBABILITY_SEMANTICS,
                    'convergence_status' => 'CONVERGED_GRADIENT',
                    'model_hash' => (new Bt02ModelArtifactHasher)->hash($artifact),
                ];
            }
        }

        return $rows;
    }

    /** @return list<object> */
    private function binRows(): array
    {
        return [
            (object) $this->binRow(9001, 1, null, 0.0, 400),
            (object) $this->binRow(9002, 2, 0.0, null, 500),
        ];
    }

    /** @return array<string, mixed> */
    private function binRow(int $id, int $index, ?float $lower, ?float $upper, int $count): array
    {
        return [
            'id' => $id,
            'backtest_run_id' => 5,
            'backtest_fold_id' => 81,
            'backtest_signal_spec_id' => 701,
            'cohort_code' => 'STRICT',
            'bin_index' => $index,
            'bin_kind' => 'NUMERIC_RANGE',
            'lower_bound' => $lower,
            'upper_bound' => $upper,
            'category_value' => null,
            'training_sample_count' => $count,
            'boundaries_hash' => str_repeat('b', 64),
        ];
    }
}
