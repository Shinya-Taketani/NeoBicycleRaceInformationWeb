<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Services;

use App\Domain\Keirin\Backtest\Calculators\EffectBinBuilder;
use App\Domain\Keirin\Backtest\Calculators\PairedRaceClusterMetricEvaluator;
use App\Domain\Keirin\Backtest\Calculators\RaceClusterBootstrap;
use App\Domain\Keirin\Backtest\Calculators\RidgeLogisticRegression;
use App\Domain\Keirin\Backtest\Calculators\TemporalLambdaSelector;
use App\Domain\Keirin\Backtest\Calculators\TrainingStandardizer;
use App\Domain\Keirin\Backtest\Contracts\Bt02EvaluationDataset;
use App\Domain\Keirin\Backtest\DTO\Bt02EvaluationRowDto;
use App\Domain\Keirin\Backtest\DTO\Bt02FittedModelDto;
use App\Domain\Keirin\Backtest\DTO\Bt02FoldDefinitionDto;
use App\Domain\Keirin\Backtest\DTO\Bt02SignalDefinitionDto;
use App\Domain\Keirin\Backtest\DTO\LogisticTrainingRowDto;
use App\Domain\Keirin\Backtest\DTO\StandardizationModelDto;
use App\Domain\Keirin\Backtest\Enums\Bt02SignalCohort;
use App\Domain\Keirin\Backtest\Repositories\Bt02AuditRepository;
use App\Domain\Keirin\Backtest\Support\Bt02EvaluationRowSpool;
use App\Domain\Keirin\Backtest\Support\Bt02ModelArtifactHasher;
use App\Domain\Keirin\Backtest\Support\Bt02PredictionSpool;
use App\Domain\Keirin\Backtest\Support\Bt02TrainingSpoolFactory;
use App\Domain\Keirin\Backtest\Support\ImmutableBt02Spool;
use App\Domain\Keirin\Backtest\Support\SpoolLogisticTrainingRowSource;
use App\Models\BacktestFold;
use App\Models\BacktestRun;
use App\Models\BacktestSignalSpec;
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
        private readonly EffectBinBuilder $effectBins,
        private readonly Bt02TrainingSpoolFactory $trainingSpools,
        private readonly Bt02ModelArtifactHasher $modelHasher,
        private readonly Bt02AuditRepository $audit,
        private readonly PairedRaceClusterMetricEvaluator $pairedMetrics,
        private readonly int $bootstrapIterations = RaceClusterBootstrap::ITERATIONS,
        private readonly ?string $temporaryDirectory = null,
    ) {}

    /**
     * @param  (callable(list<int>): void)|null  $raceProgress
     * @return array{models: int, metrics: int, races: int, rows: int, race_ids: list<int>, manifest_hash: string}
     */
    public function evaluate(
        BacktestRun $run,
        BacktestFold $fold,
        Bt02FoldDefinitionDto $definition,
        Bt02SignalDefinitionDto $signal,
        BacktestSignalSpec $spec,
        ?callable $raceProgress = null,
    ): array {
        $modelCount = $metricCount = $rowCount = 0;
        $raceIds = [];
        $manifest = hash_init('sha256');

        foreach (Bt02SignalCohort::cases() as $cohort) {
            $spools = $this->datasetSpools($definition, $signal, $cohort);
            try {
                $bins = $this->effectBins->build($this->signalValues($spools['training']));
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
                $standardizations = [
                    'inner_baseline' => $this->fitStandardization($spools['inner_fit'], $signal, false),
                    'inner_incremental' => $this->fitStandardization($spools['inner_fit'], $signal, true),
                    'training_baseline' => $this->fitStandardization($spools['training'], $signal, false),
                    'training_incremental' => $this->fitStandardization($spools['training'], $signal, true),
                ];

                foreach (self::LABELS as $labelCode) {
                    $baseline = $this->fitModel($definition, $signal, $labelCode, false, $standardizations['inner_baseline'], $standardizations['training_baseline'], $spools);
                    $incremental = $this->fitModel($definition, $signal, $labelCode, true, $standardizations['inner_incremental'], $standardizations['training_incremental'], $spools);
                    $prediction = null;
                    try {
                        $prediction = $this->evaluateModels($definition, $signal, $cohort, $labelCode, $baseline, $incremental, $spools['evaluation']);
                        $evaluation = $this->pairedMetrics->evaluate($prediction, $this->bootstrapIterations, RaceClusterBootstrap::SEED);
                        $predictionMetadata = $prediction->metadata();
                        $rowCount = max($rowCount, $evaluation->rowCount);
                        foreach ($evaluation->raceIds as $raceId) {
                            $raceIds[$raceId] = true;
                        }
                        if ($raceProgress !== null) {
                            $raceProgress($evaluation->raceIds);
                        }

                        $this->storeModel($run, $fold, $spec, $definition, $cohort, $labelCode, 'BASELINE_MATCHED', $baseline, $predictionMetadata->baselinePredictionManifestSha256);
                        $this->storeModel($run, $fold, $spec, $definition, $cohort, $labelCode, 'INCREMENTAL', $incremental, $predictionMetadata->incrementalPredictionManifestSha256);
                        $modelCount += 2;

                        foreach (self::METRICS as $metricCode) {
                            $metric = $evaluation->metrics[$metricCode] ?? throw new RuntimeException("BT-02 metric {$metricCode} was missing.");
                            $this->audit->storeMetric($run, $fold, $spec, [
                                'label_code' => $labelCode,
                                'cohort_code' => $cohort->value,
                                'metric_code' => $metricCode,
                                'baseline_value' => $metric['baseline'],
                                'incremental_value' => $metric['incremental'],
                                'delta_value' => $metric['delta'],
                                'ci_lower' => $metric['ci_lower'],
                                'ci_upper' => $metric['ci_upper'],
                                'sample_count' => $evaluation->rowCount,
                                'race_count' => count($evaluation->raceIds),
                                'bootstrap_iterations' => $evaluation->bootstrapReplicateCount,
                                'bootstrap_seed' => RaceClusterBootstrap::SEED,
                                'metadata' => [
                                    'comparison' => 'PAIRED_ENTRY_SET',
                                    'bootstrap_replicate_contract' => 'SHARED_BY_AUC_LOG_LOSS_BRIER',
                                    'outcome_manifest_hash' => $predictionMetadata->outcomeManifestSha256,
                                    'baseline_prediction_manifest_hash' => $predictionMetadata->baselinePredictionManifestSha256,
                                    'incremental_prediction_manifest_hash' => $predictionMetadata->incrementalPredictionManifestSha256,
                                    'temporary_disk_bytes' => $evaluation->temporaryByteCount,
                                ],
                            ]);
                            $metricCount++;
                        }
                        hash_update($manifest, $predictionMetadata->baselinePredictionManifestSha256."\n".$predictionMetadata->incrementalPredictionManifestSha256."\n");
                    } finally {
                        $prediction?->cleanup();
                    }
                }
            } finally {
                foreach ($spools as $spool) {
                    $spool->cleanup();
                }
            }
        }

        $raceIdList = array_map('intval', array_keys($raceIds));
        sort($raceIdList, SORT_NUMERIC);

        return [
            'models' => $modelCount,
            'metrics' => $metricCount,
            'races' => count($raceIdList),
            'rows' => $rowCount,
            'race_ids' => $raceIdList,
            'manifest_hash' => hash_final($manifest),
        ];
    }

    /**
     * @param  array{training: Bt02EvaluationRowSpool, inner_fit: Bt02EvaluationRowSpool, inner_validation: Bt02EvaluationRowSpool, evaluation: Bt02EvaluationRowSpool}  $spools
     */
    private function fitModel(
        Bt02FoldDefinitionDto $fold,
        Bt02SignalDefinitionDto $signal,
        string $labelCode,
        bool $incremental,
        StandardizationModelDto $innerModel,
        StandardizationModelDto $trainingModel,
        array $spools,
    ): Bt02FittedModelDto {
        $innerFit = $innerValidation = null;
        try {
            $innerFit = $this->standardizedSpool($spools['inner_fit'], $signal, $labelCode, $incremental, $innerModel);
            $innerValidation = $this->standardizedSpool($spools['inner_validation'], $signal, $labelCode, $incremental, $innerModel);
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

        $training = $this->standardizedSpool($spools['training'], $signal, $labelCode, $incremental, $trainingModel);
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

    private function evaluateModels(
        Bt02FoldDefinitionDto $fold,
        Bt02SignalDefinitionDto $signal,
        Bt02SignalCohort $cohort,
        string $labelCode,
        Bt02FittedModelDto $baseline,
        Bt02FittedModelDto $incremental,
        Bt02EvaluationRowSpool $evaluationRows,
    ): Bt02PredictionSpool {
        $spool = new Bt02PredictionSpool([
            'source_manifest_hash' => Bt02SourceManifest::HASH,
            'baseline_fingerprint_manifest_hash' => Bt02BaselineFingerprintManifest::HASH,
            'fold' => $fold->code,
            'stat_code' => $signal->statCode,
            'cohort' => $cohort->value,
            'label_code' => $labelCode,
            'baseline_model_hash' => $baseline->modelHash,
            'incremental_model_hash' => $incremental->modelHash,
        ], $this->temporaryDirectory);
        try {
            foreach ($evaluationRows->rows() as $row) {
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
            $spool->seal();

            return $spool;
        } catch (\Throwable $throwable) {
            $spool->cleanup();
            throw $throwable;
        }
    }

    private function fitStandardization(Bt02EvaluationRowSpool $spool, Bt02SignalDefinitionDto $signal, bool $incremental): StandardizationModelDto
    {
        return $this->standardizer->fit((function () use ($spool, $signal, $incremental): iterable {
            foreach ($spool->rows() as $row) {
                yield $this->rawFeatures($row, $incremental, $signal);
            }
        })());
    }

    private function standardizedSpool(
        Bt02EvaluationRowSpool $rows,
        Bt02SignalDefinitionDto $signal,
        string $labelCode,
        bool $incremental,
        StandardizationModelDto $model,
    ): ImmutableBt02Spool {
        return $this->trainingSpools->create((function () use ($rows, $signal, $labelCode, $incremental, $model): iterable {
            foreach ($rows->rows() as $row) {
                yield new LogisticTrainingRowDto(
                    array_values($this->standardizer->transform($model, $this->rawFeatures($row, $incremental, $signal))),
                    $row->label($labelCode),
                );
            }
        })());
    }

    private function validationLogLoss($fit, ImmutableBt02Spool $validation): float
    {
        $sum = 0.0;
        $count = 0;
        foreach ((new SpoolLogisticTrainingRowSource($validation))->rows() as $row) {
            $probability = $this->regression->probability($fit->intercept, $fit->coefficients, $row->features);
            $probability = min(max($probability, 1e-15), 1.0 - 1e-15);
            $sum -= $row->label * log($probability) + (1 - $row->label) * log(1.0 - $probability);
            $count++;
        }
        if ($count === 0) {
            throw new RuntimeException('BT-02 validation rows were empty.');
        }

        return $sum / $count;
    }

    private function storeModel(
        BacktestRun $run,
        BacktestFold $fold,
        BacktestSignalSpec $spec,
        Bt02FoldDefinitionDto $definition,
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

    /**
     * @return array{training: Bt02EvaluationRowSpool, inner_fit: Bt02EvaluationRowSpool, inner_validation: Bt02EvaluationRowSpool, evaluation: Bt02EvaluationRowSpool}
     */
    private function datasetSpools(Bt02FoldDefinitionDto $fold, Bt02SignalDefinitionDto $signal, Bt02SignalCohort $cohort): array
    {
        $spools = [];
        try {
            foreach ([
                'training' => [$fold->trainingFrom, $fold->trainingTo],
                'inner_fit' => [$fold->innerFitFrom, $fold->innerFitTo],
                'inner_validation' => [$fold->innerValidationFrom, $fold->innerValidationTo],
                'evaluation' => [$fold->evaluationFrom, $fold->evaluationTo],
            ] as $period => [$from, $to]) {
                $spools[$period] = Bt02EvaluationRowSpool::create(
                    $this->dataset->rows($from, $to, $signal->statCode, $cohort),
                    $this->temporaryDirectory,
                );
            }
        } catch (\Throwable $throwable) {
            foreach ($spools as $spool) {
                $spool->cleanup();
            }
            throw $throwable;
        }

        /** @var array{training: Bt02EvaluationRowSpool, inner_fit: Bt02EvaluationRowSpool, inner_validation: Bt02EvaluationRowSpool, evaluation: Bt02EvaluationRowSpool} $spools */
        return $spools;
    }

    /** @return iterable<float> */
    private function signalValues(Bt02EvaluationRowSpool $spool): iterable
    {
        foreach ($spool->rows() as $row) {
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
