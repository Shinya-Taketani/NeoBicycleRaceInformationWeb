<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\Stat35C1Diagnostic;

use App\Domain\Keirin\Backtest\Calculators\Bt03e05MetricEvaluator;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use RuntimeException;

final class Transitions
{
    private array $years = [];

    public function __construct(private readonly Bt03e05MetricEvaluator $metrics) {}

    public function add(array $labels, array $first, array $second, array $savedContributions): array
    {
        $year = $labels['year'];
        if (! in_array($year, Contract::YEARS, true)) {
            throw new RuntimeException('Forbidden transition year.');
        }
        $this->years[$year] ??= $this->empty();
        $summary = &$this->years[$year];
        $summary['races']++;
        $contributions = ['C1' => $this->metrics->raceComparison($labels, $first)['candidate'],
            'C2' => $this->metrics->raceComparison($labels, $second)['candidate']];
        foreach ($contributions as $name => $values) {
            foreach (Contract::METRICS as $metric) {
                Files::same($savedContributions[$name.'-STAT01']['candidate'][$metric], $values[$metric], 'saved Primary contribution');
            }
        }
        $official = [];
        $abnormal = $ties = [];
        foreach ($labels['entries'] as $entry) {
            if (in_array($entry['status'], ['FINISHED', 'TIED'], true) && is_int($entry['rank'])) {
                $official[$entry['rank']][] = $entry['bike'];
            } else {
                $abnormal[$entry['status']] = ($abnormal[$entry['status']] ?? 0) + 1;
            }
            if ($entry['status'] === 'TIED') {
                $ties[] = $entry['bike'];
            }
        }
        ksort($abnormal);
        $summary['races_with_tied_entries'] += (int) ($ties !== []);
        $summary['races_with_abnormal_entries'] += (int) ($abnormal !== []);
        foreach ($abnormal as $status => $count) {
            $summary['abnormal_entry_statuses'][$status] = ($summary['abnormal_entry_statuses'][$status] ?? 0) + $count;
        }
        $positions = [];
        foreach ([1, 2, 3] as $position) {
            $metric = 'POSITION_'.$position.'_ACCURACY';
            $c1 = $first['primary_position_'.$position.'_bike'];
            $c2 = $second['primary_position_'.$position.'_bike'];
            $left = $contributions['C1'][$metric];
            $right = $contributions['C2'][$metric];
            $eligible = $left['denominator'] === 1.0;
            $actual = $official[$position] ?? [];
            if ($eligible !== (count($actual) === 1) || $left['denominator'] !== $right['denominator']) {
                throw new RuntimeException('Official rank eligibility differs from the frozen evaluator.');
            }
            $category = $eligible ? ($left['numerator'] === 1.0 ? ($right['numerator'] === 1.0 ? 'A' : 'B')
                : ($right['numerator'] === 1.0 ? 'C' : 'D')) : null;
            $reason = $eligible ? null : (count($actual) > 1 ? 'NON_UNIQUE_OFFICIAL_POSITION' : 'MISSING_OFFICIAL_POSITION');
            $s = &$summary['positions'][$position];
            $s['prediction_changed_all'] += (int) ($c1 !== $c2);
            if ($eligible) {
                $s[$category]++;
                $s['prediction_changed_eligible'] += (int) ($c1 !== $c2);
                $s['changed_by_transition'][$category] += (int) ($c1 !== $c2);
            } else {
                $s['excluded']++;
                $s['exclusion_reasons'][$reason] = ($s['exclusion_reasons'][$reason] ?? 0) + 1;
            }
            unset($s);
            $positions[$position] = ['c1_bike' => $c1, 'c2_bike' => $c2, 'prediction_changed' => $c1 !== $c2,
                'official_bikes' => $actual, 'eligible' => $eligible, 'exclusion_reason' => $reason, 'transition' => $category,
                'c1_correct' => $eligible ? (int) $left['numerator'] : null, 'c2_correct' => $eligible ? (int) $right['numerator'] : null];
        }
        $left = $contributions['C1']['POSITION_HIT_RATE_AT_3'];
        $right = $contributions['C2']['POSITION_HIT_RATE_AT_3'];
        $eligible = $left['denominator'] === 3.0;
        $excludedPositions = array_keys(array_filter($positions, fn ($p) => ! $p['eligible']));
        if ($eligible !== ($excludedPositions === []) || $left['denominator'] !== $right['denominator']) {
            throw new RuntimeException('Hit3 eligibility differs from frozen evaluator.');
        }
        if ($eligible) {
            $summary['hit3']['matrix'][(int) $left['numerator']][(int) $right['numerator']]++;
        } else {
            $reason = 'NON_UNIQUE_OR_MISSING_POSITIONS_'.implode('_', $excludedPositions);
            $summary['hit3']['exclusion_reasons'][$reason] = ($summary['hit3']['exclusion_reasons'][$reason] ?? 0) + 1;
            $summary['hit3']['excluded']++;
        }
        $swapped = $eligible && $positions[1]['c1_bike'] === $positions[1]['c2_bike']
            && $positions[2]['c1_bike'] === $positions[3]['c2_bike'] && $positions[3]['c1_bike'] === $positions[2]['c2_bike'];
        $summary['ordered_p2_p3_swaps'] += (int) $swapped;

        return ['year' => $year, 'race_id' => $labels['race_id'], 'positions' => $positions,
            'hit3' => ['eligible' => $eligible, 'excluded_positions' => $excludedPositions,
                'c1_matches' => $eligible ? (int) $left['numerator'] : null, 'c2_matches' => $eligible ? (int) $right['numerator'] : null],
            'ordered_p2_p3_swap' => $swapped, 'tied_bikes' => $ties, 'abnormal_entry_statuses' => $abnormal];
    }

    public function finish(array $comparisons): array
    {
        $years = $equal = [];
        foreach ($this->years as $year => $s) {
            foreach ($s['positions'] as $position => &$p) {
                $denominator = $p['A'] + $p['B'] + $p['C'] + $p['D'];
                $p += self::rates($p['A'] + $p['B'], $p['A'] + $p['C'], $denominator);
                $this->check($p, $comparisons, $year, 'POSITION_'.$position.'_ACCURACY');
                if ($denominator + $p['excluded'] !== $s['races']) {
                    throw new RuntimeException('Position partition did not retain all races.');
                }
            }
            unset($p);
            $n = $left = $right = 0;
            foreach ($s['hit3']['matrix'] as $i => $row) {
                foreach ($row as $j => $count) {
                    $n += $count;
                    $left += $i * $count;
                    $right += $j * $count;
                }
            }
            $s['hit3'] += ['eligible_races' => $n] + self::rates($left, $right, 3 * $n);
            $this->check($s['hit3'], $comparisons, $year, 'POSITION_HIT_RATE_AT_3');
            if ($n + $s['hit3']['excluded'] !== $s['races']) {
                throw new RuntimeException('Hit3 partition did not retain all races.');
            }
            ksort($s['abnormal_entry_statuses']);
            foreach ($s['positions'] as &$p) {
                ksort($p['exclusion_reasons']);
            }
            unset($p);
            ksort($s['hit3']['exclusion_reasons']);
            $years[$year] = $s;
        }
        foreach ([1, 2, 3, 'hit3'] as $key) {
            $deltas = [];
            foreach (Contract::YEARS as $year) {
                $deltas[] = $key === 'hit3' ? ($years[$year]['hit3']['delta'] ?? null) : ($years[$year]['positions'][$key]['delta'] ?? null);
            }
            $equal[$key] = in_array(null, $deltas, true) ? null : array_sum($deltas) / count(Contract::YEARS);
        }

        return ['years' => $years, 'year_equal_delta' => $equal, 'existing_primary_comparison_verified' => true];
    }

    public static function rates(int $left, int $right, int $denominator): array
    {
        $c1 = $denominator > 0 ? (float) $left / (float) $denominator : null;
        $c2 = $denominator > 0 ? (float) $right / (float) $denominator : null;

        return ['c1_numerator' => $left, 'c2_numerator' => $right, 'delta_numerator' => $right - $left,
            'denominator' => $denominator, 'c1_rate' => $c1, 'c2_rate' => $c2,
            'delta' => $denominator > 0 ? $c2 - $c1 : null, 'status' => $denominator > 0 ? 'EVALUATED' : 'NOT_EVALUATED'];
    }

    private function check(array $row, array $comparisons, int $year, string $metric): void
    {
        $old = $comparisons['outer']['C2-C1'][$year];
        Files::same([(float) $row['denominator']], [$old['denominators'][$metric]], 'saved aggregate denominator');
        // The legacy evaluator emits zero at a zero denominator. Do not inherit that presentation.
        if ($row['denominator'] > 0) {
            Files::same([$row['c1_rate'], $row['c2_rate'], $row['delta']],
                [$old['baseline'][$metric], $old['candidate'][$metric], $old['delta'][$metric]], 'saved unrounded aggregate rates');
        }
    }

    private function empty(): array
    {
        return ['races' => 0, 'races_with_tied_entries' => 0, 'races_with_abnormal_entries' => 0,
            'abnormal_entry_statuses' => [], 'ordered_p2_p3_swaps' => 0,
            'positions' => array_fill_keys([1, 2, 3], ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0,
                'prediction_changed_all' => 0, 'prediction_changed_eligible' => 0,
                'changed_by_transition' => array_fill_keys(['A', 'B', 'C', 'D'], 0), 'excluded' => 0, 'exclusion_reasons' => []]),
            'hit3' => ['matrix' => array_fill(0, 4, array_fill(0, 4, 0)), 'excluded' => 0, 'exclusion_reasons' => []]];
    }
}
