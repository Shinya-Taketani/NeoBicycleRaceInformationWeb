<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\C1CompositionArchive;

use App\Domain\Keirin\Backtest\Calculators\Bt03e05MetricEvaluator;

final class Metrics
{
    public function __construct(private readonly Bt03e05MetricEvaluator $metrics) {}

    public function display(array $joined, array $contribution): array
    {
        $primary = $actual = $matches = [];
        foreach ([1, 2, 3] as $position) {
            $primary[] = $joined['prediction']['decision']['primary_position_'.$position.'_bike'];
            $official = array_values(array_map(fn (array $e): int => $e['bike'], array_filter($joined['context']['entries'],
                fn (array $e): bool => in_array($e['status'], ['FINISHED', 'TIED'], true) && $e['rank'] === $position)));
            $actual[] = $official;
            $matches[] = count($official) === 1 ? $official[0] === $primary[$position - 1] : null;
        }

        return $contribution + ['primary' => $primary, 'actual_top3_sets' => $actual, 'position_matches' => $matches];
    }

    public function summary(array $accumulator, array $excluded): array
    {
        $finished = $this->metrics->finish($accumulator);
        $display = [];
        foreach (Bt03e05MetricEvaluator::METRIC_CODES as $metric) {
            $d = $accumulator['denominators'][$metric];
            $display[$metric] = ['numerator' => $accumulator['candidate_numerators'][$metric],
                'baseline_numerator' => $accumulator['baseline_numerators'][$metric], 'denominator' => $d,
                'rate' => $d > 0 ? $finished['candidate'][$metric] : null,
                'baseline_rate' => $d > 0 ? $finished['baseline'][$metric] : null,
                'delta' => $d > 0 ? $finished['delta'][$metric] : null,
                'reason' => $d > 0 ? null : 'UNEVALUABLE_ZERO_DENOMINATOR',
                'excluded_races' => array_sum($excluded[$metric]), 'excluded_reasons' => $excluded[$metric]];
        }
        $n = $accumulator['diagnostic_counts']['PRIMARY_EXACT_ORDERED_TOP3_RATE'];
        $d = $accumulator['diagnostic_counts']['ordered_eligible'];

        return ['matched' => $accumulator['race_count'], 'missing' => 0, 'mismatched' => 0, 'metrics' => $display,
            'primary_exact_ordered_top3' => ['numerator' => $n, 'denominator' => $d, 'rate' => $d > 0 ? $n / $d : null,
                'reason' => $d > 0 ? null : 'NO_UNIQUE_OFFICIAL_ORDERED_TOP3'],
            'accumulator' => $accumulator, 'evaluator' => $finished, 'use' => Contract::plan(),
            'hit_at_3_semantics' => 'POSITION_HITS / (3 * UNIQUE_ORDERED_TOP3_RACES)',
            'exact_ordered_top3_semantics' => 'SUPPORTING_MAP; PRIMARY_IS_SEPARATE'];
    }
}
