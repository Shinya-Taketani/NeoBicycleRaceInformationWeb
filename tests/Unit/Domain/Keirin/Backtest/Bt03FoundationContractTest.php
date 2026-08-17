<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Backtest;

use App\Domain\Keirin\Backtest\Calculators\Bt03BinEffectCalculator;
use App\Domain\Keirin\Backtest\Calculators\Bt03FixedBinAssigner;
use App\Domain\Keirin\Backtest\Calculators\Bt03StoredModelReplayer;
use App\Domain\Keirin\Backtest\Calculators\EffectBinBuilder;
use App\Domain\Keirin\Backtest\Calculators\RidgeLogisticRegression;
use App\Domain\Keirin\Backtest\Contracts\EffectBinBoundaryProvider;
use App\Domain\Keirin\Backtest\DTO\Bt03SourceBinDto;
use App\Domain\Keirin\Backtest\DTO\Bt03StoredModelDto;
use App\Domain\Keirin\Backtest\Services\Bt03SourceManifest;
use App\Domain\Keirin\Backtest\Support\Bt02ModelArtifactHasher;
use App\Domain\Keirin\Backtest\Support\Bt03EffectHasher;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class Bt03FoundationContractTest extends TestCase
{
    public function test_fixed_numeric_boundaries_keep_lower_exclusive_upper_inclusive_semantics(): void
    {
        $assigner = $this->assigner();
        $bins = [
            $this->bin(101, 1, 'NUMERIC_RANGE', null, 0.0, null, 10),
            $this->bin(102, 2, 'NUMERIC_RANGE', 0.0, null, null, 12),
        ];

        $atBoundary = $assigner->assign($bins, 0.0);
        $aboveBoundary = $assigner->assign($bins, 0.0001);

        $this->assertSame(1, $atBoundary?->binIndex);
        $this->assertSame(101, $atBoundary?->sourceEffectBinId);
        $this->assertSame('TRAINING_BIN', $atBoundary?->binOrigin);
        $this->assertSame(2, $aboveBoundary?->binIndex);
    }

    public function test_category_assignment_keeps_training_bin_and_explicit_unseen_category(): void
    {
        $assigner = $this->assigner();
        $bins = [
            $this->bin(201, 1, 'CATEGORY', null, null, 'S1', 8),
            $this->bin(202, 2, 'CATEGORY', null, null, 'S2', 5),
        ];

        $known = $assigner->assign($bins, 'S2');
        $unseen = $assigner->assign($bins, 'A1');

        $this->assertSame('TRAINING_BIN', $known?->binOrigin);
        $this->assertSame(202, $known?->sourceEffectBinId);
        $this->assertSame(0, $unseen?->binIndex);
        $this->assertSame('UNSEEN_CATEGORY', $unseen?->binOrigin);
        $this->assertNull($unseen?->sourceEffectBinId);
        $this->assertSame(0, $unseen?->trainingSampleCount);
        $this->assertNull($unseen?->categoryValue);
        $this->assertEquals($unseen, $assigner->assign($bins, 'A2'));
    }

    public function test_stored_model_replay_validates_hash_contract_and_does_not_normalize_across_race(): void
    {
        $hasher = new Bt02ModelArtifactHasher;
        $model = $this->model($hasher);
        $replayer = new Bt03StoredModelReplayer(new RidgeLogisticRegression, $hasher);

        $probability = $replayer->probability($model, [
            'STAT01_RACE_SCORE' => 90.0,
            'DELTA_MEAN_RESIDUAL' => 0.4,
        ]);

        $expected = (new RidgeLogisticRegression)->probability(0.1, [0.2, -0.3], [1.0, 2.0]);
        $this->assertEqualsWithDelta($expected, $probability, 1e-15);
        $this->assertGreaterThan(0.0, $probability);
        $this->assertLessThan(1.0, $probability);

        $this->expectException(InvalidArgumentException::class);
        $replayer->assertModel($this->model($hasher, str_repeat('0', 64)));
    }

    public function test_effect_hash_requires_the_complete_contract_and_is_key_order_independent(): void
    {
        $hasher = new Bt03EffectHasher(new Bt02ModelArtifactHasher);
        $artifact = $this->effectArtifact();

        $this->assertSame($hasher->hash($artifact), $hasher->hash(array_reverse($artifact, true)));

        unset($artifact['source_boundaries_hash']);
        $this->expectException(InvalidArgumentException::class);
        $hasher->hash($artifact);
    }

    public function test_effect_hash_changes_with_each_numeric_bin_identity_value(): void
    {
        $hasher = new Bt03EffectHasher(new Bt02ModelArtifactHasher);
        $artifact = $this->effectArtifact();
        $original = $hasher->hash($artifact);

        foreach ([
            'source_backtest_effect_bin_id' => 102,
            'lower_bound' => -1.0,
            'upper_bound' => 0.5,
        ] as $key => $value) {
            $changed = $artifact;
            $changed[$key] = $value;
            $this->assertNotSame($original, $hasher->hash($changed), $key);
        }
    }

    public function test_effect_hash_changes_with_category_value(): void
    {
        $hasher = new Bt03EffectHasher(new Bt02ModelArtifactHasher);
        $artifact = $this->categoryEffectArtifact();
        $changed = $artifact;
        $changed['category_value'] = 'S2';

        $this->assertNotSame($hasher->hash($artifact), $hasher->hash($changed));
    }

    public function test_effect_hash_requires_every_stored_bin_identity_key(): void
    {
        $hasher = new Bt03EffectHasher(new Bt02ModelArtifactHasher);

        foreach (['source_backtest_effect_bin_id', 'lower_bound', 'upper_bound', 'category_value'] as $key) {
            $artifact = $this->effectArtifact();
            unset($artifact[$key]);
            try {
                $hasher->hash($artifact);
                $this->fail("Expected missing {$key} to be rejected.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_unseen_category_null_bin_identity_is_hashable_and_deterministic(): void
    {
        $hasher = new Bt03EffectHasher(new Bt02ModelArtifactHasher);
        $artifact = $this->categoryEffectArtifact();
        $artifact['source_backtest_effect_bin_id'] = null;
        $artifact['bin_index'] = 0;
        $artifact['bin_origin'] = 'UNSEEN_CATEGORY';
        $artifact['category_value'] = null;
        $artifact['training_sample_count'] = 0;

        $this->assertSame($hasher->hash($artifact), $hasher->hash(array_reverse($artifact, true)));
    }

    private function assigner(): Bt03FixedBinAssigner
    {
        $provider = new class implements EffectBinBoundaryProvider
        {
            public function build(iterable $trainingValues): array
            {
                throw new \LogicException('BT-03 must not rebuild boundaries.');
            }
        };

        return new Bt03FixedBinAssigner(new EffectBinBuilder($provider));
    }

    private function bin(int $id, int $index, string $kind, ?float $lower, ?float $upper, ?string $category, int $count): Bt03SourceBinDto
    {
        return new Bt03SourceBinDto($id, $index, $kind, $lower, $upper, $category, $count, str_repeat('a', 64));
    }

    private function model(Bt02ModelArtifactHasher $hasher, ?string $hash = null): Bt03StoredModelDto
    {
        $artifact = [
            'feature_names' => ['STAT01_RACE_SCORE', 'DELTA_MEAN_RESIDUAL'],
            'scaler_mean' => ['STAT01_RACE_SCORE' => 80.0, 'DELTA_MEAN_RESIDUAL' => 0.0],
            'scaler_sd' => ['STAT01_RACE_SCORE' => 10.0, 'DELTA_MEAN_RESIDUAL' => 0.2],
            'selected_lambda' => 0.01,
            'intercept' => 0.1,
            'coefficients' => [0.2, -0.3],
            'objective_version' => Bt03SourceManifest::OBJECTIVE_VERSION,
            'optimizer_version' => Bt03SourceManifest::OPTIMIZER_VERSION,
            'probability_semantics' => Bt03SourceManifest::PROBABILITY_SEMANTICS,
        ];

        return new Bt03StoredModelDto(
            1,
            5,
            8,
            100,
            'WF_2023',
            'STAT-07',
            'DELTA_MEAN_RESIDUAL',
            'STRICT',
            'IS_WIN',
            'INCREMENTAL',
            $artifact['feature_names'],
            $artifact['scaler_mean'],
            $artifact['scaler_sd'],
            [0.0001, 0.001, 0.01, 0.1, 1.0, 10.0, 100.0],
            $artifact['selected_lambda'],
            $artifact['intercept'],
            $artifact['coefficients'],
            $artifact['objective_version'],
            $artifact['optimizer_version'],
            $artifact['probability_semantics'],
            'CONVERGED_GRADIENT',
            $hash ?? $hasher->hash($artifact),
        );
    }

    /** @return array<string, mixed> */
    private function effectArtifact(): array
    {
        return [
            'source_bt02_run_id' => 5,
            'source_bt02_run_uuid' => Bt03SourceManifest::SOURCE_BT02_RUN_UUID,
            'source_fold_id' => 8,
            'source_signal_spec_id' => 100,
            'source_baseline_model_hash' => str_repeat('a', 64),
            'source_incremental_model_hash' => str_repeat('b', 64),
            'source_boundaries_hash' => str_repeat('c', 64),
            'source_backtest_effect_bin_id' => 101,
            'cohort_code' => 'STRICT',
            'label_code' => 'IS_WIN',
            'bin_index' => 1,
            'bin_origin' => 'TRAINING_BIN',
            'bin_kind' => 'NUMERIC_RANGE',
            'lower_bound' => null,
            'upper_bound' => 0.0,
            'category_value' => null,
            'training_sample_count' => 100,
            'evaluation_status' => 'OBSERVED',
            'evaluation_sample_count' => 20,
            'evaluation_race_count' => 4,
            'positive_count' => 3,
            'observed_rate' => 0.15,
            'observed_rate_ci_lower' => 0.05,
            'observed_rate_ci_upper' => 0.25,
            'baseline_mean_probability' => 0.12,
            'incremental_mean_probability' => 0.14,
            'baseline_residual_mean' => 0.03,
            'baseline_residual_ci_lower' => -0.01,
            'baseline_residual_ci_upper' => 0.07,
            'incremental_residual_mean' => 0.01,
            'incremental_residual_ci_lower' => -0.03,
            'incremental_residual_ci_upper' => 0.05,
            'probability_shift_mean' => 0.02,
            'probability_shift_ci_lower' => 0.0,
            'probability_shift_ci_upper' => 0.04,
            'log_loss_delta' => -0.01,
            'log_loss_delta_ci_lower' => -0.02,
            'log_loss_delta_ci_upper' => 0.0,
            'brier_delta' => -0.005,
            'brier_delta_ci_lower' => -0.01,
            'brier_delta_ci_upper' => 0.0,
            'bootstrap_iterations' => 2000,
            'bootstrap_seed' => 20260812,
            'calculation_version' => Bt03BinEffectCalculator::CALCULATION_VERSION,
        ];
    }

    /** @return array<string, mixed> */
    private function categoryEffectArtifact(): array
    {
        return array_replace($this->effectArtifact(), [
            'source_backtest_effect_bin_id' => 201,
            'bin_kind' => 'CATEGORY',
            'lower_bound' => null,
            'upper_bound' => null,
            'category_value' => 'S1',
        ]);
    }
}
