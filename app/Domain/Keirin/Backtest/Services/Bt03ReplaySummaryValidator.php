<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Services;

use App\Domain\Keirin\Backtest\DTO\Bt03ComputedBinEffectDto;
use App\Domain\Keirin\Backtest\DTO\Bt03EvaluationReplaySummaryDto;
use App\Domain\Keirin\Backtest\DTO\Bt03ProductionScopeDto;
use App\Models\BacktestBinEffectScope;
use RuntimeException;

class Bt03ReplaySummaryValidator
{
    public function validate(
        BacktestBinEffectScope $scope,
        Bt03ProductionScopeDto $definition,
        Bt03EvaluationReplaySummaryDto $summary,
    ): void {
        if ($summary->foldCode !== $definition->foldCode
            || $summary->statCode !== $definition->statCode
            || $summary->cohortCode !== $definition->cohortCode
            || $summary->trainingBinCount !== (int) $scope->expected_training_bin_count
            || $summary->evaluationRowCount < 1
            || $summary->evaluationRaceCount < 1
            || $summary->evaluationRaceCount > $summary->evaluationRowCount
            || $summary->unseenRowCount < 0
            || $summary->spoolByteCount < 1
            || $summary->maximumBinSampleCount < 0
            || $summary->maximumBinRaceCount < 0) {
            throw new RuntimeException('BT-03 Production replay summary identity or counts were invalid.');
        }
        $expectedEffectCount = ($summary->trainingBinCount * Bt03ProductionContract::LABEL_COUNT)
            + ($summary->unseenRowCount > 0 ? Bt03ProductionContract::LABEL_COUNT : 0);
        if (count($summary->effects) !== $expectedEffectCount) {
            throw new RuntimeException('BT-03 Production replay effect count was incomplete.');
        }

        $byLabel = [];
        $trainingIdentity = [];
        foreach ($summary->effects as $effect) {
            if (! $effect instanceof Bt03ComputedBinEffectDto
                || ! in_array($effect->labelCode, Bt03ProductionContract::LABELS, true)
                || preg_match('/\A[0-9a-f]{64}\z/', $effect->effectHash) !== 1
                || $effect->result->bootstrapIterations !== Bt03ProductionContract::BOOTSTRAP_ITERATIONS
                || $effect->result->bootstrapSeed !== Bt03ProductionContract::BOOTSTRAP_SEED
                || ! hash_equals((string) $scope->source_boundaries_hash, $effect->bin->boundariesHash)) {
                throw new RuntimeException('BT-03 Production replay effect contract was invalid.');
            }
            foreach ([$effect->models->baseline, $effect->models->incremental] as $model) {
                if ($model->sourceRunId !== (int) $scope->source_backtest_run_id
                    || $model->sourceFoldId !== (int) $scope->source_backtest_fold_id
                    || $model->sourceSignalSpecId !== (int) $scope->source_backtest_signal_spec_id
                    || $model->foldCode !== $definition->foldCode
                    || $model->statCode !== $definition->statCode
                    || $model->cohortCode !== $definition->cohortCode
                    || $model->labelCode !== $effect->labelCode) {
                    throw new RuntimeException('BT-03 Production source model ownership was invalid.');
                }
            }
            if ($effect->models->baseline->modelRole !== 'BASELINE_MATCHED'
                || $effect->models->incremental->modelRole !== 'INCREMENTAL') {
                throw new RuntimeException('BT-03 Production source model roles were invalid.');
            }

            $key = $effect->labelCode.':'.$effect->bin->binIndex;
            if (isset($byLabel[$key])) {
                throw new RuntimeException('BT-03 Production replay effect identity was duplicated.');
            }
            $byLabel[$key] = true;

            if ($effect->bin->binOrigin === 'TRAINING_BIN') {
                if ($effect->bin->binIndex < 1 || $effect->bin->sourceEffectBinId === null) {
                    throw new RuntimeException('BT-03 Production training bin identity was invalid.');
                }
                $identity = [
                    $effect->bin->sourceEffectBinId,
                    $effect->bin->binKind,
                    $effect->bin->lowerBound,
                    $effect->bin->upperBound,
                    $effect->bin->categoryValue,
                    $effect->bin->trainingSampleCount,
                ];
                if (isset($trainingIdentity[$effect->bin->binIndex])
                    && $trainingIdentity[$effect->bin->binIndex] !== $identity) {
                    throw new RuntimeException('BT-03 Production training bin differed between labels.');
                }
                $trainingIdentity[$effect->bin->binIndex] = $identity;
            } elseif ($effect->bin->binOrigin !== 'UNSEEN_CATEGORY'
                || $effect->bin->binIndex !== 0
                || $effect->bin->sourceEffectBinId !== null
                || $effect->bin->binKind !== 'CATEGORY') {
                throw new RuntimeException('BT-03 Production UNSEEN bin identity was invalid.');
            }
        }

        ksort($trainingIdentity, SORT_NUMERIC);
        if (count($trainingIdentity) !== $summary->trainingBinCount) {
            throw new RuntimeException('BT-03 Production training bin set was incomplete.');
        }
        foreach (Bt03ProductionContract::LABELS as $label) {
            foreach (array_keys($trainingIdentity) as $binIndex) {
                if (! isset($byLabel[$label.':'.$binIndex])) {
                    throw new RuntimeException('BT-03 Production label training bin set was incomplete.');
                }
            }
            if (isset($byLabel[$label.':0']) !== ($summary->unseenRowCount > 0)) {
                throw new RuntimeException('BT-03 Production UNSEEN label set was inconsistent.');
            }
        }
    }
}
