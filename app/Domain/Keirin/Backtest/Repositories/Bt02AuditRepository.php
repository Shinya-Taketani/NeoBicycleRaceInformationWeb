<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Repositories;

use App\Domain\Keirin\Backtest\DTO\Bt02FoldDefinitionDto;
use App\Domain\Keirin\Backtest\DTO\Bt02SignalDefinitionDto;
use App\Domain\Keirin\Backtest\DTO\Bt02SourceManifestEntryDto;
use App\Domain\Keirin\Backtest\DTO\EffectBinDto;
use App\Domain\Keirin\Backtest\DTO\VerifiedSourceDto;
use App\Domain\Keirin\Backtest\Services\Bt02SourceManifest;
use App\Models\BacktestEffectBin;
use App\Models\BacktestFeatureSource;
use App\Models\BacktestFold;
use App\Models\BacktestModel;
use App\Models\BacktestRun;
use App\Models\BacktestSignalMetric;
use App\Models\BacktestSignalSpec;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;

class Bt02AuditRepository
{
    /** @param array<string, mixed> $parameters */
    public function startRun(array $parameters): BacktestRun
    {
        return BacktestRun::query()->create([
            'run_uuid' => (string) Str::uuid(),
            'backtest_code' => 'BT-02',
            'calculation_version' => 'BT02-SIGNAL-EVALUATION-v1',
            'status' => 'RUNNING',
            'holdout_policy' => 'BLOCK_AFTER_2025-12-31',
            'source_manifest_version' => Bt02SourceManifest::VERSION,
            'source_manifest_hash' => Bt02SourceManifest::HASH,
            'prediction_rule_version' => 'INCREMENTAL_SIGNAL_REFERENCE_MODEL',
            'parameters' => [
                ...$parameters,
                'source_fingerprint_version' => Bt02SourceManifest::SOURCE_FINGERPRINT_VERSION,
                'content_fingerprint_version' => Bt02SourceManifest::CONTENT_FINGERPRINT_VERSION,
            ],
            'started_at' => new DateTimeImmutable('now'),
        ]);
    }

    /** @param list<Bt02SourceManifestEntryDto> $sources */
    public function storeSources(BacktestRun $run, array $sources): void
    {
        DB::transaction(function () use ($run, $sources): void {
            foreach ($sources as $source) {
                BacktestFeatureSource::query()->create([
                    'backtest_run_id' => $run->id,
                    'stat_code' => $source->statCode,
                    'feature_run_id' => $source->featureRunId,
                    'feature_run_uuid' => $source->featureRunUuid,
                    'calculation_version' => $source->calculationVersion,
                    'target_from' => $source->targetFrom,
                    'target_to' => $source->targetTo,
                    'expected_race_count' => $source->processedRaceCount,
                    'expected_result_count' => $source->rowCount,
                    'verified_race_count' => $source->processedRaceCount,
                    'verified_result_count' => $source->rowCount,
                    'source_manifest_hash' => Bt02SourceManifest::HASH,
                    'verified_at' => new DateTimeImmutable('now'),
                ]);
            }
        });
    }

    /** @param list<VerifiedSourceDto> $sources */
    public function storeBaselineSources(BacktestRun $run, array $sources, string $manifestHash): void
    {
        DB::transaction(function () use ($run, $sources, $manifestHash): void {
            foreach ($sources as $source) {
                BacktestFeatureSource::query()->create([
                    'backtest_run_id' => $run->id,
                    'stat_code' => 'STAT-01',
                    'feature_run_id' => $source->manifest->featureRunId,
                    'feature_run_uuid' => $source->manifest->featureRunUuid,
                    'calculation_version' => 'STAT-01-existing-db-v1',
                    'target_from' => $source->manifest->targetFrom,
                    'target_to' => $source->manifest->targetTo,
                    'expected_race_count' => $source->manifest->expectedRaceCount,
                    'expected_result_count' => $source->manifest->expectedResultCount,
                    'verified_race_count' => $source->verifiedRaceCount,
                    'verified_result_count' => $source->verifiedResultCount,
                    'source_manifest_hash' => $manifestHash,
                    'verified_at' => new DateTimeImmutable('now'),
                ]);
            }
        });
    }

    public function startFold(BacktestRun $run, Bt02FoldDefinitionDto $definition): BacktestFold
    {
        return BacktestFold::query()->create([
            'backtest_run_id' => $run->id,
            'fold_code' => $definition->code,
            'sequence' => $definition->sequence,
            'train_from' => $definition->trainingFrom,
            'train_to' => $definition->trainingTo,
            'evaluation_from' => $definition->evaluationFrom,
            'evaluation_to' => $definition->evaluationTo,
            'status' => 'RUNNING',
            'started_at' => new DateTimeImmutable('now'),
        ]);
    }

    /** @param array<string, mixed>|null $parameters */
    public function storeSignalSpec(BacktestRun $run, Bt02SignalDefinitionDto $definition, ?array $parameters = null): BacktestSignalSpec
    {
        return BacktestSignalSpec::query()->create([
            'backtest_run_id' => $run->id,
            'stat_code' => $definition->statCode,
            'subject_type' => $definition->subjectType,
            'analysis_role' => $definition->analysisRole->value,
            'primary_feature_code' => $definition->primaryFeatureCode,
            'primary_feature_path' => $definition->primaryFeaturePath,
            'transform_code' => $definition->transformCode,
            'strict_policy_version' => 'BT02-STRICT-v1',
            'operational_policy_version' => 'BT02-OPERATIONAL-v1',
            'operational_allowed_quality_reasons' => $definition->operationalAllowedQualityReasons,
            'source_manifest_version' => Bt02SourceManifest::VERSION,
            'source_manifest_hash' => Bt02SourceManifest::HASH,
            'parameters' => $parameters,
        ]);
    }

    /** @param array<string, mixed> $artifact */
    public function storeModel(BacktestRun $run, BacktestFold $fold, BacktestSignalSpec $spec, array $artifact): BacktestModel
    {
        $this->assertOwnership($run, $fold, $spec);

        return BacktestModel::query()->create([
            ...$artifact,
            'backtest_run_id' => $run->id,
            'backtest_fold_id' => $fold->id,
            'backtest_signal_spec_id' => $spec->id,
        ]);
    }

    /** @param array<string, mixed> $metric */
    public function storeMetric(BacktestRun $run, BacktestFold $fold, BacktestSignalSpec $spec, array $metric): BacktestSignalMetric
    {
        $this->assertOwnership($run, $fold, $spec);

        return BacktestSignalMetric::query()->create([
            ...$metric,
            'backtest_run_id' => $run->id,
            'backtest_fold_id' => $fold->id,
            'backtest_signal_spec_id' => $spec->id,
            'calculated_at' => new DateTimeImmutable('now'),
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $models
     * @param  list<array<string, mixed>>  $metrics
     */
    public function storePairedEvaluationArtifacts(
        BacktestRun $run,
        BacktestFold $fold,
        BacktestSignalSpec $spec,
        array $models,
        array $metrics,
    ): void {
        $this->assertOwnership($run, $fold, $spec);
        if (count($models) !== 2 || count($metrics) !== 3) {
            throw new LogicException('BT-02 paired evaluation requires exactly two models and three metrics.');
        }

        DB::transaction(function () use ($run, $fold, $spec, $models, $metrics): void {
            foreach ($models as $model) {
                $this->storeModel($run, $fold, $spec, $model);
            }
            foreach ($metrics as $metric) {
                $this->storeMetric($run, $fold, $spec, $metric);
            }
        });
    }

    /** @param list<EffectBinDto> $bins @param array<string, mixed>|null $metadata */
    public function storeEffectBins(BacktestRun $run, BacktestFold $fold, BacktestSignalSpec $spec, string $cohort, string $boundariesHash, array $bins, ?array $metadata = null): void
    {
        $this->assertOwnership($run, $fold, $spec);

        DB::transaction(function () use ($run, $fold, $spec, $cohort, $boundariesHash, $bins, $metadata): void {
            foreach ($bins as $bin) {
                BacktestEffectBin::query()->create([
                    'backtest_run_id' => $run->id,
                    'backtest_fold_id' => $fold->id,
                    'backtest_signal_spec_id' => $spec->id,
                    'cohort_code' => $cohort,
                    'bin_index' => $bin->index,
                    'bin_kind' => $bin->kind,
                    'lower_bound' => $bin->lowerBound,
                    'upper_bound' => $bin->upperBound,
                    'category_value' => $bin->categoryValue,
                    'training_sample_count' => $bin->trainingSampleCount,
                    'boundaries_hash' => $boundariesHash,
                    'metadata' => $metadata,
                ]);
            }
        });
    }

    public function finishFold(BacktestFold $fold, int $targetRaces, int $evaluatedRaces, string $predictionManifestHash): void
    {
        $this->assertRaceCounts($targetRaces, $evaluatedRaces);
        $fold->forceFill([
            'status' => 'SUCCEEDED',
            'target_race_count' => $targetRaces,
            'predicted_race_count' => $evaluatedRaces,
            'excluded_race_count' => $targetRaces - $evaluatedRaces,
            'prediction_manifest_hash' => $predictionManifestHash,
            'finished_at' => new DateTimeImmutable('now'),
        ])->save();
    }

    public function failFold(BacktestFold $fold, int $targetRaces, int $evaluatedRaces, ?string $predictionManifestHash): void
    {
        $this->assertRaceCounts($targetRaces, $evaluatedRaces);
        $fold->forceFill([
            'status' => 'FAILED',
            'target_race_count' => $targetRaces,
            'predicted_race_count' => $evaluatedRaces,
            'excluded_race_count' => $targetRaces - $evaluatedRaces,
            'prediction_manifest_hash' => $predictionManifestHash,
            'finished_at' => new DateTimeImmutable('now'),
        ])->save();
    }

    public function finishRun(BacktestRun $run, string $status, int $targetRaces, int $evaluatedRaces, int $errors, ?string $error): void
    {
        if (! in_array($status, ['SUCCEEDED', 'PARTIALLY_SUCCEEDED', 'FAILED'], true)) {
            throw new LogicException('BT-02 run finish status was invalid.');
        }
        $this->assertRaceCounts($targetRaces, $evaluatedRaces);
        $run->forceFill([
            'status' => $status,
            'target_race_count' => $targetRaces,
            'predicted_race_count' => $evaluatedRaces,
            'excluded_race_count' => $targetRaces - $evaluatedRaces,
            'error_count' => $errors,
            'error_summary' => $error,
            'finished_at' => new DateTimeImmutable('now'),
        ])->save();
    }

    private function assertOwnership(BacktestRun $run, BacktestFold $fold, BacktestSignalSpec $spec): void
    {
        if ((int) $fold->backtest_run_id !== (int) $run->id || (int) $spec->backtest_run_id !== (int) $run->id) {
            throw new LogicException('BT-02 fold and signal spec must belong to the supplied run.');
        }
    }

    private function assertRaceCounts(int $targetRaces, int $evaluatedRaces): void
    {
        if ($targetRaces < 0 || $evaluatedRaces < 0 || $evaluatedRaces > $targetRaces) {
            throw new RuntimeException('BT-02 audit race counts were invalid.');
        }
    }
}
