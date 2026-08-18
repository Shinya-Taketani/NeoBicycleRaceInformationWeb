<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Services;

use App\Domain\Keirin\Backtest\Calculators\Bt03StoredModelReplayer;
use App\Domain\Keirin\Backtest\Contracts\Bt03EvaluationSourceProvider;
use App\Domain\Keirin\Backtest\DTO\Bt03EvaluationSourceDto;
use App\Domain\Keirin\Backtest\DTO\Bt03ExpectedPredictionManifestsDto;
use App\Domain\Keirin\Backtest\DTO\Bt03ModelPairDto;
use App\Domain\Keirin\Backtest\DTO\Bt03SourceBinDto;
use App\Domain\Keirin\Backtest\DTO\Bt03StoredModelDto;
use App\Domain\Keirin\Backtest\DTO\FoldDefinitionDto;
use App\Domain\Keirin\Backtest\Repositories\Bt03EvaluationSourceRepository;
use DateTimeImmutable;
use RuntimeException;

class Bt03EvaluationSourceLoader implements Bt03EvaluationSourceProvider
{
    /** @var list<string> */
    private const LABELS = ['IS_WIN', 'IS_TOP2', 'IS_TOP3'];

    /** @var list<string> */
    private const METRICS = ['AUC', 'LOG_LOSS', 'BRIER'];

    public function __construct(
        private readonly Bt03EvaluationSourceRepository $source,
        private readonly Bt03StoredModelReplayer $models,
        private readonly FinalHoldoutGuard $holdoutGuard,
    ) {}

    public function load(string $foldCode, string $statCode, string $cohortCode): Bt03EvaluationSourceDto
    {
        if (! in_array($foldCode, ['WF_2023', 'WF_2024', 'WF_2025'], true)
            || ! in_array($statCode, Bt03SourceManifest::ENTRY_STAT_CODES, true)
            || ! in_array($cohortCode, ['STRICT', 'OPERATIONAL'], true)) {
            throw new RuntimeException('BT-03 evaluation scope was outside the fixed source contract.');
        }
        $fold = $this->exactlyOne($this->source->folds($foldCode), 'source fold');
        $evaluationFrom = new DateTimeImmutable((string) $fold->evaluation_from);
        $evaluationTo = new DateTimeImmutable((string) $fold->evaluation_to);
        if ((int) $fold->backtest_run_id !== Bt03SourceManifest::SOURCE_BT02_RUN_ID
            || $fold->fold_code !== $foldCode || $fold->status !== 'SUCCEEDED'
            || $evaluationFrom > $evaluationTo) {
            throw new RuntimeException('BT-03 source fold contract was invalid.');
        }
        $this->holdoutGuard->assertAllowed(new FoldDefinitionDto(
            $foldCode,
            (int) $fold->sequence,
            $fold->train_from === null ? null : new DateTimeImmutable((string) $fold->train_from),
            $fold->train_to === null ? null : new DateTimeImmutable((string) $fold->train_to),
            $evaluationFrom,
            $evaluationTo,
        ));

        $spec = $this->exactlyOne($this->source->signalSpecs($statCode), 'source signal spec');
        if ((int) $spec->backtest_run_id !== Bt03SourceManifest::SOURCE_BT02_RUN_ID
            || $spec->stat_code !== $statCode || $spec->analysis_role !== 'ENTRY_INCREMENTAL'
            || ! is_string($spec->primary_feature_code) || $spec->primary_feature_code === '') {
            throw new RuntimeException('BT-03 source signal spec contract was invalid.');
        }
        $foldId = (int) $fold->id;
        $specId = (int) $spec->id;
        $pairs = $this->modelPairs($foldId, $specId, $foldCode, $statCode, (string) $spec->primary_feature_code, $cohortCode);
        $expectedPredictionManifests = $this->expectedPredictionManifests($foldId, $specId, $cohortCode, $pairs);
        $bins = $this->bins($foldId, $specId, $cohortCode);

        return new Bt03EvaluationSourceDto(
            Bt03SourceManifest::SOURCE_BT02_RUN_ID,
            $foldId,
            $foldCode,
            $evaluationFrom,
            $evaluationTo,
            $specId,
            $statCode,
            (string) $spec->primary_feature_code,
            $cohortCode,
            $pairs,
            $expectedPredictionManifests,
            $bins,
        );
    }

    /** @return array<string, Bt03ModelPairDto> */
    private function modelPairs(int $foldId, int $specId, string $foldCode, string $statCode, string $primaryFeatureCode, string $cohortCode): array
    {
        $grouped = [];
        foreach ($this->source->models($foldId, $specId, $cohortCode) as $row) {
            if ((int) $row->backtest_run_id !== Bt03SourceManifest::SOURCE_BT02_RUN_ID
                || (int) $row->backtest_fold_id !== $foldId
                || (int) $row->backtest_signal_spec_id !== $specId
                || $row->cohort_code !== $cohortCode
                || ! in_array($row->label_code, self::LABELS, true)
                || ! in_array($row->model_role, ['BASELINE_MATCHED', 'INCREMENTAL'], true)
                || isset($grouped[$row->label_code][$row->model_role])) {
                throw new RuntimeException('BT-03 source model ownership or uniqueness was invalid.');
            }
            $model = $this->models->restorePersistedModel(
                $this->model($row, $foldCode, $statCode, $primaryFeatureCode),
            );
            $this->models->assertModel($model);
            $grouped[$model->labelCode][$model->modelRole] = $model;
        }
        $pairs = [];
        foreach (self::LABELS as $label) {
            if (array_keys($grouped[$label] ?? []) !== ['BASELINE_MATCHED', 'INCREMENTAL']
                && array_keys($grouped[$label] ?? []) !== ['INCREMENTAL', 'BASELINE_MATCHED']) {
                throw new RuntimeException("BT-03 {$label} required exactly one stored model pair.");
            }
            $pairs[$label] = new Bt03ModelPairDto(
                $grouped[$label]['BASELINE_MATCHED'],
                $grouped[$label]['INCREMENTAL'],
            );
        }
        if (count($grouped) !== count(self::LABELS)) {
            throw new RuntimeException('BT-03 source model labels exceeded the fixed contract.');
        }

        return $pairs;
    }

    /**
     * @param  array<string, Bt03ModelPairDto>  $pairs
     * @return array<string, Bt03ExpectedPredictionManifestsDto>
     */
    private function expectedPredictionManifests(int $foldId, int $specId, string $cohortCode, array $pairs): array
    {
        $grouped = [];
        foreach ($this->source->metrics($foldId, $specId, $cohortCode) as $row) {
            $label = $row->label_code ?? null;
            $metric = $row->metric_code ?? null;
            if ((int) ($row->backtest_run_id ?? 0) !== Bt03SourceManifest::SOURCE_BT02_RUN_ID
                || (int) ($row->backtest_fold_id ?? 0) !== $foldId
                || (int) ($row->backtest_signal_spec_id ?? 0) !== $specId
                || ($row->cohort_code ?? null) !== $cohortCode
                || ! is_string($label) || ! in_array($label, self::LABELS, true)
                || ! is_string($metric) || ! in_array($metric, self::METRICS, true)
                || isset($grouped[$label][$metric])) {
                throw new RuntimeException('BT-03 source metric ownership or uniqueness was invalid.');
            }
            $metadata = $this->json($row->metadata ?? null, 'metric metadata');
            $grouped[$label][$metric] = [
                'baseline' => $this->sha256($metadata['baseline_prediction_manifest_hash'] ?? null, 'metric baseline prediction manifest'),
                'incremental' => $this->sha256($metadata['incremental_prediction_manifest_hash'] ?? null, 'metric incremental prediction manifest'),
                'outcome' => $this->sha256($metadata['outcome_manifest_hash'] ?? null, 'metric outcome manifest'),
            ];
        }

        $expected = [];
        foreach (self::LABELS as $label) {
            $metrics = $grouped[$label] ?? [];
            $metricCodes = array_keys($metrics);
            sort($metricCodes, SORT_STRING);
            $expectedMetricCodes = self::METRICS;
            sort($expectedMetricCodes, SORT_STRING);
            if ($metricCodes !== $expectedMetricCodes) {
                throw new RuntimeException("BT-03 {$label} required exactly AUC, LOG_LOSS, and BRIER source metrics.");
            }
            $contract = $metrics[self::METRICS[0]];
            foreach (self::METRICS as $metric) {
                if ($metrics[$metric] !== $contract) {
                    throw new RuntimeException("BT-03 {$label} source metric manifests were inconsistent.");
                }
            }
            $pair = $pairs[$label] ?? throw new RuntimeException("BT-03 {$label} source model pair was missing.");
            if (! hash_equals($pair->baseline->predictionManifestHash, $contract['baseline'])
                || ! hash_equals($pair->incremental->predictionManifestHash, $contract['incremental'])) {
                throw new RuntimeException("BT-03 {$label} model and metric prediction manifests were inconsistent.");
            }
            $expected[$label] = new Bt03ExpectedPredictionManifestsDto(
                $contract['baseline'],
                $contract['incremental'],
                $contract['outcome'],
            );
        }
        if (count($grouped) !== count(self::LABELS)) {
            throw new RuntimeException('BT-03 source metric labels exceeded the fixed contract.');
        }

        return $expected;
    }

    /** @return list<Bt03SourceBinDto> */
    private function bins(int $foldId, int $specId, string $cohortCode): array
    {
        $bins = [];
        $kind = $boundariesHash = null;
        $lastIndex = 0;
        foreach ($this->source->bins($foldId, $specId, $cohortCode) as $row) {
            $index = (int) $row->bin_index;
            $lower = $this->nullableFloat($row->lower_bound);
            $upper = $this->nullableFloat($row->upper_bound);
            if ((int) $row->backtest_run_id !== Bt03SourceManifest::SOURCE_BT02_RUN_ID
                || (int) $row->backtest_fold_id !== $foldId
                || (int) $row->backtest_signal_spec_id !== $specId
                || $row->cohort_code !== $cohortCode
                || (int) $row->id < 1 || $index <= $lastIndex
                || (int) $row->training_sample_count < 1
                || ! in_array($row->bin_kind, ['NUMERIC_RANGE', 'CATEGORY'], true)
                || ($kind !== null && $row->bin_kind !== $kind)
                || ! is_string($row->boundaries_hash)
                || preg_match('/\A[0-9a-f]{64}\z/', $row->boundaries_hash) !== 1
                || ($boundariesHash !== null && $row->boundaries_hash !== $boundariesHash)
                || ! $this->validBinShape((string) $row->bin_kind, $lower, $upper, $row->category_value)) {
                throw new RuntimeException('BT-03 fixed source bin contract was invalid.');
            }
            $kind ??= (string) $row->bin_kind;
            $boundariesHash ??= (string) $row->boundaries_hash;
            $lastIndex = $index;
            $bins[] = new Bt03SourceBinDto(
                (int) $row->id,
                $index,
                (string) $row->bin_kind,
                $lower,
                $upper,
                $row->category_value === null ? null : (string) $row->category_value,
                (int) $row->training_sample_count,
                (string) $row->boundaries_hash,
            );
        }
        if ($bins === []) {
            throw new RuntimeException('BT-03 fixed source bins were unavailable.');
        }

        return $bins;
    }

    private function model(object $row, string $foldCode, string $statCode, string $primaryFeatureCode): Bt03StoredModelDto
    {
        $featureNames = $this->stringList($row->feature_names, 'feature names');

        return new Bt03StoredModelDto(
            (int) $row->id,
            (int) $row->backtest_run_id,
            (int) $row->backtest_fold_id,
            (int) $row->backtest_signal_spec_id,
            $foldCode,
            $statCode,
            $primaryFeatureCode,
            (string) $row->cohort_code,
            (string) $row->label_code,
            (string) $row->model_role,
            $featureNames,
            $this->orderedFloatMap($row->scaler_mean, $featureNames, 'scaler mean'),
            $this->orderedFloatMap($row->scaler_sd, $featureNames, 'scaler sd'),
            $this->floatList($row->lambda_candidates, 'lambda candidates'),
            $this->number($row->selected_lambda, 'selected lambda'),
            $this->number($row->intercept, 'intercept'),
            $this->floatList($row->coefficients, 'coefficients'),
            (string) $row->objective_version,
            (string) $row->optimizer_version,
            (string) $row->probability_semantics,
            (string) $row->convergence_status,
            (string) $row->model_hash,
            $this->sha256($row->prediction_manifest_hash ?? null, 'model prediction manifest'),
        );
    }

    /** @param list<object> $rows */
    private function exactlyOne(array $rows, string $artifact): object
    {
        if (count($rows) !== 1) {
            throw new RuntimeException("BT-03 {$artifact} was not exactly one row.");
        }

        return $rows[0];
    }

    private function validBinShape(string $kind, ?float $lower, ?float $upper, mixed $category): bool
    {
        return $kind === 'CATEGORY'
            ? is_string($category) && $category !== '' && $lower === null && $upper === null
            : $category === null && ($lower === null || $upper === null || $lower < $upper);
    }

    private function nullableFloat(mixed $value): ?float
    {
        return $value === null ? null : $this->number($value, 'bin boundary');
    }

    private function number(mixed $value, string $name): float
    {
        if (! is_int($value) && ! is_float($value) && ! (is_string($value) && is_numeric($value))) {
            throw new RuntimeException("BT-03 {$name} was not numeric.");
        }
        $number = (float) $value;
        if (! is_finite($number)) {
            throw new RuntimeException("BT-03 {$name} was not finite.");
        }

        return $number;
    }

    private function sha256(mixed $value, string $name): string
    {
        if (! is_string($value) || preg_match('/\A[0-9a-f]{64}\z/', $value) !== 1) {
            throw new RuntimeException("BT-03 {$name} was invalid.");
        }

        return $value;
    }

    /** @return list<string> */
    private function stringList(mixed $value, string $name): array
    {
        $decoded = $this->json($value, $name);
        if (! array_is_list($decoded) || $decoded === [] || array_filter($decoded, 'is_string') !== $decoded) {
            throw new RuntimeException("BT-03 {$name} was invalid.");
        }

        return $decoded;
    }

    /** @return list<float> */
    private function floatList(mixed $value, string $name): array
    {
        $decoded = $this->json($value, $name);
        if (! array_is_list($decoded) || $decoded === []) {
            throw new RuntimeException("BT-03 {$name} was invalid.");
        }

        return array_map(fn (mixed $item): float => $this->number($item, $name), $decoded);
    }

    /** @param list<string> $names @return array<string, float> */
    private function orderedFloatMap(mixed $value, array $names, string $name): array
    {
        $decoded = $this->json($value, $name);
        $ordered = [];
        foreach ($names as $feature) {
            if (! array_key_exists($feature, $decoded)) {
                throw new RuntimeException("BT-03 {$name} omitted {$feature}.");
            }
            $ordered[$feature] = $this->number($decoded[$feature], $name);
        }
        if (count($ordered) !== count($decoded)) {
            throw new RuntimeException("BT-03 {$name} contained an unknown feature.");
        }

        return $ordered;
    }

    /** @return array<mixed> */
    private function json(mixed $value, string $name): array
    {
        $decoded = is_string($value) ? json_decode($value, true, 512, JSON_THROW_ON_ERROR) : $value;
        if (! is_array($decoded)) {
            throw new RuntimeException("BT-03 {$name} JSON was invalid.");
        }

        return $decoded;
    }
}
