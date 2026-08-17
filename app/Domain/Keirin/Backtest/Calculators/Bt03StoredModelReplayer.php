<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Calculators;

use App\Domain\Keirin\Backtest\DTO\Bt03StoredModelDto;
use App\Domain\Keirin\Backtest\Services\Bt03SourceManifest;
use App\Domain\Keirin\Backtest\Support\Bt02ModelArtifactHasher;
use InvalidArgumentException;

class Bt03StoredModelReplayer
{
    public function __construct(
        private readonly RidgeLogisticRegression $regression,
        private readonly Bt02ModelArtifactHasher $hasher,
    ) {}

    /** @param array<string, int|float> $features */
    public function probability(Bt03StoredModelDto $model, array $features): float
    {
        $this->assertModel($model);
        if (array_keys($features) !== $model->featureNames) {
            throw new InvalidArgumentException('BT-03 replay features did not match stored feature order.');
        }
        $standardized = [];
        foreach ($model->featureNames as $name) {
            $value = (float) $features[$name];
            if (! is_finite($value)) {
                throw new InvalidArgumentException('BT-03 replay feature was not finite.');
            }
            $sd = $model->scalerSd[$name];
            $standardized[] = $sd == 0.0 ? 0.0 : ($value - $model->scalerMean[$name]) / $sd;
        }

        return $this->regression->probability($model->intercept, $model->coefficients, $standardized);
    }

    public function assertModel(Bt03StoredModelDto $model): void
    {
        $expectedFeatures = $model->modelRole === 'BASELINE_MATCHED'
            ? ['STAT01_RACE_SCORE']
            : ['STAT01_RACE_SCORE', $model->primaryFeatureCode];
        if ($model->modelId < 1
            || $model->sourceRunId !== Bt03SourceManifest::SOURCE_BT02_RUN_ID
            || $model->sourceFoldId < 1
            || $model->sourceSignalSpecId < 1
            || ! in_array($model->foldCode, ['WF_2023', 'WF_2024', 'WF_2025'], true)
            || ! in_array($model->statCode, Bt03SourceManifest::ENTRY_STAT_CODES, true)
            || ! in_array($model->cohortCode, ['STRICT', 'OPERATIONAL'], true)
            || ! in_array($model->labelCode, ['IS_WIN', 'IS_TOP2', 'IS_TOP3'], true)
            || ! in_array($model->modelRole, ['BASELINE_MATCHED', 'INCREMENTAL'], true)
            || $model->featureNames !== $expectedFeatures
            || array_keys($model->scalerMean) !== $model->featureNames
            || array_keys($model->scalerSd) !== $model->featureNames
            || count($model->coefficients) !== count($model->featureNames)
            || ! in_array($model->selectedLambda, $model->lambdaCandidates, true)
            || $model->objectiveVersion !== Bt03SourceManifest::OBJECTIVE_VERSION
            || $model->optimizerVersion !== Bt03SourceManifest::OPTIMIZER_VERSION
            || $model->probabilitySemantics !== Bt03SourceManifest::PROBABILITY_SEMANTICS
            || ! str_starts_with($model->convergenceStatus, 'CONVERGED_')) {
            throw new InvalidArgumentException('BT-03 stored model contract was invalid.');
        }
        foreach ([...array_values($model->scalerMean), ...array_values($model->scalerSd), ...$model->lambdaCandidates, $model->selectedLambda, $model->intercept, ...$model->coefficients] as $value) {
            if (! is_float($value) || ! is_finite($value)) {
                throw new InvalidArgumentException('BT-03 stored model numeric artifact was invalid.');
            }
        }
        $actualHash = $this->hasher->hash([
            'feature_names' => $model->featureNames,
            'scaler_mean' => $model->scalerMean,
            'scaler_sd' => $model->scalerSd,
            'selected_lambda' => $model->selectedLambda,
            'intercept' => $model->intercept,
            'coefficients' => $model->coefficients,
            'objective_version' => $model->objectiveVersion,
            'optimizer_version' => $model->optimizerVersion,
            'probability_semantics' => $model->probabilitySemantics,
        ]);
        if (! hash_equals($model->modelHash, $actualHash)) {
            throw new InvalidArgumentException('BT-03 stored model hash mismatched.');
        }
    }
}
