<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Services;

use App\Domain\Keirin\Backtest\Calculators\Bt03BinEffectCalculator;
use App\Domain\Keirin\Backtest\Calculators\Bt03FixedBinAssigner;
use App\Domain\Keirin\Backtest\Calculators\Bt03StoredModelReplayer;
use App\Domain\Keirin\Backtest\Calculators\RaceClusterBootstrap;
use App\Domain\Keirin\Backtest\Contracts\Bt02EvaluationDataset;
use App\Domain\Keirin\Backtest\Contracts\Bt03EvaluationSourceProvider;
use App\Domain\Keirin\Backtest\DTO\Bt02EvaluationRowDto;
use App\Domain\Keirin\Backtest\DTO\Bt03BinAssignmentDto;
use App\Domain\Keirin\Backtest\DTO\Bt03BinEffectEntryDto;
use App\Domain\Keirin\Backtest\DTO\Bt03BinEffectResultDto;
use App\Domain\Keirin\Backtest\DTO\Bt03BinSpoolIdentityDto;
use App\Domain\Keirin\Backtest\DTO\Bt03ComputedBinEffectDto;
use App\Domain\Keirin\Backtest\DTO\Bt03EvaluationReplaySelectionDto;
use App\Domain\Keirin\Backtest\DTO\Bt03EvaluationReplaySummaryDto;
use App\Domain\Keirin\Backtest\DTO\Bt03EvaluationSourceDto;
use App\Domain\Keirin\Backtest\DTO\Bt03ModelPairDto;
use App\Domain\Keirin\Backtest\Enums\Bt02SignalCohort;
use App\Domain\Keirin\Backtest\Support\Bt03BinEffectSpool;
use App\Domain\Keirin\Backtest\Support\Bt03EffectHasher;
use RuntimeException;

class Bt03EvaluationReplayService
{
    /** @var list<string> */
    public const LABELS = ['IS_WIN', 'IS_TOP2', 'IS_TOP3'];

    public function __construct(
        private readonly Bt03EvaluationSourceProvider $source,
        private readonly Bt02EvaluationDataset $dataset,
        private readonly Bt03FixedBinAssigner $binAssigner,
        private readonly Bt03StoredModelReplayer $modelReplayer,
        private readonly Bt03BinEffectCalculator $calculator,
        private readonly Bt03EffectHasher $effectHasher,
        private readonly ?string $temporaryDirectory = null,
    ) {}

    public function replay(
        string $foldCode,
        string $statCode,
        string $cohortCode,
        int $iterations = RaceClusterBootstrap::ITERATIONS,
        int $seed = RaceClusterBootstrap::SEED,
        ?Bt03EvaluationReplaySelectionDto $selection = null,
    ): Bt03EvaluationReplaySummaryDto {
        $source = $this->source->load($foldCode, $statCode, $cohortCode);
        $this->assertSourceIdentity($source, $foldCode, $statCode, $cohortCode);
        $this->assertSelection($source, $selection);
        $labels = $selection?->labelCode !== null ? [$selection->labelCode] : self::LABELS;
        $assignments = [];
        $spools = [];
        try {
            foreach ($source->bins as $bin) {
                if ($selection?->trainingBinIndex !== null && $selection->trainingBinIndex !== $bin->index) {
                    continue;
                }
                $assignment = new Bt03BinAssignmentDto(
                    $bin->index,
                    'TRAINING_BIN',
                    $bin->kind,
                    $bin->lowerBound,
                    $bin->upperBound,
                    $bin->categoryValue,
                    $bin->trainingSampleCount,
                    $bin->sourceEffectBinId,
                    $bin->boundariesHash,
                );
                $assignments[$bin->index] = $assignment;
                $this->createSpools($spools, $source, $assignment, $labels);
            }

            $rowCount = $raceCount = $unseenRowCount = 0;
            $lastRaceId = $lastRaceEntryId = null;
            $seenRaceIds = [];
            $cohort = Bt02SignalCohort::from($cohortCode);
            foreach ($this->dataset->rows($source->evaluationFrom, $source->evaluationTo, $statCode, $cohort) as $row) {
                if (! $row instanceof Bt02EvaluationRowDto || ! is_finite($row->baselineValue) || ! is_finite($row->signalValue)) {
                    throw new RuntimeException('BT-03 evaluation dataset row was invalid.');
                }
                $this->assertOrdered($row, $lastRaceId, $lastRaceEntryId, $seenRaceIds);
                if ($row->raceId !== $lastRaceId) {
                    $raceCount++;
                }
                $lastRaceId = $row->raceId;
                $lastRaceEntryId = $row->raceEntryId;
                $rowCount++;

                $assignment = $this->binAssigner->assign($source->bins, $row->signalValue)
                    ?? throw new RuntimeException('BT-03 evaluation signal did not resolve to a fixed bin.');
                if ($assignment->binOrigin === 'UNSEEN_CATEGORY') {
                    $unseenRowCount++;
                }
                if ($selection?->trainingBinIndex !== null && $selection->trainingBinIndex !== $assignment->binIndex) {
                    continue;
                }
                if (! isset($assignments[$assignment->binIndex])) {
                    if ($assignment->binOrigin !== 'UNSEEN_CATEGORY' || $assignment->binIndex !== 0) {
                        throw new RuntimeException('BT-03 assigned an unknown training bin.');
                    }
                    $assignments[0] = $assignment;
                    $this->createSpools($spools, $source, $assignment, $labels);
                }
                foreach ($labels as $label) {
                    $pair = $source->modelPairs[$label] ?? throw new RuntimeException("BT-03 {$label} model pair was missing.");
                    $baselineProbability = $this->modelReplayer->probabilityFromValidatedModel($pair->baseline, [
                        'STAT01_RACE_SCORE' => $row->baselineValue,
                    ]);
                    $incrementalProbability = $this->modelReplayer->probabilityFromValidatedModel($pair->incremental, [
                        'STAT01_RACE_SCORE' => $row->baselineValue,
                        $source->primaryFeatureCode => $row->signalValue,
                    ]);
                    $spools[$this->key($assignment->binIndex, $label)]->append(
                        $row->raceId,
                        new Bt03BinEffectEntryDto(
                            $row->raceEntryId,
                            $row->label($label),
                            $baselineProbability,
                            $incrementalProbability,
                        ),
                    );
                }
            }

            foreach ($spools as $spool) {
                $spool->seal();
            }
            ksort($assignments, SORT_NUMERIC);
            $effects = [];
            $spoolBytes = $maximumSamples = $maximumRaces = 0;
            foreach ($assignments as $assignment) {
                foreach ($labels as $label) {
                    $spool = $spools[$this->key($assignment->binIndex, $label)];
                    $metadata = $spool->metadata();
                    $spoolBytes += $metadata->byteCount;
                    $result = $this->calculator->calculate($spool->payloads(), $iterations, $seed);
                    $maximumSamples = max($maximumSamples, $result->evaluationSampleCount);
                    $maximumRaces = max($maximumRaces, $result->evaluationRaceCount);
                    $models = $source->modelPairs[$label];
                    $effects[] = new Bt03ComputedBinEffectDto(
                        $assignment,
                        $label,
                        $models,
                        $result,
                        $this->effectHasher->hash($this->effectArtifact($source, $assignment, $label, $models, $result, $iterations, $seed)),
                    );
                }
            }

            return new Bt03EvaluationReplaySummaryDto(
                $foldCode,
                $statCode,
                $cohortCode,
                $rowCount,
                $raceCount,
                count($source->bins),
                $unseenRowCount,
                count($spools),
                $spoolBytes,
                $maximumSamples,
                $maximumRaces,
                $effects,
            );
        } finally {
            foreach ($spools as $spool) {
                $spool->cleanup();
            }
        }
    }

    /** @param array<string, Bt03BinEffectSpool> $spools @param list<string> $labels */
    private function createSpools(array &$spools, Bt03EvaluationSourceDto $source, Bt03BinAssignmentDto $bin, array $labels): void
    {
        foreach ($labels as $label) {
            $key = $this->key($bin->binIndex, $label);
            if (isset($spools[$key])) {
                throw new RuntimeException('BT-03 bin effect spool identity was duplicated.');
            }
            $spools[$key] = new Bt03BinEffectSpool(new Bt03BinSpoolIdentityDto(
                $source->foldCode,
                $source->statCode,
                $source->cohortCode,
                $label,
                $bin->binIndex,
            ), $this->temporaryDirectory);
        }
    }

    private function assertSelection(Bt03EvaluationSourceDto $source, ?Bt03EvaluationReplaySelectionDto $selection): void
    {
        if ($selection?->trainingBinIndex === null) {
            return;
        }
        foreach ($source->bins as $bin) {
            if ($bin->index === $selection->trainingBinIndex) {
                return;
            }
        }

        throw new RuntimeException('BT-03 selected training bin did not belong to the fixed source.');
    }

    private function key(int $binIndex, string $label): string
    {
        return "{$binIndex}:{$label}";
    }

    /** @param array<int, true> $seenRaceIds */
    private function assertOrdered(Bt02EvaluationRowDto $row, ?int $lastRaceId, ?int $lastRaceEntryId, array &$seenRaceIds): void
    {
        if ($row->raceId < 1 || $row->raceEntryId < 1
            || ($row->raceId === $lastRaceId && $row->raceEntryId <= $lastRaceEntryId)
            || ($row->raceId !== $lastRaceId && isset($seenRaceIds[$row->raceId]))) {
            throw new RuntimeException('BT-03 evaluation dataset race grouping or entry identity was invalid.');
        }
        $seenRaceIds[$row->raceId] = true;
    }

    private function assertSourceIdentity(Bt03EvaluationSourceDto $source, string $foldCode, string $statCode, string $cohortCode): void
    {
        if ($source->sourceRunId !== Bt03SourceManifest::SOURCE_BT02_RUN_ID
            || $source->foldCode !== $foldCode || $source->statCode !== $statCode || $source->cohortCode !== $cohortCode
            || $source->sourceFoldId < 1 || $source->sourceSignalSpecId < 1 || $source->bins === []
            || array_keys($source->modelPairs) !== self::LABELS) {
            throw new RuntimeException('BT-03 evaluation source identity was inconsistent.');
        }
        foreach ($source->modelPairs as $label => $pair) {
            $this->assertModelOwnership($source, $label, $pair);
        }
    }

    private function assertModelOwnership(Bt03EvaluationSourceDto $source, string $label, Bt03ModelPairDto $pair): void
    {
        foreach ([$pair->baseline, $pair->incremental] as $model) {
            if ($model->sourceRunId !== $source->sourceRunId
                || $model->sourceFoldId !== $source->sourceFoldId
                || $model->sourceSignalSpecId !== $source->sourceSignalSpecId
                || $model->foldCode !== $source->foldCode || $model->statCode !== $source->statCode
                || $model->cohortCode !== $source->cohortCode || $model->labelCode !== $label) {
                throw new RuntimeException('BT-03 evaluation model ownership was inconsistent.');
            }
        }
    }

    /** @return array<string, mixed> */
    private function effectArtifact(
        Bt03EvaluationSourceDto $source,
        Bt03BinAssignmentDto $bin,
        string $label,
        Bt03ModelPairDto $models,
        Bt03BinEffectResultDto $result,
        int $iterations,
        int $seed,
    ): array {
        return [
            'source_bt02_run_id' => $source->sourceRunId,
            'source_bt02_run_uuid' => Bt03SourceManifest::SOURCE_BT02_RUN_UUID,
            'source_fold_id' => $source->sourceFoldId,
            'source_signal_spec_id' => $source->sourceSignalSpecId,
            'source_baseline_model_hash' => $models->baseline->modelHash,
            'source_incremental_model_hash' => $models->incremental->modelHash,
            'source_boundaries_hash' => $bin->boundariesHash,
            'source_backtest_effect_bin_id' => $bin->sourceEffectBinId,
            'cohort_code' => $source->cohortCode,
            'label_code' => $label,
            'bin_index' => $bin->binIndex,
            'bin_origin' => $bin->binOrigin,
            'bin_kind' => $bin->binKind,
            'lower_bound' => $bin->lowerBound,
            'upper_bound' => $bin->upperBound,
            'category_value' => $bin->categoryValue,
            'training_sample_count' => $bin->trainingSampleCount,
            'evaluation_status' => $result->evaluationStatus,
            'evaluation_sample_count' => $result->evaluationSampleCount,
            'evaluation_race_count' => $result->evaluationRaceCount,
            'positive_count' => $result->positiveCount,
            'observed_rate' => $result->observedRate,
            'observed_rate_ci_lower' => $result->observedRateCiLower,
            'observed_rate_ci_upper' => $result->observedRateCiUpper,
            'baseline_mean_probability' => $result->baselineMeanProbability,
            'incremental_mean_probability' => $result->incrementalMeanProbability,
            'baseline_residual_mean' => $result->baselineResidualMean,
            'baseline_residual_ci_lower' => $result->baselineResidualCiLower,
            'baseline_residual_ci_upper' => $result->baselineResidualCiUpper,
            'incremental_residual_mean' => $result->incrementalResidualMean,
            'incremental_residual_ci_lower' => $result->incrementalResidualCiLower,
            'incremental_residual_ci_upper' => $result->incrementalResidualCiUpper,
            'probability_shift_mean' => $result->probabilityShiftMean,
            'probability_shift_ci_lower' => $result->probabilityShiftCiLower,
            'probability_shift_ci_upper' => $result->probabilityShiftCiUpper,
            'log_loss_delta' => $result->logLossDelta,
            'log_loss_delta_ci_lower' => $result->logLossDeltaCiLower,
            'log_loss_delta_ci_upper' => $result->logLossDeltaCiUpper,
            'brier_delta' => $result->brierDelta,
            'brier_delta_ci_lower' => $result->brierDeltaCiLower,
            'brier_delta_ci_upper' => $result->brierDeltaCiUpper,
            'bootstrap_iterations' => $iterations,
            'bootstrap_seed' => $seed,
            'calculation_version' => Bt03BinEffectCalculator::CALCULATION_VERSION,
        ];
    }
}
