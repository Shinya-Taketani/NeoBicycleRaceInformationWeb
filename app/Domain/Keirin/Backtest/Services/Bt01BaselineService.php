<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Services;

use App\Domain\Keirin\Backtest\Calculators\FeatureEligibilityEvaluator;
use App\Domain\Keirin\Backtest\Calculators\LabelCohortEvaluator;
use App\Domain\Keirin\Backtest\Calculators\Stat01BaselinePredictionCalculator;
use App\Domain\Keirin\Backtest\DTO\Bt01BuildSummaryDto;
use App\Domain\Keirin\Backtest\DTO\LabelResultDto;
use App\Domain\Keirin\Backtest\DTO\PredictionDto;
use App\Domain\Keirin\Backtest\DTO\RaceContextDto;
use App\Domain\Keirin\Backtest\Enums\BacktestCohort;
use App\Domain\Keirin\Backtest\Repositories\BacktestAuditRepository;
use App\Domain\Keirin\Backtest\Repositories\BacktestContextRepository;
use App\Domain\Keirin\Backtest\Repositories\BacktestFeatureRepository;
use App\Domain\Keirin\Backtest\Repositories\BacktestLabelRepository;
use App\Domain\Keirin\Backtest\Support\CanonicalHasher;
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
        if ($run instanceof BacktestRun) {
            $this->audit->storeSources($run, $verifiedSources, $manifestHash);
        }

        $totalTarget = $totalPredicted = $totalExcluded = 0;
        try {
            foreach ($folds as $definition) {
                $fold = $run instanceof BacktestRun ? $this->audit->startFold($run, $definition) : null;
                $target = $predicted = $excluded = 0;
                $predictionHashes = [];
                $labelHashes = [];
                $metrics = new Bt01MetricAccumulator;
                $source = $this->manifest->forYear((int) $definition->evaluationFrom->format('Y'));

                foreach ($this->contexts->chunks($definition, $chunkSize) as $raceContexts) {
                    $target += count($raceContexts);
                    $raceIds = array_map(fn (RaceContextDto $race): int => $race->raceId, $raceContexts);
                    $featureRows = $this->features->forRaces($source->featureRunId, $raceIds);
                    $eligible = [];

                    foreach ($raceContexts as $race) {
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
                        $eligible[$race->raceId] = ['context' => $race, 'predictions' => $racePredictions];
                        $predicted++;
                        foreach ($racePredictions as $prediction) {
                            $predictionHashes[] = $prediction->predictionHash;
                        }
                        if ($run instanceof BacktestRun && $fold instanceof BacktestFold) {
                            $this->audit->storePredictions($run, $fold, $racePredictions);
                        }
                    }

                    // Labels are read only after predictions for this bounded race chunk are complete and locked.
                    $labelRows = $this->labels->forRaces(array_map('intval', array_keys($eligible)));
                    foreach ($eligible as $raceId => $data) {
                        /** @var RaceContextDto $race */
                        $race = $data['context'];
                        /** @var list<PredictionDto> $racePredictions */
                        $racePredictions = $data['predictions'];
                        $raceLabels = $labelRows[$raceId] ?? [];
                        $labelHashes[] = $this->labelHash($raceId, $raceLabels);
                        $operational = $this->cohorts->evaluate(BacktestCohort::Operational, $race, $racePredictions, $raceLabels);
                        if (! $operational->included) {
                            if ($run instanceof BacktestRun && $fold instanceof BacktestFold) {
                                foreach ($operational->reasons as $reason) {
                                    $this->audit->exclude($run, $fold, $raceId, 'LABEL', $reason);
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
                                $this->audit->exclude($run, $fold, $raceId, 'COHORT', $reason);
                            }
                        }
                    }
                }

                sort($predictionHashes, SORT_STRING);
                sort($labelHashes, SORT_STRING);
                if ($run instanceof BacktestRun && $fold instanceof BacktestFold) {
                    $this->audit->storeMetrics($run, $fold, $metrics->rows($target, $predicted));
                    $this->audit->finishFold(
                        $fold,
                        $target,
                        $predicted,
                        $excluded,
                        $this->hasher->hash($predictionHashes),
                        $this->hasher->hash($labelHashes),
                    );
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
