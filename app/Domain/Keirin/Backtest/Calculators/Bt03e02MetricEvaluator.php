<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Calculators;

use RuntimeException;

class Bt03e02MetricEvaluator
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

    public function __construct(private readonly Bt03e02Scorer $scorer) {}

    /**
     * @param  callable(): iterable<array<string,mixed>>  $predictionSource
     * @param  array{IS_WIN:float,IS_TOP2:float,IS_TOP3:float,key:string}  $alpha
     * @return array<string,mixed>
     */
    public function evaluatePaired(callable $predictionSource, array $alpha): array
    {
        $candidateNumerators = $baselineNumerators = $denominators = array_fill_keys(self::METRIC_CODES, 0.0);
        $raceCount = $orderedEligible = $orderedExcluded = 0;
        $tieTotals = [
            'exact_ranking_score_tied_races' => 0,
            'exact_ranking_score_tied_entries' => 0,
            'resolved_by_win_score' => 0,
            'resolved_by_top2_score' => 0,
            'resolved_by_top3_score' => 0,
            'resolved_by_stat01_raw' => 0,
            'technical_tiebreak_races' => 0,
            'technical_tiebreak_entries' => 0,
            'baseline_exact_score_tied_races' => 0,
            'baseline_exact_score_tied_entries' => 0,
        ];
        $minimumGap = null;

        foreach ($predictionSource() as $race) {
            $comparison = $this->raceComparison($race, $alpha);
            $raceCount++;
            $orderedEligible += $comparison['ordered_eligible'];
            $orderedExcluded += 1 - $comparison['ordered_eligible'];
            foreach (self::METRIC_CODES as $metric) {
                $candidateNumerators[$metric] += $comparison['candidate'][$metric]['numerator'];
                $baselineNumerators[$metric] += $comparison['baseline'][$metric]['numerator'];
                $denominators[$metric] += $comparison['candidate'][$metric]['denominator'];
            }
            foreach ($tieTotals as $key => $_) {
                $sourceKey = str_replace('races', 'race', $key);
                $tieTotals[$key] += (int) ($comparison['ties'][$sourceKey] ?? $comparison['ties'][$key] ?? 0);
            }
            $gap = $comparison['ties']['minimum_score_gap'];
            if ($gap !== null) {
                $minimumGap = $minimumGap === null ? $gap : min($minimumGap, $gap);
            }
        }
        if ($raceCount === 0) {
            throw new RuntimeException('BT-03E-02 metric evaluation had no races.');
        }
        $candidate = $baseline = $delta = [];
        foreach (self::METRIC_CODES as $metric) {
            $denominator = $denominators[$metric];
            $candidate[$metric] = $denominator > 0.0 ? $candidateNumerators[$metric] / $denominator : 0.0;
            $baseline[$metric] = $denominator > 0.0 ? $baselineNumerators[$metric] / $denominator : 0.0;
            $delta[$metric] = $candidate[$metric] - $baseline[$metric];
        }

        return [
            'candidate' => $candidate,
            'baseline' => $baseline,
            'delta' => $delta,
            'denominators' => $denominators,
            'race_count' => $raceCount,
            'ordered_eligible_race_count' => $orderedEligible,
            'ordered_excluded_race_count' => $orderedExcluded,
            'tie_diagnostics' => [...$tieTotals, 'minimum_score_gap' => $minimumGap],
        ];
    }

    /**
     * @param  array<string,mixed>  $race
     * @param  array{IS_WIN:float,IS_TOP2:float,IS_TOP3:float,key:string}  $alpha
     * @return array{candidate:array<string,array{numerator:float,denominator:float}>,baseline:array<string,array{numerator:float,denominator:float}>,ordered_eligible:int,ties:array<string,int|float|null>}
     */
    public function raceComparison(array $race, array $alpha): array
    {
        $ranked = $this->scorer->rank($race['race_id'], $race['entries'], $alpha);
        $candidate = array_map('intval', array_column($ranked['entries'], 'bike'));
        $baselineEntries = $this->rankBaseline((int) $race['race_id'], $race['entries']);
        $baseline = array_map('intval', array_column($baselineEntries, 'bike'));
        $official = $this->officialRanks($race['entries']);

        return [
            'candidate' => $this->contributions($candidate, $official),
            'baseline' => $this->contributions($baseline, $official),
            'ordered_eligible' => (int) ($this->orderedTop3($official) !== null),
            'ties' => [...$ranked['diagnostics'], ...$this->baselineTies($baselineEntries)],
        ];
    }

    /** @param list<array<string,mixed>> $entries @return list<array<string,mixed>> */
    public function rankBaseline(int $raceId, array $entries): array
    {
        foreach ($entries as &$entry) {
            $entry['technical_key'] = $this->scorer->technicalKey($raceId, (int) $entry['bike']);
        }
        unset($entry);
        usort($entries, static fn (array $left, array $right): int => [
            -(float) $left['raw'], $left['technical_key'],
        ] <=> [
            -(float) $right['raw'], $right['technical_key'],
        ]);

        return $entries;
    }

    /** @param list<int> $predicted @param array<int,list<int>> $official @return array<string,array{numerator:float,denominator:float}> */
    private function contributions(array $predicted, array $official): array
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
        $values['EXACT_ORDERED_TOP3_RATE'] = [
            'numerator' => (float) ($ordered !== null && array_slice($predicted, 0, 3) === $ordered),
            'denominator' => (float) ($ordered !== null),
        ];
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
        $idealRelevance = array_values($relevance);
        rsort($idealRelevance, SORT_NUMERIC);
        $ideal = 0.0;
        foreach (array_slice($idealRelevance, 0, 3) as $offset => $value) {
            $ideal += ((2 ** $value) - 1) / log($offset + 2, 2);
        }

        return $ideal > 0.0 ? $dcg / $ideal : 0.0;
    }

    /** @param list<array<string,mixed>> $entries @return array{baseline_exact_score_tied_race:int,baseline_exact_score_tied_entries:int} */
    private function baselineTies(array $entries): array
    {
        $groups = [];
        foreach ($entries as $entry) {
            $groups[sprintf('%.17g', $entry['raw'])] = ($groups[sprintf('%.17g', $entry['raw'])] ?? 0) + 1;
        }
        $tied = array_filter($groups, static fn (int $count): bool => $count > 1);

        return [
            'baseline_exact_score_tied_race' => (int) ($tied !== []),
            'baseline_exact_score_tied_entries' => array_sum($tied),
        ];
    }
}
