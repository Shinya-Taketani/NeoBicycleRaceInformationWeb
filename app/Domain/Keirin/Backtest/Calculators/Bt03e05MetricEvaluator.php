<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Calculators;

use App\Domain\Keirin\Backtest\Services\Bt03e02Contract;
use RuntimeException;

final class Bt03e05MetricEvaluator
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

    /** @return array<string,mixed> */
    public function emptySummary(): array
    {
        return [
            'candidate_numerators' => array_fill_keys(self::METRIC_CODES, 0.0),
            'baseline_numerators' => array_fill_keys(self::METRIC_CODES, 0.0),
            'denominators' => array_fill_keys(self::METRIC_CODES, 0.0),
            'race_count' => 0,
            'ordered_eligible_race_count' => 0,
            'tie_diagnostics' => [
                'primary_score_tied_races' => 0,
                'primary_score_tied_combinations' => 0,
                'technical_tiebreak_races' => 0,
                'baseline_exact_score_tied_races' => 0,
                'baseline_exact_score_tied_entries' => 0,
            ],
            'diagnostic_counts' => [
                'PRIMARY_WINNER_HIT_AT_1' => 0.0,
                'PRIMARY_EXACT_ORDERED_TOP3_RATE' => 0.0,
                'MAP_ORDERED_EXACT_ORDERED_TOP3_RATE' => 0.0,
                'MAP_TOP3_SET_RATE' => 0.0,
                'winner_eligible' => 0.0,
                'ordered_eligible' => 0.0,
            ],
        ];
    }

    /** @param array<string,mixed> $summary @param array<string,mixed> $comparison */
    public function add(array &$summary, array $comparison): void
    {
        foreach (self::METRIC_CODES as $metric) {
            $summary['candidate_numerators'][$metric] += $comparison['candidate'][$metric]['numerator'];
            $summary['baseline_numerators'][$metric] += $comparison['baseline'][$metric]['numerator'];
            $summary['denominators'][$metric] += $comparison['candidate'][$metric]['denominator'];
        }
        $summary['race_count']++;
        $summary['ordered_eligible_race_count'] += $comparison['ordered_eligible'];
        foreach ($summary['tie_diagnostics'] as $key => $_) {
            $summary['tie_diagnostics'][$key] += $comparison['ties'][$key];
        }
        foreach ($summary['diagnostic_counts'] as $key => $_) {
            $summary['diagnostic_counts'][$key] += $comparison['diagnostics'][$key];
        }
    }

    /** @param array<string,mixed> $summary @return array<string,mixed> */
    public function finish(array $summary): array
    {
        if ($summary['race_count'] < 1) {
            throw new RuntimeException('BT-03E-05 metric evaluation had no races.');
        }
        $candidate = $baseline = $delta = [];
        foreach (self::METRIC_CODES as $metric) {
            $denominator = $summary['denominators'][$metric];
            $candidate[$metric] = $denominator > 0.0 ? $summary['candidate_numerators'][$metric] / $denominator : 0.0;
            $baseline[$metric] = $denominator > 0.0 ? $summary['baseline_numerators'][$metric] / $denominator : 0.0;
            $delta[$metric] = $candidate[$metric] - $baseline[$metric];
        }
        $diagnostics = $summary['diagnostic_counts'];
        $winnerEligible = $diagnostics['winner_eligible'];
        $orderedEligible = $diagnostics['ordered_eligible'];

        return [
            'candidate' => $candidate,
            'baseline' => $baseline,
            'delta' => $delta,
            'denominators' => $summary['denominators'],
            'race_count' => $summary['race_count'],
            'ordered_eligible_race_count' => $summary['ordered_eligible_race_count'],
            'ordered_excluded_race_count' => $summary['race_count'] - $summary['ordered_eligible_race_count'],
            'tie_diagnostics' => $summary['tie_diagnostics'],
            'decoder_diagnostics' => [
                'PRIMARY_WINNER_HIT_AT_1' => $winnerEligible > 0 ? $diagnostics['PRIMARY_WINNER_HIT_AT_1'] / $winnerEligible : 0.0,
                'PRIMARY_EXACT_ORDERED_TOP3_RATE' => $orderedEligible > 0 ? $diagnostics['PRIMARY_EXACT_ORDERED_TOP3_RATE'] / $orderedEligible : 0.0,
                'MAP_ORDERED_EXACT_ORDERED_TOP3_RATE' => $orderedEligible > 0 ? $diagnostics['MAP_ORDERED_EXACT_ORDERED_TOP3_RATE'] / $orderedEligible : 0.0,
                'MAP_TOP3_SET_RATE' => $diagnostics['MAP_TOP3_SET_RATE'] / $summary['race_count'],
            ],
        ];
    }

    /** @param array<string,mixed> $context @param array<string,mixed> $decision @return array<string,mixed> */
    public function raceComparison(array $context, array $decision): array
    {
        if (($context['year'] ?? null) !== ($decision['year'] ?? null)
            || ($context['race_id'] ?? null) !== ($decision['race_id'] ?? null)
            || ! is_array($context['entries'] ?? null) || $context['entries'] === []) {
            throw new RuntimeException('BT-03E-05 metric context and decision identities differed.');
        }
        $entries = $context['entries'];
        $official = $this->officialRanks($entries);
        $ordered = $this->orderedTop3($official);
        $primary = [
            $decision['primary_position_1_bike'],
            $decision['primary_position_2_bike'],
            $decision['primary_position_3_bike'],
        ];
        $candidate = $this->candidateContributions($decision, $primary, $official);
        $baselineEntries = $this->rankBaseline((int) $context['race_id'], $entries);
        $baselineBikes = array_map('intval', array_column($baselineEntries, 'bike'));
        $baseline = $this->rankingContributions($baselineBikes, $official);
        $winnerEligible = count($official[1] ?? []) === 1;
        $winner = $winnerEligible ? $official[1][0] : null;

        return [
            'candidate' => $candidate,
            'baseline' => $baseline,
            'ordered_eligible' => (int) ($ordered !== null),
            'ties' => [
                'primary_score_tied_races' => (int) $decision['primary_decision_tied'],
                'primary_score_tied_combinations' => ($decision['winner_tie_count'] > 1 ? $decision['winner_tie_count'] : 0)
                    + ($decision['second_third_tie_count'] > 1 ? $decision['second_third_tie_count'] : 0),
                'technical_tiebreak_races' => (int) $decision['primary_technical_tiebreak_used'],
                ...$this->baselineTies($baselineEntries),
            ],
            'diagnostics' => [
                'PRIMARY_WINNER_HIT_AT_1' => (float) ($winnerEligible && $primary[0] === $winner),
                'PRIMARY_EXACT_ORDERED_TOP3_RATE' => (float) ($ordered !== null && $primary === $ordered),
                'MAP_ORDERED_EXACT_ORDERED_TOP3_RATE' => (float) ($ordered !== null && $decision['map_ordered_top3'] === $ordered),
                'MAP_TOP3_SET_RATE' => (float) ($this->set($decision['map_top3_set']) === $this->set($this->topSet($official, 3))),
                'winner_eligible' => (float) $winnerEligible,
                'ordered_eligible' => (float) ($ordered !== null),
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

    /** @param array<string,mixed> $decision @param list<int> $primary @param array<int,list<int>> $official @return array<string,array{numerator:float,denominator:float}> */
    private function candidateContributions(array $decision, array $primary, array $official): array
    {
        $values = $this->rankingContributions($primary, $official);
        $ordered = $this->orderedTop3($official);
        $actualTop3 = $this->topSet($official, 3);
        $actualTop2 = $this->topSet($official, 2);
        $values['EXACT_ORDERED_TOP3_RATE'] = [
            'numerator' => (float) ($ordered !== null && $decision['map_ordered_top3'] === $ordered),
            'denominator' => (float) ($ordered !== null),
        ];
        $values['EXACT_TOP3_SET_RATE'] = [
            'numerator' => (float) ($this->set($decision['map_top3_set']) === $this->set($actualTop3)),
            'denominator' => 1.0,
        ];
        $values['TOP3_COVERAGE_AT_3'] = [
            'numerator' => (float) count(array_intersect($decision['top3_marginal_bikes'], $actualTop3)),
            'denominator' => 3.0,
        ];
        $values['EXACT_TOP2_SET_RATE'] = [
            'numerator' => (float) ($this->set($decision['top2_marginal_bikes']) === $this->set($actualTop2)),
            'denominator' => 1.0,
        ];
        $values['TOP2_COVERAGE_AT_2'] = [
            'numerator' => (float) count(array_intersect($decision['top2_marginal_bikes'], $actualTop2)),
            'denominator' => 2.0,
        ];
        $values['NDCG_AT_3'] = [
            'numerator' => $this->ndcgAt3($decision['expected_ndcg_top3'], $official),
            'denominator' => 1.0,
        ];

        return $values;
    }

    /** @param list<int> $predicted @param array<int,list<int>> $official @return array<string,array{numerator:float,denominator:float}> */
    private function rankingContributions(array $predicted, array $official): array
    {
        $values = [];
        foreach ([1, 2, 3] as $position) {
            $eligible = count($official[$position] ?? []) === 1;
            $values["POSITION_{$position}_ACCURACY"] = [
                'numerator' => (float) ($eligible && ($predicted[$position - 1] ?? null) === $official[$position][0]),
                'denominator' => (float) $eligible,
            ];
        }
        $values['WINNER_HIT_AT_1'] = $values['POSITION_1_ACCURACY'];
        $ordered = $this->orderedTop3($official);
        $hits = 0;
        if ($ordered !== null) {
            foreach ([0, 1, 2] as $offset) {
                $hits += (int) (($predicted[$offset] ?? null) === $ordered[$offset]);
            }
        }
        $values['POSITION_HIT_RATE_AT_3'] = ['numerator' => (float) $hits, 'denominator' => $ordered === null ? 0.0 : 3.0];
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
        $idealValues = array_values($relevance);
        rsort($idealValues, SORT_NUMERIC);
        $ideal = 0.0;
        foreach (array_slice($idealValues, 0, 3) as $offset => $value) {
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
