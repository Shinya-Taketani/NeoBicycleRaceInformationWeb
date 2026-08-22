<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Calculators;

use App\Domain\Keirin\Backtest\DTO\Bt03eCandidateDto;
use App\Domain\Keirin\Backtest\DTO\Bt03eMetricSummaryDto;

class Bt03eRaceMetricEvaluator
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

    public function __construct(private readonly Bt03ePointScorer $scorer) {}

    /** @param iterable<array{race_id: int, entries: list<array{id: int, bike: int, raw: float, stat01_rank: int, directions: list<int>, rank: ?int, status: string}>}> $races */
    public function evaluate(iterable $races, Bt03eCandidateDto $candidate): Bt03eMetricSummaryDto
    {
        return $this->evaluateRanked($races, fn (array $entries): array => $this->scorer->rank($entries, $candidate));
    }

    /** @param iterable<array{race_id: int, entries: list<array{id: int, bike: int, raw: float, stat01_rank: int, directions: list<int>, rank: ?int, status: string}>}> $races */
    public function evaluateBaseline(iterable $races): Bt03eMetricSummaryDto
    {
        return $this->evaluateRanked($races, fn (array $entries): array => $this->scorer->rankBaseline($entries));
    }

    /**
     * @param  iterable<array{race_id: int, entries: list<array{id: int, bike: int, raw: float, stat01_rank: int, directions: list<int>, rank: ?int, status: string}>}>  $races
     * @param  callable(list<array<string, mixed>>): array{entries: list<array<string, mixed>>, tied_entries: int, stat01_tie_breaks: int, tied: bool}  $ranker
     */
    private function evaluateRanked(iterable $races, callable $ranker): Bt03eMetricSummaryDto
    {
        $raceCount = $entryCount = $orderedEligible = $orderedExcluded = 0;
        $positionEligible = [1 => 0, 2 => 0, 3 => 0];
        $positionHits = [1 => 0, 2 => 0, 3 => 0];
        $orderedPositionHits = [1 => 0, 2 => 0, 3 => 0];
        $exactOrdered = $exactTop3 = $exactTop2 = 0;
        $top3Hits = $top2Hits = 0;
        $ndcgSum = 0.0;
        $tiedRaces = $tiedEntries = $stat01TieBreaks = 0;
        $exclusionReasons = [];
        $positionExclusionReasons = [];

        foreach ($races as $race) {
            $raceCount++;
            $entryCount += count($race['entries']);
            $scored = $ranker($race['entries']);
            $predicted = array_map('intval', array_column($scored['entries'], 'bike'));
            $tiedRaces += (int) $scored['tied'];
            $tiedEntries += $scored['tied_entries'];
            $stat01TieBreaks += $scored['stat01_tie_breaks'];

            $official = $this->officialRanks($race['entries']);
            foreach ([1, 2, 3] as $position) {
                if (count($official[$position] ?? []) === 1) {
                    $positionEligible[$position]++;
                    $positionHits[$position] += (int) (($predicted[$position - 1] ?? null) === $official[$position][0]);
                } else {
                    $reason = "POSITION_{$position}_NON_UNIQUE_OR_MISSING";
                    $positionExclusionReasons[$reason] = ($positionExclusionReasons[$reason] ?? 0) + 1;
                }
            }
            $ordered = $this->orderedTop3($official);
            if ($ordered === null) {
                $orderedExcluded++;
                $exclusionReasons['NON_UNIQUE_OR_MISSING_OFFICIAL_TOP3'] = ($exclusionReasons['NON_UNIQUE_OR_MISSING_OFFICIAL_TOP3'] ?? 0) + 1;
            } else {
                $orderedEligible++;
                foreach ([1, 2, 3] as $position) {
                    $orderedPositionHits[$position] += (int) (($predicted[$position - 1] ?? null) === $ordered[$position - 1]);
                }
                $exactOrdered += (int) (array_slice($predicted, 0, 3) === $ordered);
            }

            $actualTop3 = $this->topSet($official, 3);
            $actualTop2 = $this->topSet($official, 2);
            $predictedTop3 = array_slice($predicted, 0, 3);
            $predictedTop2 = array_slice($predicted, 0, 2);
            $exactTop3 += (int) ($this->set($predictedTop3) === $this->set($actualTop3));
            $exactTop2 += (int) ($this->set($predictedTop2) === $this->set($actualTop2));
            $top3Hits += count(array_intersect($predictedTop3, $actualTop3));
            $top2Hits += count(array_intersect($predictedTop2, $actualTop2));
            $ndcgSum += $this->ndcgAt3($predicted, $official);
        }

        $positionDenominators = array_map(static fn (int $count): int => max(1, $count), $positionEligible);
        $orderedDenominator = max(1, $orderedEligible);
        $raceDenominator = max(1, $raceCount);
        $metrics = [
            'WINNER_HIT_AT_1' => (float) ($positionHits[1] / $positionDenominators[1]),
            'POSITION_1_ACCURACY' => (float) ($positionHits[1] / $positionDenominators[1]),
            'POSITION_2_ACCURACY' => (float) ($positionHits[2] / $positionDenominators[2]),
            'POSITION_3_ACCURACY' => (float) ($positionHits[3] / $positionDenominators[3]),
            'POSITION_HIT_RATE_AT_3' => (float) (array_sum($orderedPositionHits) / (3 * $orderedDenominator)),
            'EXACT_ORDERED_TOP3_RATE' => (float) ($exactOrdered / $orderedDenominator),
            'EXACT_TOP3_SET_RATE' => (float) ($exactTop3 / $raceDenominator),
            'TOP3_COVERAGE_AT_3' => (float) ($top3Hits / (3 * $raceDenominator)),
            'EXACT_TOP2_SET_RATE' => (float) ($exactTop2 / $raceDenominator),
            'TOP2_COVERAGE_AT_2' => (float) ($top2Hits / (2 * $raceDenominator)),
            'NDCG_AT_3' => (float) ($ndcgSum / $raceDenominator),
        ];

        return new Bt03eMetricSummaryDto(
            $metrics,
            $raceCount,
            $entryCount,
            $positionEligible[1],
            $positionEligible[2],
            $positionEligible[3],
            $orderedEligible,
            $orderedExcluded,
            $exclusionReasons,
            $positionExclusionReasons,
            $tiedRaces,
            $tiedEntries,
            $stat01TieBreaks,
        );
    }

    /** @param list<array<string, mixed>> $entries @return array<int, list<int>> */
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

    /** @param array<int, list<int>> $ranks @return list<int>|null */
    private function orderedTop3(array $ranks): ?array
    {
        foreach ([1, 2, 3] as $rank) {
            if (count($ranks[$rank] ?? []) !== 1) {
                return null;
            }
        }

        return [$ranks[1][0], $ranks[2][0], $ranks[3][0]];
    }

    /** @param array<int, list<int>> $ranks @return list<int> */
    private function topSet(array $ranks, int $maximumRank): array
    {
        $values = [];
        foreach ($ranks as $rank => $bikes) {
            if ($rank <= $maximumRank) {
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

    /** @param list<int> $predicted @param array<int, list<int>> $ranks */
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
            $gain = (2 ** ($relevance[$bike] ?? 0)) - 1;
            $dcg += $gain / log($offset + 2, 2);
        }
        $idealRelevance = array_values($relevance);
        rsort($idealRelevance, SORT_NUMERIC);
        $ideal = 0.0;
        foreach (array_slice($idealRelevance, 0, 3) as $offset => $value) {
            $ideal += ((2 ** $value) - 1) / log($offset + 2, 2);
        }

        return $ideal > 0.0 ? $dcg / $ideal : 0.0;
    }
}
