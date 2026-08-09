<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Repositories;

use App\Domain\Keirin\Backtest\DTO\FoldDefinitionDto;
use App\Domain\Keirin\Backtest\DTO\PredictionDto;
use App\Domain\Keirin\Backtest\DTO\VerifiedSourceDto;
use App\Domain\Keirin\Backtest\Enums\BacktestExclusionReason;
use App\Models\BacktestExclusion;
use App\Models\BacktestFeatureSource;
use App\Models\BacktestFold;
use App\Models\BacktestMetric;
use App\Models\BacktestPrediction;
use App\Models\BacktestRun;
use DateTimeImmutable;
use Illuminate\Support\Str;

class BacktestAuditRepository
{
    /** @param array<string, mixed> $parameters */
    public function startRun(string $manifestVersion, string $manifestHash, string $calculationVersion, string $ruleVersion, string $holdoutPolicy, array $parameters): BacktestRun
    {
        return BacktestRun::query()->create([
            'run_uuid' => (string) Str::uuid(),
            'backtest_code' => 'BT-01',
            'calculation_version' => $calculationVersion,
            'status' => 'RUNNING',
            'holdout_policy' => $holdoutPolicy,
            'source_manifest_version' => $manifestVersion,
            'source_manifest_hash' => $manifestHash,
            'prediction_rule_version' => $ruleVersion,
            'parameters' => $parameters,
            'started_at' => new DateTimeImmutable('now'),
        ]);
    }

    /** @param list<VerifiedSourceDto> $sources */
    public function storeSources(BacktestRun $run, array $sources, string $manifestHash): void
    {
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
    }

    public function startFold(BacktestRun $run, FoldDefinitionDto $definition): BacktestFold
    {
        return BacktestFold::query()->create([
            'backtest_run_id' => $run->id,
            'fold_code' => $definition->code,
            'sequence' => $definition->sequence,
            'train_from' => $definition->trainFrom,
            'train_to' => $definition->trainTo,
            'evaluation_from' => $definition->evaluationFrom,
            'evaluation_to' => $definition->evaluationTo,
            'status' => 'RUNNING',
            'started_at' => new DateTimeImmutable('now'),
        ]);
    }

    /** @param list<PredictionDto> $predictions */
    public function storePredictions(BacktestRun $run, BacktestFold $fold, array $predictions): void
    {
        foreach ($predictions as $prediction) {
            BacktestPrediction::query()->create([
                'backtest_run_id' => $run->id,
                'backtest_fold_id' => $fold->id,
                'race_id' => $prediction->raceId,
                'race_entry_id' => $prediction->raceEntryId,
                'player_id' => $prediction->playerId,
                'bike_number' => $prediction->bikeNumber,
                'feature_run_id' => $prediction->featureRunId,
                'feature_result_id' => $prediction->featureResultId,
                'source_input_hash' => $prediction->sourceInputHash,
                'prediction_rule_version' => 'STAT01-RACE-SCORE-RANK-v1',
                'prediction_score' => $prediction->predictionScore,
                'predicted_rank' => $prediction->predictedRank,
                'is_rank1_set' => $prediction->isRank1Set,
                'is_top3_set' => $prediction->isTop3Set,
                'prediction_hash' => $prediction->predictionHash,
                'locked_at' => new DateTimeImmutable('now'),
            ]);
        }
    }

    /** @param array<string, mixed>|null $details */
    public function exclude(BacktestRun $run, BacktestFold $fold, int $raceId, string $stage, BacktestExclusionReason $reason, ?array $details = null): void
    {
        BacktestExclusion::query()->create([
            'backtest_run_id' => $run->id,
            'backtest_fold_id' => $fold->id,
            'race_id' => $raceId,
            'stage' => $stage,
            'reason_code' => $reason->value,
            'details' => $details,
        ]);
    }

    /** @param list<array{cohort: string, metric: string, numerator: int, denominator: int, sample_count: int, value: ?float}> $rows */
    public function storeMetrics(BacktestRun $run, BacktestFold $fold, array $rows): void
    {
        foreach ($rows as $row) {
            BacktestMetric::query()->create([
                'backtest_run_id' => $run->id,
                'backtest_fold_id' => $fold->id,
                'cohort_code' => $row['cohort'],
                'metric_code' => $row['metric'],
                'numerator' => $row['numerator'],
                'denominator' => $row['denominator'],
                'sample_count' => $row['sample_count'],
                'metric_value' => $row['value'],
                'calculated_at' => new DateTimeImmutable('now'),
            ]);
        }
    }

    public function finishFold(BacktestFold $fold, int $target, int $predicted, int $excluded, string $predictionHash, string $labelHash): void
    {
        $fold->forceFill([
            'status' => $excluded > 0 ? 'PARTIALLY_SUCCEEDED' : 'SUCCEEDED',
            'target_race_count' => $target,
            'predicted_race_count' => $predicted,
            'excluded_race_count' => $excluded,
            'prediction_manifest_hash' => $predictionHash,
            'label_manifest_hash' => $labelHash,
            'finished_at' => new DateTimeImmutable('now'),
        ])->save();
    }

    public function finishRun(BacktestRun $run, int $target, int $predicted, int $excluded, int $errors, ?string $error): void
    {
        $run->forceFill([
            'status' => $errors > 0 ? 'FAILED' : ($excluded > 0 ? 'PARTIALLY_SUCCEEDED' : 'SUCCEEDED'),
            'target_race_count' => $target,
            'predicted_race_count' => $predicted,
            'excluded_race_count' => $excluded,
            'error_count' => $errors,
            'error_summary' => $error,
            'finished_at' => new DateTimeImmutable('now'),
        ])->save();
    }
}
