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
        private readonly Bt02FingerprintPreflightService $preflight,
        private readonly Bt02SourceManifest $sourceManifest,
        private readonly Bt02SignalRegistry $signalRegistry,
        private readonly Bt02AuditRepository $audit,
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

        // Baseline source identity is verified before holdout and full signal fingerprint checks.
        $baselineSources = $this->baselineFeatures->validateSources($this->baselineManifest->entries());
        $folds = $this->foldProvider->folds();
        if (array_map(fn ($fold): string => $fold->code, $folds) !== ['WF_2023', 'WF_2024', 'WF_2025']) {
            throw new RuntimeException('BT-02 requires exactly the fixed three folds.');
        }
        foreach ($folds as $fold) {
            $this->holdoutGuard->assertAllowed($fold->holdoutDefinition());
        }
        $this->preflight->run();

        $run = $this->audit->startRun([
            'folds' => array_map(fn ($fold): string => $fold->code, $folds),
            'labels' => Bt02EntrySignalEvaluator::LABELS,
            'metrics' => Bt02EntrySignalEvaluator::METRICS,
            'bootstrap_iterations' => 2000,
            'bootstrap_seed' => 20260812,
            'probability_semantics' => Bt02EntrySignalEvaluator::PROBABILITY_SEMANTICS,
        ]);
        $currentFold = null;
        $modelCount = $metricCount = $targetRaces = $evaluatedRaces = 0;

        try {
            $this->audit->storeBaselineSources($run, $baselineSources, $this->baselineManifest->hash());
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
                $currentFold = $this->audit->startFold($run, $foldDefinition);
                $foldManifest = hash_init('sha256');
                $foldRaces = 0;
                foreach ($entrySignals as $signal) {
                    $result = $this->evaluator->evaluate(
                        $run,
                        $currentFold,
                        $foldDefinition,
                        $signal,
                        $specs[$signal->statCode],
                    );
                    $modelCount += $result['models'];
                    $metricCount += $result['metrics'];
                    $foldRaces = max($foldRaces, $result['races']);
                    hash_update($foldManifest, $signal->statCode.':'.$result['manifest_hash']."\n");
                }
                $this->audit->finishFold($currentFold, $foldRaces, $foldRaces, hash_final($foldManifest));
                $targetRaces += $foldRaces;
                $evaluatedRaces += $foldRaces;
                $currentFold = null;
            }

            $this->audit->finishRun($run, 'SUCCEEDED', $targetRaces, $evaluatedRaces, 0, null);
        } catch (Throwable $throwable) {
            if ($currentFold instanceof BacktestFold) {
                $this->audit->failFold($currentFold);
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
}
