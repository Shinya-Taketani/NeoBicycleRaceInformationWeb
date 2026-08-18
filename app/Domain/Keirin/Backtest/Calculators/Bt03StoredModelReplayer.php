<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Calculators;

use App\Domain\Keirin\Backtest\DTO\Bt03StoredModelDto;
use App\Domain\Keirin\Backtest\Services\Bt03SourceManifest;
use App\Domain\Keirin\Backtest\Support\Bt02ModelArtifactHasher;
use InvalidArgumentException;

class Bt03StoredModelReplayer
{
    private const PERSISTED_INTERCEPT_ULP_WINDOW = 512;

    public function __construct(
        private readonly RidgeLogisticRegression $regression,
        private readonly Bt02ModelArtifactHasher $hasher,
    ) {}

    /** @param array<string, int|float> $features */
    public function probability(Bt03StoredModelDto $model, array $features): float
    {
        $this->assertModel($model);

        return $this->probabilityFromValidatedModel($model, $features);
    }

    /** @param array<string, int|float> $features */
    public function probabilityFromValidatedModel(Bt03StoredModelDto $model, array $features): float
    {
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
        $this->assertContract($model);
        if (! hash_equals($model->modelHash, $this->hasher->hash($this->modelArtifact($model)))) {
            throw new InvalidArgumentException('BT-03 stored model hash mismatched.');
        }
    }

    public function restorePersistedModel(Bt03StoredModelDto $model): Bt03StoredModelDto
    {
        $this->assertContract($model);
        if (hash_equals($model->modelHash, $this->hasher->hash($this->modelArtifact($model)))) {
            return $model;
        }

        // PDO persisted scalar doubles at lower textual precision than the JSON artifacts in run 5.
        $bits = unpack('J', pack('E', $model->intercept))[1];
        $match = null;
        for ($offset = -self::PERSISTED_INTERCEPT_ULP_WINDOW; $offset <= self::PERSISTED_INTERCEPT_ULP_WINDOW; $offset++) {
            $candidate = $this->withIntercept($model, unpack('E', pack('J', $bits + $offset))[1]);
            if (! hash_equals($candidate->modelHash, $this->hasher->hash($this->modelArtifact($candidate)))) {
                continue;
            }
            if ($match !== null) {
                throw new InvalidArgumentException('BT-03 persisted model intercept recovery was ambiguous.');
            }
            $match = $candidate;
        }
        if ($match === null) {
            throw new InvalidArgumentException('BT-03 persisted model hash could not be restored.');
        }

        return $match;
    }

    private function assertContract(Bt03StoredModelDto $model): void
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
    }

    /** @return array<string, mixed> */
    private function modelArtifact(Bt03StoredModelDto $model): array
    {
        return [
            'feature_names' => $model->featureNames,
            'scaler_mean' => $model->scalerMean,
            'scaler_sd' => $model->scalerSd,
            'selected_lambda' => $model->selectedLambda,
            'intercept' => $model->intercept,
            'coefficients' => $model->coefficients,
            'objective_version' => $model->objectiveVersion,
            'optimizer_version' => $model->optimizerVersion,
            'probability_semantics' => $model->probabilitySemantics,
        ];
    }

    private function withIntercept(Bt03StoredModelDto $model, float $intercept): Bt03StoredModelDto
    {
        return new Bt03StoredModelDto(
            $model->modelId,
            $model->sourceRunId,
            $model->sourceFoldId,
            $model->sourceSignalSpecId,
            $model->foldCode,
            $model->statCode,
            $model->primaryFeatureCode,
            $model->cohortCode,
            $model->labelCode,
            $model->modelRole,
            $model->featureNames,
            $model->scalerMean,
            $model->scalerSd,
            $model->lambdaCandidates,
            $model->selectedLambda,
            $intercept,
            $model->coefficients,
            $model->objectiveVersion,
            $model->optimizerVersion,
            $model->probabilitySemantics,
            $model->convergenceStatus,
            $model->modelHash,
        );
    }
}
