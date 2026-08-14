<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Backtest;

use App\Domain\Keirin\Backtest\Calculators\BinaryMetricCalculator;
use App\Domain\Keirin\Backtest\Calculators\Bt02LabelDefinition;
use App\Domain\Keirin\Backtest\Calculators\Bt02RaceCompletenessEvaluator;
use App\Domain\Keirin\Backtest\Calculators\Bt02SignalEligibilityEvaluator;
use App\Domain\Keirin\Backtest\Calculators\EffectBinBuilder;
use App\Domain\Keirin\Backtest\Calculators\InMemoryEffectBinBoundaryProvider;
use App\Domain\Keirin\Backtest\Calculators\RaceClusterBootstrap;
use App\Domain\Keirin\Backtest\Calculators\RidgeLogisticRegression;
use App\Domain\Keirin\Backtest\Calculators\TemporalLambdaSelector;
use App\Domain\Keirin\Backtest\Calculators\TrainingStandardizer;
use App\Domain\Keirin\Backtest\Calculators\Type7Quantile;
use App\Domain\Keirin\Backtest\DTO\Bt02SignalFeatureDto;
use App\Domain\Keirin\Backtest\DTO\Bt02SourceManifestEntryDto;
use App\Domain\Keirin\Backtest\DTO\FoldDefinitionDto;
use App\Domain\Keirin\Backtest\DTO\LogisticTrainingRowDto;
use App\Domain\Keirin\Backtest\Enums\Bt02AnalysisRole;
use App\Domain\Keirin\Backtest\Enums\Bt02ConvergenceStatus;
use App\Domain\Keirin\Backtest\Enums\Bt02SignalCohort;
use App\Domain\Keirin\Backtest\Repositories\Bt02SourceVerifier;
use App\Domain\Keirin\Backtest\Services\Bt02FoldProvider;
use App\Domain\Keirin\Backtest\Services\Bt02SignalRegistry;
use App\Domain\Keirin\Backtest\Services\Bt02SourceManifest;
use App\Domain\Keirin\Backtest\Services\FinalHoldoutGuard;
use App\Domain\Keirin\Backtest\Support\Bt02ModelArtifactHasher;
use App\Domain\Keirin\Backtest\Support\CallbackLogisticTrainingRowSource;
use DomainException;
use Generator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class Bt02FoundationTest extends TestCase
{
    public function test_manifest_is_the_fixed_56_run_allowlist(): void
    {
        $manifest = new Bt02SourceManifest;
        $entries = $manifest->entries();

        $this->assertCount(56, $entries);
        $this->assertCount(56, array_unique(array_map(fn ($entry): int => $entry->featureRunId, $entries)));
        $this->assertSame([2022, 2023, 2024, 2025], array_values(array_unique(array_map(fn ($entry): int => $entry->year, $entries))));
        $this->assertSame(Bt02SourceManifest::HASH, $manifest->hash());
        $this->assertSame('92aa8439775101c4f9d190d829b8a0f3e3702fd8646101b66a42b68babb79e6d', $manifest->hash());
        $this->assertSame($manifest->hash(), $manifest->computedHash());
        $this->assertSame([], array_values(array_filter($entries, fn ($entry): bool => $entry->year >= 2026)));
    }

    public function test_2023_stat08_uses_the_corrected_canonical_fingerprints(): void
    {
        $entry = (new Bt02SourceManifest)->for(2023, 'STAT-08');

        $this->assertSame(51, $entry->featureRunId);
        $this->assertSame('5ad91ffe93f511a7626d967dac047f39a2d4308d474ef6e75af455d7efa6c9c0', $entry->sourceFingerprintSha256);
        $this->assertSame('fa08fd538bb4f58534e80257bf24d876619ce8cc44dd7dd230c23a25b69991c8', $entry->contentFingerprintSha256);
    }

    public function test_manifest_has_no_latest_fallback_api(): void
    {
        $reflection = new \ReflectionClass(Bt02SourceManifest::class);
        $methods = array_map(fn (\ReflectionMethod $method): string => strtolower($method->getName()), $reflection->getMethods());

        $this->assertNotContains('latest', $methods);
        $this->assertNotContains('newest', $methods);
    }

    public function test_source_verifier_rejects_any_manifest_other_than_the_fixed_56_runs(): void
    {
        $this->expectException(RuntimeException::class);
        (new Bt02SourceVerifier)->verify(array_slice((new Bt02SourceManifest)->entries(), 0, 55));
    }

    public function test_source_verifier_rejects_modified_56_entry_manifests_before_database_access(): void
    {
        foreach ([
            ['featureRunUuid' => '00000000-0000-4000-8000-000000000999'],
            ['sourceFingerprintSha256' => str_repeat('a', 64)],
            ['contentFingerprintSha256' => str_repeat('b', 64)],
            ['processedRaceCount' => 1],
            ['targetEntryCount' => 1],
        ] as $changes) {
            $entries = (new Bt02SourceManifest)->entries();
            $entries[0] = $this->changedManifestEntry($entries[0], $changes);
            $this->assertCallbackThrows(
                fn () => (new Bt02SourceVerifier)->verify(array_reverse($entries)),
                RuntimeException::class,
            );
        }
    }

    public function test_folds_fix_inner_temporal_validation_and_holdout(): void
    {
        $folds = (new Bt02FoldProvider)->folds();

        $this->assertSame(['WF_2023', 'WF_2024', 'WF_2025'], array_map(fn ($fold): string => $fold->code, $folds));
        $this->assertSame('2022-09-30', $folds[0]->innerFitTo->format('Y-m-d'));
        $this->assertSame('2022-10-01', $folds[0]->innerValidationFrom->format('Y-m-d'));
        $this->assertSame('2025-12-31', $folds[2]->evaluationTo->format('Y-m-d'));
        foreach ($folds as $fold) {
            (new FinalHoldoutGuard)->assertAllowed($fold->holdoutDefinition());
            $this->assertLessThan($fold->evaluationFrom, $fold->innerValidationTo);
        }
    }

    public function test_final_holdout_still_rejects_2026(): void
    {
        $fold = (new Bt02FoldProvider)->folds()[2];
        $forbidden = new FoldDefinitionDto(
            'FORBIDDEN', 4, $fold->trainingFrom, $fold->trainingTo, new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-12-31'),
        );
        $this->expectException(DomainException::class);
        (new FinalHoldoutGuard)->assertAllowed($forbidden);
    }

    public function test_primary_registry_fixes_stat10_and_stat26_paths(): void
    {
        $registry = new Bt02SignalRegistry;

        $this->assertSame('features.SUMMARY.mean_residual_3_minus_10', $registry->get('STAT-10')->primaryFeaturePath);
        $this->assertSame('features.DAY_WINDOWS.30.started_race_count', $registry->get('STAT-26')->primaryFeaturePath);
        $this->assertSame(1.25, $registry->primary('STAT-10', ['SUMMARY' => ['mean_residual_3_minus_10' => 1.25]]));
        $this->assertSame(4.0, $registry->primary('STAT-26', ['DAY_WINDOWS' => ['30' => ['started_race_count' => 4]]]));
    }

    public function test_stat31_uses_stored_aggregate_and_checks_weighted_integrity(): void
    {
        $features = ['STAGE_EXPERIENCE' => [
            'SEMIFINAL' => ['residual_sample_count' => 2, 'mean_score_expectation_residual' => 1.0],
            'FINAL' => ['residual_sample_count' => 1, 'mean_score_expectation_residual' => -0.5],
            'SEMIFINAL_OR_FINAL' => ['mean_score_expectation_residual' => 0.5],
        ]];
        $this->assertSame(0.5, (new Bt02SignalRegistry)->primary('STAT-31', $features));

        $features['STAGE_EXPERIENCE']['SEMIFINAL_OR_FINAL']['mean_score_expectation_residual'] = 0.4;
        $this->expectException(RuntimeException::class);
        (new Bt02SignalRegistry)->primary('STAT-31', $features);
    }

    public function test_stat42_uses_sample_weighted_pair_residual_and_never_zero_fills(): void
    {
        $registry = new Bt02SignalRegistry;
        $features = ['HEAD_TO_HEAD_BY_COENTRANT' => [
            ['DIRECT_HISTORY' => ['relative_expectation_residual_sample_count' => 2, 'mean_relative_expectation_residual' => 1.0]],
            ['DIRECT_HISTORY' => ['relative_expectation_residual_sample_count' => 1, 'mean_relative_expectation_residual' => -0.5]],
            ['DIRECT_HISTORY' => ['relative_expectation_residual_sample_count' => 0, 'mean_relative_expectation_residual' => null]],
        ]];
        $this->assertSame(0.5, $registry->primary('STAT-42', $features));
        $this->assertNull($registry->primary('STAT-42', ['HEAD_TO_HEAD_BY_COENTRANT' => [
            ['DIRECT_HISTORY' => ['relative_expectation_residual_sample_count' => 0, 'mean_relative_expectation_residual' => null]],
        ]]));
    }

    public function test_stat42_positive_count_without_mean_is_a_contract_error(): void
    {
        $this->expectException(RuntimeException::class);
        (new Bt02SignalRegistry)->primary('STAT-42', ['HEAD_TO_HEAD_BY_COENTRANT' => [
            ['DIRECT_HISTORY' => ['relative_expectation_residual_sample_count' => 1, 'mean_relative_expectation_residual' => null]],
        ]]);
    }

    public function test_strict_and_operational_eligibility_are_fail_closed(): void
    {
        $evaluator = new Bt02SignalEligibilityEvaluator(new Bt02SignalRegistry);
        $full = $this->signal(1, 'FULL', []);
        $allowed = $this->signal(2, 'DEGRADED', ['IN_MEETING_RESULT_CONFIRMATION_NOT_RECONSTRUCTED']);
        $mixed = $this->signal(3, 'DEGRADED', ['IN_MEETING_RESULT_CONFIRMATION_NOT_RECONSTRUCTED', 'OTHER']);

        $this->assertTrue($evaluator->eligible('STAT-10', Bt02SignalCohort::Strict, $full));
        $this->assertFalse($evaluator->eligible('STAT-10', Bt02SignalCohort::Strict, $allowed));
        $this->assertTrue($evaluator->eligible('STAT-10', Bt02SignalCohort::Operational, $allowed));
        $this->assertFalse($evaluator->eligible('STAT-10', Bt02SignalCohort::Operational, $mixed));
        $this->assertFalse($evaluator->eligible('STAT-10', Bt02SignalCohort::Operational, $this->signal(4, 'FULL', [], null)));
    }

    public function test_stat26_degraded_and_stat33_operational_are_forbidden(): void
    {
        $evaluator = new Bt02SignalEligibilityEvaluator(new Bt02SignalRegistry);

        $this->assertFalse($evaluator->eligible('STAT-26', Bt02SignalCohort::Operational, $this->signal(1, 'DEGRADED', ['IN_MEETING_RESULT_CONFIRMATION_NOT_RECONSTRUCTED'])));
        $this->assertFalse($evaluator->eligible('STAT-33', Bt02SignalCohort::Operational, $this->signal(1, 'FULL', [])));
        $this->assertSame(Bt02AnalysisRole::RaceStratifier, (new Bt02SignalRegistry)->get('STAT-41')->analysisRole);
        $this->assertFalse((new Bt02SignalRegistry)->get('STAT-41')->permitsOperationalUse());
    }

    public function test_paired_set_is_identical_for_baseline_and_incremental(): void
    {
        $set = (new Bt02SignalEligibilityEvaluator(new Bt02SignalRegistry))->matchedSet(
            'STAT-07', Bt02SignalCohort::Strict, [1, 2, 3, 4], [$this->signal(2, 'FULL', []), $this->signal(4, 'FULL', []), $this->signal(9, 'FULL', [])],
        );

        $this->assertSame([2, 4], $set->raceEntryIds);
        $this->assertSame($set->baselineRaceEntryIds(), $set->incrementalRaceEntryIds());
        $this->assertNotContains(1, $set->baselineRaceEntryIds());
    }

    public function test_race_ranking_metric_requires_every_official_entrant(): void
    {
        $evaluator = new Bt02RaceCompletenessEvaluator;

        $this->assertTrue($evaluator->complete([1, 2, 3], [3, 1, 2]));
        $this->assertFalse($evaluator->complete([1, 2, 3], [1, 2]));
        $this->assertFalse($evaluator->complete([1, 2, 3], [1, 2, 2]));
    }

    public function test_label_contract_keeps_abnormal_results_false(): void
    {
        $labels = new Bt02LabelDefinition;

        $this->assertTrue($labels->labels('TIED', 1)->isWin);
        $this->assertTrue($labels->labels('FINISHED', 2)->isTop2);
        $this->assertTrue($labels->labels('FINISHED', 3)->isTop3);
        $this->assertFalse($labels->labels('DISQUALIFIED', null)->isTop3);
    }

    public function test_standardizer_is_training_only_and_handles_zero_variance(): void
    {
        $standardizer = new TrainingStandardizer;
        $consumed = 0;
        $rows = (function () use (&$consumed): Generator {
            foreach ([['a' => 1, 'constant' => 5], ['a' => 3, 'constant' => 5]] as $row) {
                $consumed++;
                yield $row;
            }
        })();
        $model = $standardizer->fit($rows);

        $this->assertSame(2, $consumed);
        $this->assertSame(2.0, $model->means['a']);
        $this->assertSame(1.0, $model->populationStandardDeviations['a']);
        $this->assertSame(['constant'], $model->zeroVarianceFeatures);
        $this->assertSame(['a' => 98.0, 'constant' => 0.0], $standardizer->transform($model, ['a' => 100, 'constant' => 5]));
        $this->assertSame(2.0, $model->means['a']);
    }

    public function test_standardizer_fails_closed_for_empty_or_reordered_iterables(): void
    {
        $standardizer = new TrainingStandardizer;

        $this->assertCallbackThrows(fn () => $standardizer->fit((function (): Generator {
            yield from [];
        })()), InvalidArgumentException::class);
        $this->assertCallbackThrows(fn () => $standardizer->fit((function (): Generator {
            yield ['a' => 1, 'b' => 2];
            yield ['b' => 2, 'a' => 1];
        })()), InvalidArgumentException::class);
    }

    public function test_ridge_logistic_known_case_is_deterministic(): void
    {
        $regression = new RidgeLogisticRegression;
        $x = [[-2.0], [-1.0], [1.0], [2.0]];
        $y = [0, 0, 1, 1];
        $counter = (object) ['passes' => 0];
        $first = $regression->fit($this->logisticSource($x, $y, $counter), 0.1);
        $second = $regression->fit($this->logisticSource($x, $y), 0.1);

        $this->assertGreaterThan(2, $counter->passes, 'IRLS and line search must replay the bounded source.');
        $this->assertTrue($first->converged(), $first->status->value);
        $this->assertGreaterThan(0, $first->coefficients[0]);
        $this->assertLessThan(0.5, $regression->probability($first->intercept, $first->coefficients, [-1.0]));
        $this->assertGreaterThan(0.5, $regression->probability($first->intercept, $first->coefficients, [1.0]));
        $this->assertEquals($first, $second);
    }

    public function test_ridge_converges_when_newton_improvement_reaches_production_roundoff_scale(): void
    {
        $regression = new RidgeLogisticRegression;
        $features = array_fill(0, 48, [0.0]);
        $labels = [...array_fill(0, 14, 1), ...array_fill(0, 34, 0)];
        $initialObjective = $regression->objective([0.0, 0.0], $this->logisticSource($features, $labels), 100.0);

        $first = $regression->fit($this->logisticSource($features, $labels), 100.0);
        $second = $regression->fit($this->logisticSource($features, $labels), 100.0);

        $this->assertSame(Bt02ConvergenceStatus::ConvergedStepObjective, $first->status);
        $this->assertSame(3, $first->iterations);
        $this->assertLessThanOrEqual($initialObjective, $first->finalObjective);
        $this->assertSame(0.0, $first->coefficients[0]);
        $this->assertEquals($first, $second);
    }

    public function test_single_class_is_not_fitted(): void
    {
        $result = (new RidgeLogisticRegression)->fit($this->logisticSource([[0.0], [1.0]], [1, 1]), 0.1);

        $this->assertSame(Bt02ConvergenceStatus::FailedSingleClassTraining, $result->status);
        $this->assertFalse($result->converged());
    }

    public function test_ridge_rejects_non_finite_and_dimension_mismatched_stream_rows(): void
    {
        $regression = new RidgeLogisticRegression;

        $this->assertCallbackThrows(
            fn () => $regression->fit($this->logisticSource([[0.0], [INF]], [0, 1]), 0.1),
            InvalidArgumentException::class,
        );
        $this->assertCallbackThrows(
            fn () => $regression->fit($this->logisticSource([[0.0], [1.0, 2.0]], [0, 1]), 0.1),
            InvalidArgumentException::class,
        );
    }

    public function test_regularization_shrinks_coefficients_without_penalizing_intercept(): void
    {
        $regression = new RidgeLogisticRegression;
        $x = [[-2.0], [-1.0], [0.0], [1.0], [2.0], [3.0]];
        $y = [0, 0, 0, 1, 1, 1];
        $weak = $regression->fit($this->logisticSource($x, $y), 1e-4);
        $strong = $regression->fit($this->logisticSource($x, $y), 100.0);
        $zeroXWeak = $regression->fit($this->logisticSource(array_fill(0, 4, [0.0]), [0, 0, 0, 1]), 1e-4);
        $zeroXStrong = $regression->fit($this->logisticSource(array_fill(0, 4, [0.0]), [0, 0, 0, 1]), 100.0);

        $this->assertLessThan(abs($weak->coefficients[0]), abs($strong->coefficients[0]));
        $this->assertEqualsWithDelta($zeroXWeak->intercept, $zeroXStrong->intercept, 1e-12);
        $this->assertSame(0.0, $zeroXStrong->coefficients[0]);
    }

    public function test_mean_loss_objective_does_not_rescale_lambda_with_sample_count(): void
    {
        $regression = new RidgeLogisticRegression;
        $parameters = [0.2, 0.5];
        $single = $regression->objective($parameters, $this->logisticSource([[1.0], [-1.0]], [1, 0]), 0.1);
        $duplicated = $regression->objective($parameters, $this->logisticSource([[1.0], [-1.0], [1.0], [-1.0]], [1, 0, 1, 0]), 0.1);

        $this->assertEqualsWithDelta($single, $duplicated, 1e-15);
    }

    public function test_temporal_lambda_selection_uses_log_loss_and_stronger_tie_break(): void
    {
        $selector = new TemporalLambdaSelector;

        $this->assertSame(0.1, $selector->select($this->lambdaLosses(['0.1' => 0.4])));
        $this->assertSame(10.0, $selector->select($this->lambdaLosses(['1' => 0.4, '10' => 0.4000000000005])));
    }

    public function test_temporal_lambda_tie_set_is_minimum_based_and_order_independent(): void
    {
        $selector = new TemporalLambdaSelector;
        $losses = $this->lambdaLosses([
            '0.0001' => 0.0,
            '0.001' => 0.75e-12,
            '0.01' => 1.5e-12,
        ]);

        $this->assertSame(0.001, $selector->select($losses));
        $this->assertSame(0.001, $selector->select(array_reverse($losses, true)));
    }

    public function test_temporal_lambda_requires_each_fixed_candidate_exactly_once(): void
    {
        $selector = new TemporalLambdaSelector;
        $missing = $this->lambdaLosses();
        unset($missing['100']);
        $this->assertCallbackThrows(fn () => $selector->select($missing), InvalidArgumentException::class);

        $duplicate = $this->lambdaLosses();
        unset($duplicate['100']);
        $duplicate['1e-4'] = 0.5;
        $this->assertCallbackThrows(fn () => $selector->select($duplicate), InvalidArgumentException::class);

        $nonFinite = $this->lambdaLosses();
        $nonFinite['1'] = INF;
        $this->assertCallbackThrows(fn () => $selector->select($nonFinite), InvalidArgumentException::class);

        $unknown = $this->lambdaLosses();
        unset($unknown['100']);
        $unknown['1000'] = 0.5;
        $this->assertCallbackThrows(fn () => $selector->select($unknown), InvalidArgumentException::class);
    }

    public function test_model_artifact_hash_is_key_order_independent_and_float_exact(): void
    {
        $hasher = new Bt02ModelArtifactHasher;
        $first = ['intercept' => 0.1, 'coefficients' => [1.0, 2.0], 'lambda' => 0.01];
        $second = ['lambda' => 0.01, 'coefficients' => [1.0, 2.0], 'intercept' => 0.1];

        $this->assertSame($hasher->hash($first), $hasher->hash($second));
        $this->assertNotSame($hasher->hash($first), $hasher->hash([...$first, 'intercept' => 0.1000000000000001]));
    }

    public function test_evaluation_single_class_keeps_losses_but_auc_is_null(): void
    {
        $metrics = new BinaryMetricCalculator;

        $this->assertGreaterThan(0, $metrics->logLoss([0.2, 0.3], [0, 0]));
        $this->assertGreaterThan(0, $metrics->brier([0.2, 0.3], [0, 0]));
        $this->assertNull($metrics->auc([0.2, 0.3], [0, 0]));
        $this->assertSame(1.0, $metrics->auc([0.1, 0.9], [0, 1]));
        $this->assertSame(
            $metrics->logLoss([0.1, 0.9], [0, 1]),
            $metrics->streamingLogLoss((function (): Generator {
                yield [0.1, 0];
                yield [0.9, 1];
            })()),
        );
    }

    public function test_type7_quantile_and_training_only_effect_bins_are_fixed(): void
    {
        $quantile = new Type7Quantile;
        $builder = new EffectBinBuilder(new InMemoryEffectBinBoundaryProvider($quantile));
        $training = (function (): Generator {
            yield from range(0, 10);
        })();
        $bins = $builder->build($training);

        $this->assertSame(2.5, $quantile->calculate([0, 10], 0.25));
        $this->assertSame(2.5, $quantile->calculateSorted([0.0, 10.0], 0.25));
        $this->assertSame(10, count($bins));
        $this->assertSame(1.0, $bins[0]->upperBound);
        $this->assertSame(10, $builder->assign($bins, 1000));
        $this->assertSame(1.0, $bins[0]->upperBound, 'Evaluation values must not refit boundaries.');
    }

    public function test_low_cardinality_bins_use_natural_categories_and_unseen_marker(): void
    {
        $builder = new EffectBinBuilder(new InMemoryEffectBinBoundaryProvider(new Type7Quantile));
        $bins = $builder->build(['A', 'B', 'A']);

        $this->assertSame('CATEGORY', $bins[0]->kind);
        $this->assertSame(2, $bins[0]->trainingSampleCount);
        $this->assertSame(0, $builder->assign($bins, 'C'));
        $this->assertNull($builder->assign($bins, null));
    }

    public function test_race_cluster_bootstrap_is_seeded_and_keeps_whole_payloads(): void
    {
        $bootstrap = new RaceClusterBootstrap;
        $stream = $bootstrap->resampleIndexes(3, 5, RaceClusterBootstrap::SEED);
        $this->assertInstanceOf(Generator::class, $stream);
        $first = iterator_to_array($stream, false);
        $second = iterator_to_array($bootstrap->resampleIndexes(3, 5, RaceClusterBootstrap::SEED), false);

        $this->assertSame($first, $second);
        $this->assertCount(3, $first[0]);
        $this->assertTrue((bool) array_filter($first, fn (array $sample): bool => count(array_unique($sample)) < count($sample)));
        foreach ($first as $sample) {
            foreach ($sample as $index) {
                $this->assertGreaterThanOrEqual(0, $index);
                $this->assertLessThan(3, $index);
            }
        }
        $payloads = [['race' => 1, 'entries' => [1, 2]], ['race' => 2, 'entries' => [3]], ['race' => 3, 'entries' => [4, 5]]];
        foreach ($bootstrap->apply($payloads, $first[0]) as $payload) {
            $this->assertArrayHasKey('entries', $payload);
        }

        $large = $bootstrap->resampleIndexes(1000, 2000);
        $this->assertCount(1000, $large->current());
        $large->next();
        $this->assertSame(1, $large->key(), 'The caller can stop after any yielded iteration.');
    }

    /**
     * @param  list<list<float>>  $features
     * @param  list<int>  $labels
     */
    private function logisticSource(array $features, array $labels, ?object $counter = null): CallbackLogisticTrainingRowSource
    {
        return new CallbackLogisticTrainingRowSource(function () use ($features, $labels, $counter): Generator {
            if ($counter !== null) {
                $counter->passes++;
            }
            foreach ($features as $index => $row) {
                yield new LogisticTrainingRowDto($row, $labels[$index]);
            }
        });
    }

    /** @param array<string, float> $overrides @return array<string, float> */
    private function lambdaLosses(array $overrides = []): array
    {
        return array_replace([
            '0.0001' => 1.0,
            '0.001' => 1.0,
            '0.01' => 1.0,
            '0.1' => 1.0,
            '1' => 1.0,
            '10' => 1.0,
            '100' => 1.0,
        ], $overrides);
    }

    /** @param array<string, mixed> $changes */
    private function changedManifestEntry(Bt02SourceManifestEntryDto $entry, array $changes): Bt02SourceManifestEntryDto
    {
        $value = fn (string $name): mixed => $changes[$name] ?? $entry->{$name};

        return new Bt02SourceManifestEntryDto(
            $value('year'),
            $value('statCode'),
            $value('featureRunId'),
            $value('featureRunUuid'),
            $value('calculationVersion'),
            $value('sourceStat01RunId'),
            $value('sourceStat01RunUuid'),
            $value('targetFrom'),
            $value('targetTo'),
            $value('historyFrom'),
            $value('subjectType'),
            $value('processedRaceCount'),
            $value('targetEntryCount'),
            $value('rowCount'),
            $value('sourceFingerprintSha256'),
            $value('contentFingerprintSha256'),
        );
    }

    /** @param class-string<\Throwable> $exceptionClass */
    private function assertCallbackThrows(callable $callback, string $exceptionClass): void
    {
        try {
            $callback();
            $this->fail("Expected {$exceptionClass} was not thrown.");
        } catch (\Throwable $exception) {
            $this->assertInstanceOf($exceptionClass, $exception);
        }
    }

    /** @param list<string> $reasons */
    private function signal(int $entryId, string $quality, array $reasons, ?float $value = 1.0): Bt02SignalFeatureDto
    {
        return new Bt02SignalFeatureDto(1, $entryId, 'VALID', $quality, $reasons, $value);
    }
}
