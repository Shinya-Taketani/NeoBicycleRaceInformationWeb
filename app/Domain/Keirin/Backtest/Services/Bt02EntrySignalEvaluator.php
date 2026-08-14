<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Services;

use App\Domain\Keirin\Backtest\Calculators\BinaryMetricCalculator;
use App\Domain\Keirin\Backtest\Calculators\EffectBinBuilder;
use App\Domain\Keirin\Backtest\Calculators\RaceClusterBootstrap;
use App\Domain\Keirin\Backtest\Calculators\RidgeLogisticRegression;
use App\Domain\Keirin\Backtest\Calculators\TemporalLambdaSelector;
use App\Domain\Keirin\Backtest\Calculators\TrainingStandardizer;
use App\Domain\Keirin\Backtest\Calculators\Type7Quantile;
use App\Domain\Keirin\Backtest\Contracts\Bt02EvaluationDataset;
use App\Domain\Keirin\Backtest\DTO\Bt02EvaluationRowDto;
use App\Domain\Keirin\Backtest\DTO\Bt02FittedModelDto;
use App\Domain\Keirin\Backtest\DTO\Bt02FoldDefinitionDto;
use App\Domain\Keirin\Backtest\DTO\Bt02SignalDefinitionDto;
use App\Domain\Keirin\Backtest\DTO\LogisticTrainingRowDto;
use App\Domain\Keirin\Backtest\DTO\StandardizationModelDto;
use App\Domain\Keirin\Backtest\Enums\Bt02SignalCohort;
use App\Domain\Keirin\Backtest\Repositories\Bt02AuditRepository;
use App\Domain\Keirin\Backtest\Support\Bt02ModelArtifactHasher;
use App\Domain\Keirin\Backtest\Support\Bt02PredictionSpool;
use App\Domain\Keirin\Backtest\Support\Bt02TrainingSpoolFactory;
use App\Domain\Keirin\Backtest\Support\ImmutableBt02Spool;
use App\Domain\Keirin\Backtest\Support\SpoolLogisticTrainingRowSource;
use App\Models\BacktestFold;
use App\Models\BacktestRun;
use App\Models\BacktestSignalSpec;
use DateTimeImmutable;
use RuntimeException;

class Bt02EntrySignalEvaluator
{
    /** @var list<string> */
    public const LABELS = ['IS_WIN', 'IS_TOP2', 'IS_TOP3'];

    /** @var list<string> */
    public const METRICS = ['AUC', 'LOG_LOSS', 'BRIER'];

    public const PROBABILITY_SEMANTICS = 'ENTRY_BINARY_NOT_RACE_NORMALIZED';

    public function __construct(
        private readonly Bt02EvaluationDataset $dataset,
        private readonly TrainingStandardizer $standardizer,
        private readonly RidgeLogisticRegression $regression,
        private readonly TemporalLambdaSelector $lambdaSelector,
        private readonly BinaryMetricCalculator $metrics,
        private readonly RaceClusterBootstrap $bootstrap,
        private readonly Type7Quantile $quantile,
        private readonly EffectBinBuilder $effectBins,
        private readonly Bt02TrainingSpoolFactory $trainingSpools,
        private readonly Bt02ModelArtifactHasher $modelHasher,
        private readonly Bt02AuditRepository $audit,
        private readonly int $bootstrapIterations = RaceClusterBootstrap::ITERATIONS,
    ) {}

    /** @return array{models: int, metrics: int, races: int, rows: int, manifest_hash: string} */
    public function evaluate(
        BacktestRun $run,
        BacktestFold $fold,
        Bt02FoldDefinitionDto $definition,
        Bt02SignalDefinitionDto $signal,
        BacktestSignalSpec $spec,
    ): array {
        $modelCount = $metricCount = $raceCount = $rowCount = 0;
        $manifest = hash_init('sha256');

        foreach (Bt02SignalCohort::cases() as $cohort) {
            $bins = $this->effectBins->build($this->signalValues($definition->trainingFrom, $definition->trainingTo, $signal->statCode, $cohort));
            $boundariesHash = $this->modelHasher->hash(array_map(fn ($bin): array => [
                'index' => $bin->index,
                'kind' => $bin->kind,
                'lower' => $bin->lowerBound,
                'upper' => $bin->upperBound,
                'category' => $bin->categoryValue,
                'count' => $bin->trainingSampleCount,
            ], $bins));
            $this->audit->storeEffectBins($run, $fold, $spec, $cohort->value, $boundariesHash, $bins, [
                'boundary_source' => 'TRAINING_ONLY',
                'quantile_type' => 7,
            ]);

            foreach (self::LABELS as $labelCode) {
                $baseline = $this->fitModel($definition, $signal, $cohort, $labelCode, false);
                $incremental = $this->fitModel($definition, $signal, $cohort, $labelCode, true);
                $prediction = null;
                try {
                    $prediction = $this->evaluateModels($definition, $signal, $cohort, $labelCode, $baseline, $incremental);
                    $payloads = $prediction['spool']->racePayloads();
                    $raceCount = max($raceCount, count($payloads));
                    $rowCount = max($rowCount, $prediction['manifests']['rows']);

                    $this->storeModel($run, $fold, $spec, $definition, $signal, $cohort, $labelCode, 'BASELINE_MATCHED', $baseline, $prediction['manifests']['baseline']);
                    $this->storeModel($run, $fold, $spec, $definition, $signal, $cohort, $labelCode, 'INCREMENTAL', $incremental, $prediction['manifests']['incremental']);
                    $modelCount += 2;

                    foreach (self::METRICS as $metricCode) {
                        [$baselineValue, $incrementalValue] = $this->metricPair($metricCode, $payloads);
                        $delta = $baselineValue !== null && $incrementalValue !== null
                            ? $incrementalValue - $baselineValue
                            : null;
                        [$lower, $upper] = $this->bootstrapInterval($metricCode, $payloads);
                        $this->audit->storeMetric($run, $fold, $spec, [
                            'label_code' => $labelCode,
                            'cohort_code' => $cohort->value,
                            'metric_code' => $metricCode,
                            'baseline_value' => $baselineValue,
                            'incremental_value' => $incrementalValue,
                            'delta_value' => $delta,
                            'ci_lower' => $lower,
                            'ci_upper' => $upper,
                            'sample_count' => $prediction['manifests']['rows'],
                            'race_count' => count($payloads),
                            'bootstrap_iterations' => $this->bootstrapIterations,
                            'bootstrap_seed' => RaceClusterBootstrap::SEED,
                            'metadata' => ['comparison' => 'PAIRED_ENTRY_SET'],
                        ]);
                        $metricCount++;
                    }
                    hash_update($manifest, $prediction['manifests']['baseline']."\n".$prediction['manifests']['incremental']."\n");
                } finally {
                    if ($prediction !== null) {
                        $prediction['spool']->cleanup();
                    }
                }
            }
        }

        return [
            'models' => $modelCount,
            'metrics' => $metricCount,
            'races' => $raceCount,
            'rows' => $rowCount,
            'manifest_hash' => hash_final($manifest),
        ];
    }

    private function fitModel(
        Bt02FoldDefinitionDto $fold,
        Bt02SignalDefinitionDto $signal,
        Bt02SignalCohort $cohort,
        string $labelCode,
        bool $incremental,
    ): Bt02FittedModelDto {
        $innerModel = $this->fitStandardization($fold->innerFitFrom, $fold->innerFitTo, $signal, $cohort, $incremental);
        $innerFit = null;
        $innerValidation = null;
        try {
            $innerFit = $this->standardizedSpool($fold->innerFitFrom, $fold->innerFitTo, $signal, $cohort, $labelCode, $incremental, $innerModel);
            $innerValidation = $this->standardizedSpool($fold->innerValidationFrom, $fold->innerValidationTo, $signal, $cohort, $labelCode, $incremental, $innerModel);
            $losses = [];
            foreach (RidgeLogisticRegression::LAMBDA_CANDIDATES as $lambda) {
                $fit = $this->regression->fit(new SpoolLogisticTrainingRowSource($innerFit), $lambda);
                if (! $fit->converged()) {
                    throw new RuntimeException("BT-02 inner model failed: {$fit->status->value}.");
                }
                $losses[$this->lambdaKey($lambda)] = $this->validationLogLoss($fit, $innerValidation);
            }
            $selectedLambda = $this->lambdaSelector->select($losses);
        } finally {
            $innerFit?->cleanup();
            $innerValidation?->cleanup();
        }

        $trainingModel = $this->fitStandardization($fold->trainingFrom, $fold->trainingTo, $signal, $cohort, $incremental);
        $training = $this->standardizedSpool($fold->trainingFrom, $fold->trainingTo, $signal, $cohort, $labelCode, $incremental, $trainingModel);
        try {
            $fit = $this->regression->fit(new SpoolLogisticTrainingRowSource($training), $selectedLambda);
        } finally {
            $training->cleanup();
        }
        if (! $fit->converged()) {
            throw new RuntimeException("BT-02 full training model failed: {$fit->status->value}.");
        }
        $featureNames = $this->featureNames($signal, $incremental);
        $hash = $this->modelHasher->hash([
            'feature_names' => $featureNames,
            'scaler_mean' => $trainingModel->means,
            'scaler_sd' => $trainingModel->populationStandardDeviations,
            'selected_lambda' => $selectedLambda,
            'intercept' => $fit->intercept,
            'coefficients' => $fit->coefficients,
            'objective_version' => RidgeLogisticRegression::OBJECTIVE_VERSION,
            'optimizer_version' => RidgeLogisticRegression::OPTIMIZER_VERSION,
            'probability_semantics' => self::PROBABILITY_SEMANTICS,
        ]);

        return new Bt02FittedModelDto($featureNames, $trainingModel, $selectedLambda, $fit, $hash);
    }

    /** @return array{spool: Bt02PredictionSpool, manifests: array{baseline: string, incremental: string, rows: int}} */
    private function evaluateModels(
        Bt02FoldDefinitionDto $fold,
        Bt02SignalDefinitionDto $signal,
        Bt02SignalCohort $cohort,
        string $labelCode,
        Bt02FittedModelDto $baseline,
        Bt02FittedModelDto $incremental,
    ): array {
        $spool = new Bt02PredictionSpool([
            'source_manifest_hash' => Bt02SourceManifest::HASH,
            'fold' => $fold->code,
            'stat_code' => $signal->statCode,
            'cohort' => $cohort->value,
            'label' => $labelCode,
            'baseline_model_hash' => $baseline->modelHash,
            'incremental_model_hash' => $incremental->modelHash,
        ]);
        try {
            foreach ($this->dataset->rows($fold->evaluationFrom, $fold->evaluationTo, $signal->statCode, $cohort) as $row) {
                $baseFeatures = array_values($this->standardizer->transform($baseline->standardization, $this->rawFeatures($row, false, $signal)));
                $incrementalFeatures = array_values($this->standardizer->transform($incremental->standardization, $this->rawFeatures($row, true, $signal)));
                $spool->append(
                    $row->raceId,
                    $row->raceEntryId,
                    $row->label($labelCode),
                    $this->regression->probability($baseline->fit->intercept, $baseline->fit->coefficients, $baseFeatures),
                    $this->regression->probability($incremental->fit->intercept, $incremental->fit->coefficients, $incrementalFeatures),
                );
            }

            return ['spool' => $spool, 'manifests' => $spool->seal()];
        } catch (\Throwable $throwable) {
            $spool->cleanup();
            throw $throwable;
        }
    }

    private function fitStandardization(
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        Bt02SignalDefinitionDto $signal,
        Bt02SignalCohort $cohort,
        bool $incremental,
    ): StandardizationModelDto {
        return $this->standardizer->fit((function () use ($from, $to, $signal, $cohort, $incremental): iterable {
            foreach ($this->dataset->rows($from, $to, $signal->statCode, $cohort) as $row) {
                yield $this->rawFeatures($row, $incremental, $signal);
            }
        })());
    }

    private function standardizedSpool(
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        Bt02SignalDefinitionDto $signal,
        Bt02SignalCohort $cohort,
        string $labelCode,
        bool $incremental,
        StandardizationModelDto $model,
    ): ImmutableBt02Spool {
        return $this->trainingSpools->create((function () use ($from, $to, $signal, $cohort, $labelCode, $incremental, $model): iterable {
            foreach ($this->dataset->rows($from, $to, $signal->statCode, $cohort) as $row) {
                yield new LogisticTrainingRowDto(
                    array_values($this->standardizer->transform($model, $this->rawFeatures($row, $incremental, $signal))),
                    $row->label($labelCode),
                );
            }
        })());
    }

    private function validationLogLoss($fit, ImmutableBt02Spool $validation): float
    {
        return $this->metrics->streamingLogLoss((function () use ($fit, $validation): iterable {
            foreach ((new SpoolLogisticTrainingRowSource($validation))->rows() as $row) {
                yield [
                    $this->regression->probability($fit->intercept, $fit->coefficients, $row->features),
                    $row->label,
                ];
            }
        })());
    }

    /** @param list<array{race_id: int, labels: list<int>, baseline: list<float>, incremental: list<float>}> $payloads @return array{?float, ?float} */
    private function metricPair(string $metricCode, array $payloads): array
    {
        $labels = $baseline = $incremental = [];
        foreach ($payloads as $payload) {
            array_push($labels, ...$payload['labels']);
            array_push($baseline, ...$payload['baseline']);
            array_push($incremental, ...$payload['incremental']);
        }

        return match ($metricCode) {
            'AUC' => [$this->metrics->auc($baseline, $labels), $this->metrics->auc($incremental, $labels)],
            'LOG_LOSS' => [$this->metrics->logLoss($baseline, $labels), $this->metrics->logLoss($incremental, $labels)],
            'BRIER' => [$this->metrics->brier($baseline, $labels), $this->metrics->brier($incremental, $labels)],
            default => throw new RuntimeException("Unknown BT-02 metric {$metricCode}."),
        };
    }

    /** @param list<array{race_id: int, labels: list<int>, baseline: list<float>, incremental: list<float>}> $payloads @return array{?float, ?float} */
    private function bootstrapInterval(string $metricCode, array $payloads): array
    {
        $deltas = [];
        foreach ($this->bootstrap->resampleIndexes(count($payloads), $this->bootstrapIterations, RaceClusterBootstrap::SEED) as $indexes) {
            [$baseline, $incremental] = $this->metricPair($metricCode, $this->bootstrap->apply($payloads, $indexes));
            if ($baseline !== null && $incremental !== null) {
                $deltas[] = $incremental - $baseline;
            }
        }
        if ($deltas === []) {
            return [null, null];
        }

        return [$this->quantile->calculate($deltas, 0.025), $this->quantile->calculate($deltas, 0.975)];
    }

    private function storeModel(
        BacktestRun $run,
        BacktestFold $fold,
        BacktestSignalSpec $spec,
        Bt02FoldDefinitionDto $definition,
        Bt02SignalDefinitionDto $signal,
        Bt02SignalCohort $cohort,
        string $labelCode,
        string $role,
        Bt02FittedModelDto $model,
        string $predictionManifestHash,
    ): void {
        $this->audit->storeModel($run, $fold, $spec, [
            'model_role' => $role,
            'label_code' => $labelCode,
            'cohort_code' => $cohort->value,
            'training_from' => $definition->trainingFrom,
            'training_to' => $definition->trainingTo,
            'inner_fit_from' => $definition->innerFitFrom,
            'inner_fit_to' => $definition->innerFitTo,
            'inner_validation_from' => $definition->innerValidationFrom,
            'inner_validation_to' => $definition->innerValidationTo,
            'feature_names' => $model->featureNames,
            'scaler_mean' => $model->standardization->means,
            'scaler_sd' => $model->standardization->populationStandardDeviations,
            'lambda_candidates' => RidgeLogisticRegression::LAMBDA_CANDIDATES,
            'selected_lambda' => $model->selectedLambda,
            'intercept' => $model->fit->intercept,
            'coefficients' => $model->fit->coefficients,
            'objective_version' => RidgeLogisticRegression::OBJECTIVE_VERSION,
            'optimizer_version' => RidgeLogisticRegression::OPTIMIZER_VERSION,
            'probability_semantics' => self::PROBABILITY_SEMANTICS,
            'convergence_status' => $model->fit->status->value,
            'iterations' => $model->fit->iterations,
            'final_objective' => $model->fit->finalObjective,
            'model_hash' => $model->modelHash,
            'prediction_manifest_hash' => $predictionManifestHash,
        ]);
    }

    /** @return iterable<float> */
    private function signalValues(DateTimeImmutable $from, DateTimeImmutable $to, string $statCode, Bt02SignalCohort $cohort): iterable
    {
        foreach ($this->dataset->rows($from, $to, $statCode, $cohort) as $row) {
            yield $row->signalValue;
        }
    }

    /** @return array<string, float> */
    private function rawFeatures(Bt02EvaluationRowDto $row, bool $incremental, Bt02SignalDefinitionDto $signal): array
    {
        $features = ['STAT01_RACE_SCORE' => $row->baselineValue];
        if ($incremental) {
            $features[$signal->primaryFeatureCode] = $row->signalValue;
        }

        return $features;
    }

    /** @return list<string> */
    private function featureNames(Bt02SignalDefinitionDto $signal, bool $incremental): array
    {
        return $incremental ? ['STAT01_RACE_SCORE', $signal->primaryFeatureCode] : ['STAT01_RACE_SCORE'];
    }

    private function lambdaKey(float $lambda): string
    {
        return sprintf('%.4g', $lambda);
    }
}
