<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Services;

use App\Domain\Keirin\Backtest\Calculators\FeatureEligibilityEvaluator;
use App\Domain\Keirin\Backtest\Calculators\LabelCohortEvaluator;
use App\Domain\Keirin\Backtest\Calculators\Stat01BaselinePredictionCalculator;
use App\Domain\Keirin\Backtest\DTO\Bt01BuildSummaryDto;
use App\Domain\Keirin\Backtest\DTO\FoldDefinitionDto;
use App\Domain\Keirin\Backtest\DTO\LabelResultDto;
use App\Domain\Keirin\Backtest\DTO\RaceContextDto;
use App\Domain\Keirin\Backtest\Enums\BacktestCohort;
use App\Domain\Keirin\Backtest\Repositories\BacktestAuditRepository;
use App\Domain\Keirin\Backtest\Repositories\BacktestContextRepository;
use App\Domain\Keirin\Backtest\Repositories\BacktestFeatureRepository;
use App\Domain\Keirin\Backtest\Repositories\BacktestLabelRepository;
use App\Domain\Keirin\Backtest\Support\CanonicalHasher;
use App\Domain\Keirin\Backtest\Support\PredictionSpool;
use App\Domain\Keirin\Backtest\Support\PredictionSpoolFactory;
use App\Models\BacktestFold;
use App\Models\BacktestRun;
use InvalidArgumentException;
use Throwable;

class Bt01BaselineService
{
    public function __construct(
        private readonly Bt01SourceManifest $manifest,
        private readonly Bt01FoldProvider $foldProvider,
        private readonly FinalHoldoutGuard $holdoutGuard,
        private readonly BacktestContextRepository $contexts,
        private readonly BacktestFeatureRepository $features,
        private readonly BacktestLabelRepository $labels,
        private readonly BacktestAuditRepository $audit,
        private readonly FeatureEligibilityEvaluator $eligibility,
        private readonly Stat01BaselinePredictionCalculator $predictions,
        private readonly LabelCohortEvaluator $cohorts,
        private readonly CanonicalHasher $hasher,
        private readonly PredictionSpoolFactory $spoolFactory,
    ) {}

    public function build(bool $dryRun, int $chunkSize = 200): Bt01BuildSummaryDto
    {
        if ($chunkSize < 1 || $chunkSize > 1000) {
            throw new InvalidArgumentException('BT-01 chunk size must be between 1 and 1000.');
        }

        $folds = $this->foldProvider->folds();
        foreach ($folds as $fold) {
            $this->holdoutGuard->assertAllowed($fold);
        }
        $verifiedSources = $this->features->validateSources($this->manifest->entries());
        $manifestHash = $this->manifest->hash();
        $run = $dryRun ? null : $this->audit->startRun(
            manifestVersion: Bt01SourceManifest::VERSION,
            manifestHash: $manifestHash,
            calculationVersion: Stat01BaselinePredictionCalculator::CALCULATION_VERSION,
            ruleVersion: Stat01BaselinePredictionCalculator::RULE_VERSION,
            holdoutPolicy: FinalHoldoutGuard::POLICY,
            parameters: ['folds' => array_map(fn ($fold): string => $fold->code, $folds), 'warm_up_year' => 2021, 'chunk' => $chunkSize],
        );
        $totalTarget = $totalPredicted = $totalExcluded = 0;
        try {
            if ($run instanceof BacktestRun) {
                $this->audit->storeSources($run, $verifiedSources, $manifestHash);
            }

            foreach ($folds as $definition) {
                $fold = $run instanceof BacktestRun ? $this->audit->startFold($run, $definition) : null;
                $target = $predicted = $excluded = 0;
                $spool = null;

                try {
                    $spool = $this->spoolFactory->create();
                    $predictionManifestHash = $this->predictionPhase(
                        $definition,
                        $run,
                        $fold,
                        $spool,
                        $chunkSize,
                        $target,
                        $predicted,
                        $excluded,
                    );
                    [$metrics, $labelManifestHash] = $this->labelPhase($run, $fold, $spool, $chunkSize);
                    if ($run instanceof BacktestRun && $fold instanceof BacktestFold) {
                        $this->audit->storeMetrics($run, $fold, $metrics->rows($target, $predicted));
                        $this->audit->finishFold(
                            $fold,
                            $target,
                            $predicted,
                            $excluded,
                            $predictionManifestHash,
                            $labelManifestHash,
                        );
                    }
                } catch (Throwable $throwable) {
                    $totalTarget += $target;
                    $totalPredicted += $predicted;
                    $totalExcluded += $excluded;
                    if ($fold instanceof BacktestFold) {
                        $this->audit->failFold($fold, $target, $predicted, $excluded);
                    }

                    throw $throwable;
                } finally {
                    $spool?->close();
                }
                $totalTarget += $target;
                $totalPredicted += $predicted;
                $totalExcluded += $excluded;
            }
        } catch (Throwable $throwable) {
            if ($run instanceof BacktestRun) {
                $this->audit->finishRun($run, $totalTarget, $totalPredicted, $totalExcluded, 1, $throwable->getMessage());
            }

            throw $throwable;
        }

        if ($run instanceof BacktestRun) {
            $this->audit->finishRun($run, $totalTarget, $totalPredicted, $totalExcluded, 0, null);
        }

        return new Bt01BuildSummaryDto(
            runId: $run?->id,
            runUuid: $run?->run_uuid,
            dryRun: $dryRun,
            targetRaces: $totalTarget,
            predictedRaces: $totalPredicted,
            excludedRaces: $totalExcluded,
            errors: 0,
        );
    }

    private function predictionPhase(
        FoldDefinitionDto $definition,
        ?BacktestRun $run,
        ?BacktestFold $fold,
        PredictionSpool $spool,
        int $chunkSize,
        int &$target,
        int &$predicted,
        int &$excluded,
    ): string {
        $manifest = hash_init('sha256');
        $source = $this->manifest->forYear((int) $definition->evaluationFrom->format('Y'));
        foreach ($this->contexts->chunks($definition, $chunkSize) as $raceContexts) {
            $raceIds = array_map(fn (RaceContextDto $race): int => $race->raceId, $raceContexts);
            $featureRows = $this->features->forRaces($source->featureRunId, $raceIds);
            foreach ($raceContexts as $race) {
                $target++;
                $raceFeatures = $featureRows[$race->raceId] ?? [];
                $decision = $this->eligibility->evaluate($race, $raceFeatures);
                if (! $decision->eligible) {
                    $excluded++;
                    if ($run instanceof BacktestRun && $fold instanceof BacktestFold) {
                        foreach ($decision->reasons as $reason) {
                            $this->audit->exclude($run, $fold, $race->raceId, 'FEATURE', $reason);
                        }
                    }

                    continue;
                }

                $racePredictions = $this->predictions->calculate($raceFeatures);
                if ($run instanceof BacktestRun && $fold instanceof BacktestFold) {
                    $this->audit->storePredictions($run, $fold, $racePredictions);
                    $predicted++;
                    $spool->append($race, $racePredictions);
                } else {
                    $spool->append($race, $racePredictions);
                    $predicted++;
                }
                foreach ($racePredictions as $prediction) {
                    hash_update($manifest, $prediction->predictionHash."\n");
                }
            }
        }

        return hash_final($manifest);
    }

    /** @return array{Bt01MetricAccumulator, string} */
    private function labelPhase(?BacktestRun $run, ?BacktestFold $fold, PredictionSpool $spool, int $chunkSize): array
    {
        $manifest = hash_init('sha256');
        $metrics = new Bt01MetricAccumulator;
        foreach ($spool->chunks($chunkSize) as $records) {
            $raceIds = array_map(fn (array $record): int => $record['race']->raceId, $records);
            $labelRows = $this->labels->forRaces($raceIds);
            foreach ($records as $record) {
                $race = $record['race'];
                $racePredictions = $record['predictions'];
                $raceLabels = $labelRows[$race->raceId] ?? [];
                hash_update($manifest, $this->labelHash($race->raceId, $raceLabels)."\n");
                $operational = $this->cohorts->evaluate(BacktestCohort::Operational, $race, $racePredictions, $raceLabels);
                if (! $operational->included) {
                    if ($run instanceof BacktestRun && $fold instanceof BacktestFold) {
                        foreach ($operational->reasons as $reason) {
                            $this->audit->exclude($run, $fold, $race->raceId, 'LABEL', $reason);
                        }
                    }

                    continue;
                }
                $metrics->add(BacktestCohort::Operational, $operational);

                $normal = $this->cohorts->evaluate(BacktestCohort::NormalFinish, $race, $racePredictions, $raceLabels);
                if ($normal->included) {
                    $metrics->add(BacktestCohort::NormalFinish, $normal);
                } elseif ($run instanceof BacktestRun && $fold instanceof BacktestFold) {
                    foreach ($normal->reasons as $reason) {
                        $this->audit->exclude($run, $fold, $race->raceId, 'COHORT', $reason);
                    }
                }
            }
        }

        return [$metrics, hash_final($manifest)];
    }

    /** @param list<LabelResultDto> $labels */
    private function labelHash(int $raceId, array $labels): string
    {
        usort($labels, fn (LabelResultDto $a, LabelResultDto $b): int => $a->bikeNumber <=> $b->bikeNumber);

        return $this->hasher->hash([
            'race_id' => $raceId,
            'labels' => array_map(fn (LabelResultDto $label): array => [
                'bike_number' => $label->bikeNumber,
                'rank' => $label->rank,
                'result_status' => $label->resultStatus,
            ], $labels),
        ]);
    }
}
