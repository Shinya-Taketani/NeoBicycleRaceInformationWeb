<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Calculators;

use App\Domain\Keirin\Backtest\Services\Bt03e05Contract;
use RuntimeException;

final class Bt03e05DecisionDecoder
{
    /** @param array<string,mixed> $race @return array<string,mixed> */
    public function decode(array $race): array
    {
        $entries = $race['entries'] ?? null;
        if (! is_array($entries) || count($entries) < 5 || count($entries) > 9) {
            throw new RuntimeException('BT-03E-05 decoder entrant count was invalid.');
        }
        $raceId = $race['race_id'] ?? null;
        if (! is_int($raceId) || $raceId < 1) {
            throw new RuntimeException('BT-03E-05 decoder race identity was invalid.');
        }

        $primary = $this->winnerPreserving($raceId, $entries);
        $top2 = $this->rank($raceId, $entries, 'TOP2_MARGINAL', 'top2_probability', 2);
        $top3 = $this->rank($raceId, $entries, 'TOP3_MARGINAL', 'top3_probability', 3);
        $ndcg = $this->rankExpectedNdcg($raceId, $entries);

        return [
            'year' => $race['year'],
            'race_id' => $raceId,
            'primary_position_1_bike' => $primary['bikes'][0],
            'primary_position_2_bike' => $primary['bikes'][1],
            'primary_position_3_bike' => $primary['bikes'][2],
            'primary_position_1_probability' => $primary['probabilities'][0],
            'primary_position_2_probability' => $primary['probabilities'][1],
            'primary_position_3_probability' => $primary['probabilities'][2],
            'primary_second_third_objective_score' => $primary['second_third_score'],
            'map_ordered_top3' => $race['map_ordered_top3'],
            'map_ordered_probability' => $race['map_ordered_probability'],
            'map_top3_set' => $race['map_top3_set'],
            'map_top3_set_probability' => $race['map_top3_set_probability'],
            'top2_marginal_bikes' => $top2['bikes'],
            'top3_marginal_bikes' => $top3['bikes'],
            'expected_ndcg_top3' => $ndcg['bikes'],
            'winner_tie_count' => $primary['winner_tie_count'],
            'second_third_tie_count' => $primary['second_third_tie_count'],
            'primary_decision_tied' => $primary['winner_tie_count'] > 1 || $primary['second_third_tie_count'] > 1,
            'primary_technical_tiebreak_used' => $primary['winner_tie_count'] > 1 || $primary['second_third_tie_count'] > 1,
            'decoder_tie_diagnostics' => [
                'PRIMARY_WINNER_P1' => ['tie_count' => $primary['winner_tie_count'], 'technical_tiebreak_used' => $primary['winner_tie_count'] > 1],
                'PRIMARY_SECOND_THIRD' => ['tie_count' => $primary['second_third_tie_count'], 'technical_tiebreak_used' => $primary['second_third_tie_count'] > 1],
                'MAP_ORDERED_TOP3' => ['tie_count' => null, 'technical_tiebreak_used' => null, 'source_diagnostic_unavailable' => true],
                'MAP_TOP3_SET' => ['tie_count' => null, 'technical_tiebreak_used' => null, 'source_diagnostic_unavailable' => true],
                'TOP2_MARGINAL' => $top2['tie'],
                'TOP3_MARGINAL' => $top3['tie'],
                'EXPECTED_NDCG' => $ndcg['tie'],
            ],
        ];
    }

    /** @param list<array<string,mixed>> $entries @return array{bikes:list<int>,probabilities:list<float>,second_third_score:float,winner_tie_count:int,second_third_tie_count:int} */
    private function winnerPreserving(int $raceId, array $entries): array
    {
        $maximumP1 = max(array_map(static fn (array $entry): float => (float) $entry['position_1_probability'], $entries));
        $winnerCandidates = array_values(array_filter(
            $entries,
            static fn (array $entry): bool => (float) $entry['position_1_probability'] === $maximumP1,
        ));
        usort($winnerCandidates, fn (array $left, array $right): int => $this->primaryTieKey('PRIMARY_WINNER_P1', $raceId, (string) $left['bike'])
            <=> $this->primaryTieKey('PRIMARY_WINNER_P1', $raceId, (string) $right['bike'])
        );
        $winner = $winnerCandidates[0];
        $winnerBike = (int) $winner['bike'];

        $bestPair = null;
        $bestScore = -INF;
        $bestKey = null;
        $pairTies = 0;
        foreach ($entries as $second) {
            if ((int) $second['bike'] === $winnerBike) {
                continue;
            }
            foreach ($entries as $third) {
                if ((int) $third['bike'] === $winnerBike || $third['bike'] === $second['bike']) {
                    continue;
                }
                $bikes = [(int) $second['bike'], (int) $third['bike']];
                $score = (float) $second['position_2_probability'] + (float) $third['position_3_probability'];
                $key = $this->primaryTieKey('PRIMARY_SECOND_THIRD', $raceId, $winnerBike.'-'.implode('-', $bikes));
                if ($score > $bestScore) {
                    $bestPair = [$second, $third];
                    $bestScore = $score;
                    $bestKey = $key;
                    $pairTies = 1;
                } elseif ($score === $bestScore) {
                    $pairTies++;
                    if ($bestKey === null || $key < $bestKey) {
                        $bestPair = [$second, $third];
                        $bestKey = $key;
                    }
                }
            }
        }
        if ($bestPair === null || ! is_finite($bestScore)) {
            throw new RuntimeException('BT-03E-05 second/third decoder had no valid ordered pair.');
        }

        return [
            'bikes' => [$winnerBike, (int) $bestPair[0]['bike'], (int) $bestPair[1]['bike']],
            'probabilities' => [$maximumP1, (float) $bestPair[0]['position_2_probability'], (float) $bestPair[1]['position_3_probability']],
            'second_third_score' => $bestScore,
            'winner_tie_count' => count($winnerCandidates),
            'second_third_tie_count' => $pairTies,
        ];
    }

    /** @param list<array<string,mixed>> $entries @return array{bikes:list<int>,scores:list<float>,tie:array{tie_count:int,technical_tiebreak_used:bool}} */
    private function rank(int $raceId, array $entries, string $decoder, string $field, int $take): array
    {
        $ranked = array_map(fn (array $entry): array => [
            'bike' => (int) $entry['bike'],
            'score' => (float) $entry[$field],
            'key' => $this->supportingTieKey($decoder, $raceId, (string) $entry['bike']),
        ], $entries);
        usort($ranked, static function (array $left, array $right): int {
            if ($left['score'] > $right['score']) {
                return -1;
            }
            if ($left['score'] < $right['score']) {
                return 1;
            }

            return $left['key'] <=> $right['key'];
        });
        $selected = array_slice($ranked, 0, $take);
        $cutoff = $selected[$take - 1]['score'];
        $tieCount = count(array_filter($ranked, static fn (array $entry): bool => $entry['score'] === $cutoff));

        return [
            'bikes' => array_column($selected, 'bike'),
            'scores' => array_column($selected, 'score'),
            'tie' => ['tie_count' => $tieCount, 'technical_tiebreak_used' => $tieCount > 1],
        ];
    }

    /** @param list<array<string,mixed>> $entries @return array{bikes:list<int>,scores:list<float>,tie:array{tie_count:int,technical_tiebreak_used:bool}} */
    private function rankExpectedNdcg(int $raceId, array $entries): array
    {
        foreach ($entries as &$entry) {
            $entry['expected_ndcg_gain'] = 7.0 * (float) $entry['position_1_probability']
                + 3.0 * (float) $entry['position_2_probability']
                + (float) $entry['position_3_probability'];
        }
        unset($entry);

        return $this->rank($raceId, $entries, 'EXPECTED_NDCG', 'expected_ndcg_gain', 3);
    }

    private function primaryTieKey(string $decoder, int $raceId, string $identity): string
    {
        return $this->tieKey(Bt03e05Contract::TIE_RULE_VERSION, $decoder, $raceId, $identity);
    }

    private function supportingTieKey(string $decoder, int $raceId, string $identity): string
    {
        return $this->tieKey(Bt03e05Contract::SUPPORTING_TIE_RULE_VERSION, $decoder, $raceId, $identity);
    }

    private function tieKey(string $version, string $decoder, int $raceId, string $identity): string
    {
        return hash('sha256', $version.'|'.$decoder.'|'.$raceId.'|'.$identity);
    }
}
