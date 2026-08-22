<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Services;

use App\Domain\Keirin\Backtest\Calculators\Bt03eCoordinateDescentOptimizer;
use App\Domain\Keirin\Backtest\Calculators\Bt03eDirectionRuleBuilder;
use App\Domain\Keirin\Backtest\Calculators\Bt03eRaceMetricEvaluator;
use App\Domain\Keirin\Backtest\DTO\Bt03eCandidateDto;
use App\Domain\Keirin\Backtest\DTO\Bt03eMetricSummaryDto;
use App\Domain\Keirin\Backtest\Repositories\Bt03eRuleSourceRepository;
use App\Domain\Keirin\Backtest\Support\Bt02OutcomeContextSnapshotArtifact;
use RuntimeException;
use Throwable;

class Bt03eHistoricalForwardScoringService
{
    public function __construct(
        private readonly Bt03eRuleSourceRepository $ruleSource,
        private readonly Bt03eDirectionRuleBuilder $ruleBuilder,
        private readonly Bt03eSelectedSourcePreflightService $preflight,
        private readonly Bt03eDatasetBuilder $datasets,
        private readonly Bt03eCoordinateDescentOptimizer $optimizer,
        private readonly Bt03eRaceMetricEvaluator $metrics,
        private readonly Bt03eArtifactWriter $artifacts,
        private readonly Bt03eReadOnlyQueryAudit $queryAudit,
    ) {}

    /** @return array<string, mixed> */
    public function run(string $outputDirectory = '/tmp'): array
    {
        $started = hrtime(true);
        $this->queryAudit->start();
        $training = $evaluation = null;
        try {
            $run6Before = $this->ruleSource->auditState();
            $rules = $this->ruleBuilder->build($this->ruleSource->rows());
            $sourcePreflight = $this->preflight->run();
            $snapshotPath = $this->ruleSource->outcomeSnapshotPath();
            $snapshot = Bt02OutcomeContextSnapshotArtifact::open(storage_path('app/'.$snapshotPath), $snapshotPath);
            if (! hash_equals(Bt03SourceManifest::OUTCOME_SNAPSHOT_MANIFEST_HASH, $snapshot->manifestHash())) {
                throw new RuntimeException('BT-03E outcome snapshot manifest identity was invalid.');
            }

            $trainingStarted = hrtime(true);
            $this->queryAudit->recordSnapshotYear(Bt03eContract::TRAINING_YEAR);
            $training = $this->datasets->build(Bt03eContract::TRAINING_YEAR, $rules, $snapshot, sys_get_temp_dir());
            $optimization = $this->optimizer->optimize(fn () => $training['spool']->races());
            $candidate = $optimization['candidate'];
            $trainingElapsed = $this->secondsSince($trainingStarted);

            // The 2024 outcome partition is not opened until the 2023-only candidate is frozen.
            $evaluationStarted = hrtime(true);
            $this->queryAudit->recordSnapshotYear(Bt03eContract::EVALUATION_YEAR);
            $evaluation = $this->datasets->build(Bt03eContract::EVALUATION_YEAR, $rules, $snapshot, sys_get_temp_dir());
            $zeroWeights = array_fill_keys(Bt03eContract::STAT_CODES, 0);
            $baselineMetrics = $this->metrics->evaluate($evaluation['spool']->races(), new Bt03eCandidateDto(1, $zeroWeights));
            $engineMetrics = $this->metrics->evaluate($evaluation['spool']->races(), $candidate);
            $evaluationElapsed = $this->secondsSince($evaluationStarted);
            $run6After = $this->ruleSource->auditState();
            if ($run6Before !== $run6After) {
                throw new RuntimeException('BT-03E detected a mutation of fixed run 6.');
            }
            $queryAudit = $this->queryAudit->finish();

            $summary = [
                'contract' => [
                    'name' => 'BT-03E-01-HISTORICAL-FORWARD-SCORING',
                    'rule_source_run_id' => Bt03eContract::SOURCE_RUN_ID,
                    'rule_source_fold' => Bt03eContract::SOURCE_FOLD,
                    'cohort' => Bt03eContract::COHORT,
                    'effect_manifest_hash' => Bt03eContract::EFFECT_MANIFEST_HASH,
                    'training_year' => Bt03eContract::TRAINING_YEAR,
                    'evaluation_year' => Bt03eContract::EVALUATION_YEAR,
                    'source_effect_row_count' => count($rules) * 3,
                    'rule_count' => count($rules),
                ],
                'source_preflight' => $sourcePreflight,
                'chosen_candidate' => $candidate->canonical(),
                'nonzero_rule_counts' => $this->nonzeroRuleCounts($rules),
                'point_rules' => array_map(fn ($rule): array => [
                    ...$rule->canonical(),
                    'stat_weight' => $candidate->weights[$rule->statCode],
                    'final_points' => $rule->directionStrength * $candidate->weights[$rule->statCode],
                    'source_fold' => Bt03eContract::SOURCE_FOLD,
                ], $rules),
                'optimization' => [
                    'algorithm' => 'DETERMINISTIC_MULTI_START_COORDINATE_DESCENT',
                    'starts' => $optimization['starts'],
                    'evaluated_candidate_count' => $optimization['evaluated_candidate_count'],
                    'weight_grid' => Bt03eContract::WEIGHT_GRID,
                    'base_step_grid' => Bt03eContract::BASE_STEP_GRID,
                ],
                'training_2023' => $this->datasetSummary($training, $optimization['metrics']),
                'evaluation_2024' => [
                    ...$this->datasetSummary($evaluation, $engineMetrics),
                    'baseline_metrics' => $baselineMetrics->metrics,
                    'point_engine_metrics' => $engineMetrics->metrics,
                    'metric_deltas' => $this->deltas($engineMetrics, $baselineMetrics),
                    'baseline_ties' => $this->ties($baselineMetrics),
                    'point_engine_ties' => $this->ties($engineMetrics),
                ],
                'audit' => [
                    ...$queryAudit,
                    'source_year_access' => [2023 => 13, 2024 => 13, 2025 => 0, 2026 => 0],
                    'run6_unchanged' => true,
                ],
                'runtime' => [
                    'training_seconds' => $trainingElapsed,
                    'evaluation_seconds' => $evaluationElapsed,
                    'total_seconds' => $this->secondsSince($started),
                    'php_peak_bytes' => memory_get_peak_usage(false),
                    'php_real_peak_bytes' => memory_get_peak_usage(true),
                ],
            ];
            $paths = $this->artifacts->write($outputDirectory, $summary, $rules, $candidate);

            return [...$summary, 'artifacts' => $paths];
        } catch (Throwable $throwable) {
            try {
                $this->queryAudit->finish();
            } catch (Throwable) {
                // Preserve the primary failure while still disabling the listener.
            }
            throw $throwable;
        } finally {
            if (is_array($training)) {
                $training['spool']->cleanup();
            }
            if (is_array($evaluation)) {
                $evaluation['spool']->cleanup();
            }
        }
    }

    /** @param list<object> $rules @return array<string, int> */
    private function nonzeroRuleCounts(array $rules): array
    {
        $counts = array_fill_keys(Bt03eContract::STAT_CODES, 0);
        foreach ($rules as $rule) {
            $counts[$rule->statCode] += (int) ($rule->directionStrength !== 0);
        }

        return $counts;
    }

    /** @param array<string, mixed> $dataset @return array<string, mixed> */
    private function datasetSummary(array $dataset, Bt03eMetricSummaryDto $metrics): array
    {
        $metadata = $dataset['spool']->metadata();

        return [
            'snapshot_race_count' => $dataset['snapshot_races'],
            'race_count' => $metadata->raceCount,
            'entry_count' => $metadata->entryCount,
            'prediction_coverage' => $dataset['snapshot_races'] > 0 ? $metadata->raceCount / $dataset['snapshot_races'] : 0.0,
            'source_excluded_race_count' => $dataset['excluded_races'],
            'source_exclusion_reasons' => $dataset['excluded_reasons'],
            'ordered_eligible_race_count' => $metrics->orderedEligibleRaceCount,
            'ordered_excluded_race_count' => $metrics->orderedExcludedRaceCount,
            'ordered_exclusion_reasons' => $metrics->orderedExclusionReasons,
            'metrics' => $metrics->metrics,
            'ties' => $this->ties($metrics),
            'spool_bytes' => $metadata->byteCount,
            'spool_sha256' => $metadata->sha256,
        ];
    }

    /** @return array<string, float> */
    private function deltas(Bt03eMetricSummaryDto $engine, Bt03eMetricSummaryDto $baseline): array
    {
        $deltas = [];
        foreach (Bt03eRaceMetricEvaluator::METRIC_CODES as $metric) {
            $deltas[$metric] = $engine->metrics[$metric] - $baseline->metrics[$metric];
        }

        return $deltas;
    }

    /** @return array{score_tied_race_count: int, score_tied_entry_count: int, stat01_tie_break_usage_count: int} */
    private function ties(Bt03eMetricSummaryDto $metrics): array
    {
        return [
            'score_tied_race_count' => $metrics->scoreTiedRaceCount,
            'score_tied_entry_count' => $metrics->scoreTiedEntryCount,
            'stat01_tie_break_usage_count' => $metrics->stat01TieBreakUsageCount,
        ];
    }

    private function secondsSince(int $started): float
    {
        return (hrtime(true) - $started) / 1_000_000_000;
    }
}
