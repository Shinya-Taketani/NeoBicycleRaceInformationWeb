<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Calculators;

use App\Domain\Keirin\Backtest\Services\Bt03e02Contract;
use App\Domain\Keirin\Backtest\Services\Bt03e03Contract;
use RuntimeException;

final class Bt03e03MetricEvaluator
{
    public const METRIC_CODES = [
        'WINNER_HIT_AT_1',
        'POSITION_1_ACCURACY',
        'POSITION_2_ACCURACY',
        'POSITION_3_ACCURACY',
        'POSITION_HIT_RATE_AT_3',
        'EXACT_ORDERED_TOP3_RATE',
        'EXACT_TOP3_SET_RATE',
        'TOP3_COVERAGE_AT_3',
        'EXACT_TOP2_SET_RATE',
        'TOP2_COVERAGE_AT_2',
        'NDCG_AT_3',
    ];

    /** @param callable():iterable<array<string,mixed>> $predictionSource @return array<string,mixed> */
    public function evaluate(callable $predictionSource): array
    {
        $candidateNumerators = $baselineNumerators = $denominators = array_fill_keys(self::METRIC_CODES, 0.0);
        $probabilitySums = array_fill_keys($this->probabilityMetricCodes(), 0.0);
        $probabilityCounts = array_fill_keys(Bt03e03Contract::POSITIONS, 0);
        $calibration = [];
        foreach (Bt03e03Contract::POSITIONS as $position) {
            $calibration[$position] = array_fill(0, 10, ['count' => 0, 'predicted_sum' => 0.0, 'observed_sum' => 0.0]);
        }
        $raceCount = $orderedEligible = $orderedExcluded = $mapSetHits = 0;
        $ties = [
            'ordered_probability_tied_races' => 0,
            'ordered_probability_tied_combinations' => 0,
            'technical_tiebreak_races' => 0,
            'baseline_exact_score_tied_races' => 0,
            'baseline_exact_score_tied_entries' => 0,
        ];
        foreach ($predictionSource() as $race) {
            $comparison = $this->raceComparison($race);
            $raceCount++;
            $orderedEligible += $comparison['ordered_eligible'];
            $orderedExcluded += 1 - $comparison['ordered_eligible'];
            foreach (self::METRIC_CODES as $metric) {
                $candidateNumerators[$metric] += $comparison['candidate'][$metric]['numerator'];
                $baselineNumerators[$metric] += $comparison['baseline'][$metric]['numerator'];
                $denominators[$metric] += $comparison['candidate'][$metric]['denominator'];
            }
            foreach ($ties as $key => $_) {
                $ties[$key] += (int) ($comparison['ties'][$key] ?? 0);
            }
            $probability = $this->probabilityContributions($race);
            foreach ($probability['metrics'] as $metric => $value) {
                $probabilitySums[$metric] += $value;
            }
            foreach ($probability['eligible'] as $position => $eligible) {
                $probabilityCounts[$position] += $eligible;
            }
            foreach ($probability['calibration'] as $position => $bins) {
                foreach ($bins as $bin => $values) {
                    foreach (array_keys($values) as $key) {
                        $calibration[$position][$bin][$key] += $values[$key];
                    }
                }
            }
            $mapSetHits += $probability['map_set_hit'];
        }
        if ($raceCount === 0) {
            throw new RuntimeException('BT-03E-03 metric evaluation had no races.');
        }
        $candidate = $baseline = $delta = [];
        foreach (self::METRIC_CODES as $metric) {
            $denominator = $denominators[$metric];
            $candidate[$metric] = $denominator > 0.0 ? $candidateNumerators[$metric] / $denominator : 0.0;
            $baseline[$metric] = $denominator > 0.0 ? $baselineNumerators[$metric] / $denominator : 0.0;
            $delta[$metric] = $candidate[$metric] - $baseline[$metric];
        }
        $probabilityMetrics = [];
        foreach (Bt03e03Contract::POSITIONS as $position) {
            $count = $probabilityCounts[$position];
            if ($count < 1) {
                $probabilityMetrics[$position.'_LOG_LOSS'] = null;
                $probabilityMetrics[$position.'_BRIER'] = null;

                continue;
            }
            $probabilityMetrics[$position.'_LOG_LOSS'] = $probabilitySums[$position.'_LOG_LOSS'] / $count;
            $probabilityMetrics[$position.'_BRIER'] = $probabilitySums[$position.'_BRIER'] / $count;
        }
        $calibrationSummary = [];
        foreach ($calibration as $position => $bins) {
            foreach ($bins as $index => $values) {
                $count = $values['count'];
                $calibrationSummary[$position][] = [
                    'bin' => $index,
                    'lower' => $index / 10,
                    'upper' => ($index + 1) / 10,
                    'count' => $count,
                    'mean_predicted_probability' => $count > 0 ? $values['predicted_sum'] / $count : null,
                    'observed_rate' => $count > 0 ? $values['observed_sum'] / $count : null,
                ];
            }
        }

        return [
            'candidate' => $candidate,
            'baseline' => $baseline,
            'delta' => $delta,
            'denominators' => $denominators,
            'race_count' => $raceCount,
            'ordered_eligible_race_count' => $orderedEligible,
            'ordered_excluded_race_count' => $orderedExcluded,
            'tie_diagnostics' => $ties,
            'probability_metrics' => $probabilityMetrics,
            'probability_metric_eligible_races' => $probabilityCounts,
            'calibration' => [
                'version' => Bt03e03Contract::CALIBRATION_DIAGNOSTIC_VERSION,
                'positions' => $calibrationSummary,
            ],
            'map_diagnostics' => [
                'TOP3_SET_MAP_RATE' => $mapSetHits / $raceCount,
                'map_top3_set_hits' => $mapSetHits,
                'race_count' => $raceCount,
            ],
        ];
    }

    /** @param array<string,mixed> $race @return array<string,mixed> */
    public function raceComparison(array $race): array
    {
        $entries = $race['entries'] ?? null;
        if (! is_array($entries) || $entries === []) {
            throw new RuntimeException('BT-03E-03 metric race entries were empty.');
        }
        $candidateEntries = $entries;
        usort($candidateEntries, static fn (array $left, array $right): int => $left['predicted_position'] <=> $right['predicted_position']);
        $candidate = array_map('intval', array_column($candidateEntries, 'bike'));
        $baselineEntries = $this->rankBaseline((int) $race['race_id'], $entries);
        $baseline = array_map('intval', array_column($baselineEntries, 'bike'));
        $official = $this->officialRanks($entries);
        $tie = $entries[0]['map_tie_diagnostics'] ?? [];
        $baselineTies = $this->baselineTies($baselineEntries);

        return [
            'candidate' => $this->rankingContributions($candidate, $official),
            'baseline' => $this->rankingContributions($baseline, $official),
            'ordered_eligible' => (int) ($this->orderedTop3($official) !== null),
            'ties' => [
                'ordered_probability_tied_races' => (int) ($tie['ordered_probability_tied_race'] ?? 0),
                'ordered_probability_tied_combinations' => (int) ($tie['ordered_probability_tied_combinations'] ?? 0),
                'technical_tiebreak_races' => (int) ($tie['technical_tiebreak_used'] ?? false),
                ...$baselineTies,
            ],
        ];
    }

    /** @param list<array<string,mixed>> $entries @return list<array<string,mixed>> */
    public function rankBaseline(int $raceId, array $entries): array
    {
        foreach ($entries as &$entry) {
            $entry['baseline_technical_key'] = hash('sha256', Bt03e02Contract::TIE_RULE_VERSION.'|'.$raceId.'|'.$entry['bike']);
        }
        unset($entry);
        usort($entries, static fn (array $left, array $right): int => [
            -(float) $left['raw'], $left['baseline_technical_key'],
        ] <=> [
            -(float) $right['raw'], $right['baseline_technical_key'],
        ]);

        return $entries;
    }

    /** @return list<string> */
    private function probabilityMetricCodes(): array
    {
        $codes = [];
        foreach (Bt03e03Contract::POSITIONS as $position) {
            $codes[] = $position.'_LOG_LOSS';
            $codes[] = $position.'_BRIER';
        }

        return $codes;
    }

    /** @param array<string,mixed> $race @return array<string,mixed> */
    private function probabilityContributions(array $race): array
    {
        $entries = $race['entries'];
        $official = $this->officialRanks($entries);
        $metrics = array_fill_keys($this->probabilityMetricCodes(), 0.0);
        $eligible = array_fill_keys(Bt03e03Contract::POSITIONS, 0);
        $calibration = [];
        foreach (Bt03e03Contract::POSITIONS as $position) {
            $calibration[$position] = array_fill(0, 10, ['count' => 0, 'predicted_sum' => 0.0, 'observed_sum' => 0.0]);
        }
        foreach (Bt03e03Contract::POSITIONS as $offset => $position) {
            $rank = $offset + 1;
            $positionEligible = true;
            for ($required = 1; $required <= $rank; $required++) {
                $positionEligible = $positionEligible && count($official[$required] ?? []) === 1;
            }
            if (! $positionEligible) {
                continue;
            }
            $eligible[$position] = 1;
            $actualBike = $official[$rank][0];
            $brier = new Bt03e03CompensatedSum;
            $actualLogProbability = null;
            foreach ($entries as $entry) {
                $probability = (float) $entry['position_'.$rank.'_probability'];
                $observed = (float) ((int) $entry['bike'] === $actualBike);
                $brier->add(($probability - $observed) ** 2);
                if ($observed === 1.0) {
                    $actualLogProbability = (float) $entry['position_'.$rank.'_log_probability'];
                }
                $bin = min(9, (int) floor($probability * 10));
                $calibration[$position][$bin]['count']++;
                $calibration[$position][$bin]['predicted_sum'] += $probability;
                $calibration[$position][$bin]['observed_sum'] += $observed;
            }
            if ($actualLogProbability === null || ! is_finite($actualLogProbability)) {
                throw new RuntimeException("BT-03E-03 {$position} actual log probability was invalid.");
            }
            $metrics[$position.'_LOG_LOSS'] = -$actualLogProbability;
            $metrics[$position.'_BRIER'] = $brier->value();
        }
        $mapSet = $entries[0]['map_top3_set'] ?? [];
        $actualSet = $this->topSet($official, 3);

        return [
            'metrics' => $metrics,
            'eligible' => $eligible,
            'calibration' => $calibration,
            'map_set_hit' => (int) ($this->set($mapSet) === $this->set($actualSet)),
        ];
    }

    /** @param list<int> $predicted @param array<int,list<int>> $official @return array<string,array{numerator:float,denominator:float}> */
    private function rankingContributions(array $predicted, array $official): array
    {
        $values = [];
        foreach ([1, 2, 3] as $position) {
            $eligible = count($official[$position] ?? []) === 1;
            $hit = $eligible && ($predicted[$position - 1] ?? null) === $official[$position][0];
            $values["POSITION_{$position}_ACCURACY"] = ['numerator' => (float) $hit, 'denominator' => (float) $eligible];
        }
        $values['WINNER_HIT_AT_1'] = $values['POSITION_1_ACCURACY'];
        $ordered = $this->orderedTop3($official);
        $orderedHits = 0;
        if ($ordered !== null) {
            foreach ([0, 1, 2] as $offset) {
                $orderedHits += (int) (($predicted[$offset] ?? null) === $ordered[$offset]);
            }
        }
        $values['POSITION_HIT_RATE_AT_3'] = ['numerator' => (float) $orderedHits, 'denominator' => $ordered === null ? 0.0 : 3.0];
        $values['EXACT_ORDERED_TOP3_RATE'] = ['numerator' => (float) ($ordered !== null && array_slice($predicted, 0, 3) === $ordered), 'denominator' => (float) ($ordered !== null)];
        $actualTop3 = $this->topSet($official, 3);
        $actualTop2 = $this->topSet($official, 2);
        $predictedTop3 = array_slice($predicted, 0, 3);
        $predictedTop2 = array_slice($predicted, 0, 2);
        $values['EXACT_TOP3_SET_RATE'] = ['numerator' => (float) ($this->set($predictedTop3) === $this->set($actualTop3)), 'denominator' => 1.0];
        $values['TOP3_COVERAGE_AT_3'] = ['numerator' => (float) count(array_intersect($predictedTop3, $actualTop3)), 'denominator' => 3.0];
        $values['EXACT_TOP2_SET_RATE'] = ['numerator' => (float) ($this->set($predictedTop2) === $this->set($actualTop2)), 'denominator' => 1.0];
        $values['TOP2_COVERAGE_AT_2'] = ['numerator' => (float) count(array_intersect($predictedTop2, $actualTop2)), 'denominator' => 2.0];
        $values['NDCG_AT_3'] = ['numerator' => $this->ndcgAt3($predicted, $official), 'denominator' => 1.0];

        return $values;
    }

    /** @param list<array<string,mixed>> $entries @return array<int,list<int>> */
    private function officialRanks(array $entries): array
    {
        $ranks = [];
        foreach ($entries as $entry) {
            if (in_array($entry['status'] ?? null, ['FINISHED', 'TIED'], true) && is_int($entry['rank'] ?? null)) {
                $ranks[$entry['rank']][] = (int) $entry['bike'];
            }
        }

        return $ranks;
    }

    /** @param array<int,list<int>> $ranks @return list<int>|null */
    private function orderedTop3(array $ranks): ?array
    {
        foreach ([1, 2, 3] as $rank) {
            if (count($ranks[$rank] ?? []) !== 1) {
                return null;
            }
        }

        return [$ranks[1][0], $ranks[2][0], $ranks[3][0]];
    }

    /** @param array<int,list<int>> $ranks @return list<int> */
    private function topSet(array $ranks, int $maximum): array
    {
        $values = [];
        foreach ($ranks as $rank => $bikes) {
            if ($rank <= $maximum) {
                array_push($values, ...$bikes);
            }
        }

        return $values;
    }

    /** @param list<int> $values @return list<int> */
    private function set(array $values): array
    {
        sort($values, SORT_NUMERIC);

        return $values;
    }

    /** @param list<int> $predicted @param array<int,list<int>> $ranks */
    private function ndcgAt3(array $predicted, array $ranks): float
    {
        $relevance = [];
        foreach ($ranks as $rank => $bikes) {
            foreach ($bikes as $bike) {
                $relevance[$bike] = $rank <= 3 ? 4 - $rank : 0;
            }
        }
        $dcg = 0.0;
        foreach (array_slice($predicted, 0, 3) as $offset => $bike) {
            $dcg += ((2 ** ($relevance[$bike] ?? 0)) - 1) / log($offset + 2, 2);
        }
        $ideal = 0.0;
        $values = array_values($relevance);
        rsort($values, SORT_NUMERIC);
        foreach (array_slice($values, 0, 3) as $offset => $value) {
            $ideal += ((2 ** $value) - 1) / log($offset + 2, 2);
        }

        return $ideal > 0.0 ? $dcg / $ideal : 0.0;
    }

    /** @param list<array<string,mixed>> $entries @return array{baseline_exact_score_tied_races:int,baseline_exact_score_tied_entries:int} */
    private function baselineTies(array $entries): array
    {
        $groups = [];
        foreach ($entries as $entry) {
            $key = sprintf('%.17g', $entry['raw']);
            $groups[$key] = ($groups[$key] ?? 0) + 1;
        }
        $tied = array_filter($groups, static fn (int $count): bool => $count > 1);

        return [
            'baseline_exact_score_tied_races' => (int) ($tied !== []),
            'baseline_exact_score_tied_entries' => array_sum($tied),
        ];
    }
}
