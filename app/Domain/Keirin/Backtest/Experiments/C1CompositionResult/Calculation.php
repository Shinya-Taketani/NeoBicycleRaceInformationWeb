<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\C1CompositionResult;

use App\Domain\Keirin\Backtest\Calculators\Bt03e05MetricEvaluator;
use App\Domain\Keirin\Backtest\Experiments\C1CompositionRequest\PredictionVerifier;
use App\Domain\Keirin\Backtest\Experiments\C1Stat35CompositionFinal\Input;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\JsonlArtifact as Jsonl;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Backtest\Experiments\TacticalPredictionResult\Matcher;
use RuntimeException;

final class Calculation
{
    public function __construct(private readonly Matcher $matcher, private readonly Bt03e05MetricEvaluator $metrics,
        private readonly PredictionVerifier $predictions) {}

    public function compute(string $fixedPath, string $resultPath): array
    {
        // Only the explicitly bounded (maximum ten) selected results are retained, never annual payloads.
        $results = [];
        foreach (Jsonl::read($resultPath) as $row) {
            $clean = $this->matcher->result($row);
            Files::same($clean, $row, 'extracted results contain identities/rank/status only');
            $id = $row['race_id'];
            if ($row['year'] !== 2025 || isset($results[$id]) || count($results) >= 10) {
                throw new RuntimeException('Invalid or duplicate selected result.');
            }
            $results[$id] = $clean;
        }
        $summary = $this->metrics->emptySummary();
        $joined = $contributions = $seen = $requests = [];
        $excluded = array_fill_keys(Bt03e05MetricEvaluator::METRIC_CODES, []);
        foreach (Jsonl::read($fixedPath) as $fixed) {
            if (array_keys($fixed) !== ['request_id', 'input', 'prediction'] || ! is_string($fixed['request_id'])) {
                throw new RuntimeException('Invalid fixed prediction.');
            }
            Input::validate($fixed['input'], [2025]);
            $this->predictions->verify($fixed['prediction']);
            $id = $fixed['input']['race_id'];
            if (isset($seen[$id]) || isset($requests[$fixed['request_id']]) || ! isset($results[$id]) || count($seen) >= 10) {
                throw new RuntimeException('Missing / duplicate selected prediction or result.');
            }
            $seen[$id] = $requests[$fixed['request_id']] = true;
            $row = $this->matcher->join($fixed, $results[$id]);
            unset($results[$id]);
            $joined[] = $row;
            $contribution = $this->matcher->comparison($row);
            $this->metrics->add($summary, $contribution['comparison']);
            foreach ($contribution['unevaluable'] as $metric => $reason) {
                $excluded[$metric][$reason] = ($excluded[$metric][$reason] ?? 0) + 1;
            }
            $primary = $actual = $matches = [];
            foreach ([1, 2, 3] as $position) {
                $primary[] = $row['prediction']['decision']['primary_position_'.$position.'_bike'];
                $official = array_values(array_map(fn (array $e): int => $e['bike'], array_filter($row['context']['entries'],
                    fn (array $e): bool => in_array($e['status'], ['FINISHED', 'TIED'], true) && $e['rank'] === $position)));
                $actual[] = $official;
                $matches[] = count($official) === 1 ? $official[0] === $primary[$position - 1] : null;
            }
            $contributions[] = $contribution + ['primary' => $primary, 'actual_top3_sets' => $actual, 'position_matches' => $matches];
        }
        if ($results !== []) {
            throw new RuntimeException('Extra extracted results.');
        }
        $finished = $this->metrics->finish($summary);
        $display = [];
        foreach (Bt03e05MetricEvaluator::METRIC_CODES as $metric) {
            $denominator = $summary['denominators'][$metric];
            $display[$metric] = ['numerator' => $summary['candidate_numerators'][$metric],
                'baseline_numerator' => $summary['baseline_numerators'][$metric], 'denominator' => $denominator,
                'rate' => $denominator > 0 ? $finished['candidate'][$metric] : null,
                'baseline_rate' => $denominator > 0 ? $finished['baseline'][$metric] : null,
                'delta' => $denominator > 0 ? $finished['delta'][$metric] : null,
                'reason' => $denominator > 0 ? null : 'UNEVALUABLE_ZERO_DENOMINATOR',
                'excluded_races' => array_sum($excluded[$metric]), 'excluded_reasons' => $excluded[$metric]];
        }
        $n = $summary['diagnostic_counts']['PRIMARY_EXACT_ORDERED_TOP3_RATE'];
        $d = $summary['diagnostic_counts']['ordered_eligible'];
        $result = ['matched' => $summary['race_count'], 'missing' => 0, 'mismatched' => 0, 'metrics' => $display,
            'primary_exact_ordered_top3' => ['numerator' => $n, 'denominator' => $d, 'rate' => $d > 0 ? $n / $d : null,
                'reason' => $d > 0 ? null : 'NO_UNIQUE_OFFICIAL_ORDERED_TOP3'],
            'accumulator' => $summary, 'evaluator' => $finished, 'use' => Contract::plan(),
            'hit_at_3_semantics' => 'POSITION_HITS / (3 * UNIQUE_ORDERED_TOP3_RACES)',
            'exact_ordered_top3_semantics' => 'SUPPORTING_MAP; PRIMARY_IS_SEPARATE'];

        return ['joined.jsonl' => $joined, 'contributions.jsonl' => $contributions, 'summary.json' => $result];
    }
}
