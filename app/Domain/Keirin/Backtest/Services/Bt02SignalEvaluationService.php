<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Services;

use App\Domain\Keirin\Backtest\DTO\Bt02SignalEvaluationSummaryDto;
use App\Domain\Keirin\Backtest\Enums\Bt02AnalysisRole;
use App\Domain\Keirin\Backtest\Repositories\BacktestFeatureRepository;
use App\Domain\Keirin\Backtest\Repositories\Bt02AuditRepository;
use App\Models\BacktestFold;
use RuntimeException;
use Throwable;

class Bt02SignalEvaluationService
{
    public function __construct(
        private readonly Bt01SourceManifest $baselineManifest,
        private readonly BacktestFeatureRepository $baselineFeatures,
        private readonly Bt02FoldProvider $foldProvider,
        private readonly FinalHoldoutGuard $holdoutGuard,
        private readonly Bt02BaselineFingerprintPreflightService $baselineFingerprintPreflight,
        private readonly Bt02FingerprintPreflightService $preflight,
        private readonly Bt02SourceManifest $sourceManifest,
        private readonly Bt02SignalRegistry $signalRegistry,
        private readonly Bt02AuditRepository $audit,
        private readonly Bt02OutcomeContextSnapshotBuilder $snapshotBuilder,
        private readonly Bt02OutcomeContextSnapshotSession $snapshotSession,
        private readonly Bt02EntrySignalEvaluator $evaluator,
    ) {}

    public function execute(): Bt02SignalEvaluationSummaryDto
    {
        $definitions = $this->signalRegistry->all();
        $entrySignals = array_values(array_filter(
            $definitions,
            fn ($definition): bool => $definition->analysisRole === Bt02AnalysisRole::EntryIncremental,
        ));
        if (count($definitions) !== 14 || count($entrySignals) !== 12
            || count(array_filter($definitions, fn ($definition): bool => $definition->analysisRole === Bt02AnalysisRole::DiagnosticOnly)) !== 1
            || count(array_filter($definitions, fn ($definition): bool => $definition->analysisRole === Bt02AnalysisRole::RaceStratifier)) !== 1) {
            throw new RuntimeException('BT-02 signal role registry was outside the fixed contract.');
        }

        $folds = $this->foldProvider->folds();
        if (array_map(fn ($fold): string => $fold->code, $folds) !== ['WF_2023', 'WF_2024', 'WF_2025']) {
            throw new RuntimeException('BT-02 requires exactly the fixed three folds.');
        }
        foreach ($folds as $fold) {
            $this->holdoutGuard->assertAllowed($fold->holdoutDefinition());
        }
        $baselineSources = $this->baselineFeatures->validateSources($this->baselineManifest->entries());
        $this->baselineFingerprintPreflight->run();
        $this->preflight->run();
        $snapshot = $this->snapshotBuilder->build();
        $this->snapshotSession->activate($snapshot);

        $targetRaces = array_sum(array_map(
            fn ($fold): int => $this->baselineManifest->forYear((int) $fold->evaluationFrom->format('Y'))->expectedRaceCount,
            $folds,
        ));

        $run = $this->audit->startRun([
            'folds' => array_map(fn ($fold): string => $fold->code, $folds),
            'labels' => Bt02EntrySignalEvaluator::LABELS,
            'metrics' => Bt02EntrySignalEvaluator::METRICS,
            'bootstrap_iterations' => 2000,
            'bootstrap_seed' => 20260812,
            'probability_semantics' => Bt02EntrySignalEvaluator::PROBABILITY_SEMANTICS,
            'baseline_fingerprint_manifest_version' => Bt02BaselineFingerprintManifest::VERSION,
            'baseline_fingerprint_manifest_hash' => Bt02BaselineFingerprintManifest::HASH,
            ...$snapshot->auditParameters(),
        ]);
        $currentFold = null;
        $currentFoldTarget = 0;
        $currentFoldEvaluated = [];
        $currentFoldManifest = null;
        $currentFoldManifestHash = null;
        $modelCount = $metricCount = $evaluatedRaces = 0;

        try {
            $this->audit->storeBaselineSources($run, $baselineSources, Bt02BaselineFingerprintManifest::HASH);
            $this->audit->storeSources($run, $this->sourceManifest->entries());
            $specs = [];
            foreach ($definitions as $definition) {
                $specs[$definition->statCode] = $this->audit->storeSignalSpec($run, $definition, [
                    'execution' => $definition->analysisRole === Bt02AnalysisRole::EntryIncremental
                        ? 'PAIRED_LOGISTIC'
                        : 'SPEC_ONLY_UNTIL_OUTCOME_CONTRACT_IS_DEFINED',
                ]);
            }

            foreach ($folds as $foldDefinition) {
                $currentFoldTarget = $this->baselineManifest
                    ->forYear((int) $foldDefinition->evaluationFrom->format('Y'))
                    ->expectedRaceCount;
                $currentFoldEvaluated = [];
                $currentFoldManifest = hash_init('sha256');
                $currentFoldManifestHash = null;
                $currentFold = $this->audit->startFold($run, $foldDefinition);
                foreach ($entrySignals as $signal) {
                    $result = $this->evaluator->evaluate(
                        $run,
                        $currentFold,
                        $foldDefinition,
                        $signal,
                        $specs[$signal->statCode],
                        function (
                            array $raceIds,
                            string $cohort,
                            string $label,
                            string $baselinePredictionHash,
                            string $incrementalPredictionHash,
                        ) use (&$currentFoldEvaluated, &$currentFoldManifest, $signal): void {
                            if ($currentFoldManifest === null) {
                                throw new RuntimeException('BT-02 fold manifest was unavailable during committed progress.');
                            }
                            hash_update(
                                $currentFoldManifest,
                                implode(':', [$signal->statCode, $cohort, $label, $baselinePredictionHash, $incrementalPredictionHash])."\n",
                            );
                            $this->addRaceIds($currentFoldEvaluated, $raceIds);
                        },
                    );
                    $modelCount += $result['models'];
                    $metricCount += $result['metrics'];
                }
                $foldEvaluatedCount = count($currentFoldEvaluated);
                if ($foldEvaluatedCount > $currentFoldTarget) {
                    throw new RuntimeException('BT-02 evaluated race union exceeded the fixed target universe.');
                }
                $currentFoldManifestHash = hash_final($currentFoldManifest);
                $currentFoldManifest = null;
                $this->audit->finishFold($currentFold, $currentFoldTarget, $foldEvaluatedCount, $currentFoldManifestHash);
                $evaluatedRaces += $foldEvaluatedCount;
                $currentFold = null;
                $currentFoldTarget = 0;
                $currentFoldEvaluated = [];
                $currentFoldManifest = null;
                $currentFoldManifestHash = null;
            }

            try {
                $this->baselineFingerprintPreflight->run();
                $this->preflight->run();
            } catch (Throwable $throwable) {
                throw new RuntimeException(
                    'SOURCE_FINGERPRINT_DRIFT_AFTER_EVALUATION: '.$throwable->getMessage(),
                    previous: $throwable,
                );
            }
            $this->audit->finishRun($run, 'SUCCEEDED', $targetRaces, $evaluatedRaces, 0, null);
        } catch (Throwable $throwable) {
            if ($currentFold instanceof BacktestFold) {
                $partialEvaluated = count($currentFoldEvaluated);
                $partialManifest = $currentFoldManifestHash
                    ?? ($currentFoldManifest !== null ? hash_final($currentFoldManifest) : null);
                $this->audit->failFold($currentFold, $currentFoldTarget, $partialEvaluated, $partialManifest);
                $evaluatedRaces += $partialEvaluated;
            }
            $this->audit->finishRun($run, 'FAILED', $targetRaces, $evaluatedRaces, 1, $throwable->getMessage());
            throw $throwable;
        }

        return new Bt02SignalEvaluationSummaryDto(
            runId: (int) $run->id,
            runUuid: (string) $run->run_uuid,
            foldCount: count($folds),
            signalCount: count($entrySignals),
            modelCount: $modelCount,
            metricCount: $metricCount,
        );
    }

    /** @param array<int, true> $union @param list<int> $raceIds */
    private function addRaceIds(array &$union, array $raceIds): void
    {
        foreach ($raceIds as $raceId) {
            if (! is_int($raceId) || $raceId < 1) {
                throw new RuntimeException('BT-02 evaluated race identity was invalid.');
            }
            $union[$raceId] = true;
        }
    }
}
