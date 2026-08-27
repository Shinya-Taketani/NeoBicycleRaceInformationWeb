<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Calculators;

use App\Domain\Keirin\Backtest\Services\Bt03e04Contract;
use RuntimeException;

final class Bt03e04DecisionDecoder
{
    /** @param array<string,mixed> $race @return array<string,mixed> */
    public function decode(array $race): array
    {
        $entries = $race['entries'] ?? null;
        if (! is_array($entries) || count($entries) < 5 || count($entries) > 9) {
            throw new RuntimeException('BT-03E-04 decoder entrant count was invalid.');
        }
        $raceId = $race['race_id'] ?? null;
        if (! is_int($raceId) || $raceId < 1) {
            throw new RuntimeException('BT-03E-04 decoder race identity was invalid.');
        }

        $primary = $this->coherent($raceId, $entries);
        $argmax = $this->rank($raceId, $entries, 'ARGMAX_P1', 'position_1_probability', 1);
        $top2 = $this->rank($raceId, $entries, 'TOP2_MARGINAL', 'top2_probability', 2);
        $top3 = $this->rank($raceId, $entries, 'TOP3_MARGINAL', 'top3_probability', 3);
        $ndcg = $this->rankExpectedNdcg($raceId, $entries);

        return [
            'year' => $race['year'],
            'race_id' => $raceId,
            'primary_position_1_bike' => $primary['bikes'][0],
            'primary_position_2_bike' => $primary['bikes'][1],
            'primary_position_3_bike' => $primary['bikes'][2],
            'primary_objective_score' => $primary['score'],
            'argmax_p1_bike' => $argmax['bikes'][0],
            'argmax_p1_probability' => $argmax['scores'][0],
            'map_ordered_top3' => $race['map_ordered_top3'],
            'map_ordered_probability' => $race['map_ordered_probability'],
            'map_top3_set' => $race['map_top3_set'],
            'map_top3_set_probability' => $race['map_top3_set_probability'],
            'top2_marginal_bikes' => $top2['bikes'],
            'top3_marginal_bikes' => $top3['bikes'],
            'expected_ndcg_top3' => $ndcg['bikes'],
            'primary_tie_count' => $primary['tie_count'],
            'primary_technical_tiebreak_used' => $primary['tie_count'] > 1,
            'decoder_tie_diagnostics' => [
                'PRIMARY_COHERENT_POSITION' => ['tie_count' => $primary['tie_count'], 'technical_tiebreak_used' => $primary['tie_count'] > 1],
                'ARGMAX_P1' => $argmax['tie'],
                'MAP_ORDERED_TOP3' => ['tie_count' => null, 'technical_tiebreak_used' => null, 'source_diagnostic_unavailable' => true],
                'MAP_TOP3_SET' => ['tie_count' => null, 'technical_tiebreak_used' => null, 'source_diagnostic_unavailable' => true],
                'TOP2_MARGINAL' => $top2['tie'],
                'TOP3_MARGINAL' => $top3['tie'],
                'EXPECTED_NDCG' => $ndcg['tie'],
            ],
        ];
    }

    /** @param list<array<string,mixed>> $entries @return array{bikes:list<int>,score:float,tie_count:int} */
    private function coherent(int $raceId, array $entries): array
    {
        $best = null;
        $bestScore = -INF;
        $bestKey = null;
        $ties = 0;
        foreach ($entries as $first) {
            foreach ($entries as $second) {
                if ($first['bike'] === $second['bike']) {
                    continue;
                }
                foreach ($entries as $third) {
                    if ($third['bike'] === $first['bike'] || $third['bike'] === $second['bike']) {
                        continue;
                    }
                    $bikes = [(int) $first['bike'], (int) $second['bike'], (int) $third['bike']];
                    $score = (float) $first['position_1_probability']
                        + (float) $second['position_2_probability']
                        + (float) $third['position_3_probability'];
                    $key = $this->tieKey('PRIMARY_COHERENT_POSITION', $raceId, implode('-', $bikes));
                    if ($score > $bestScore) {
                        $best = $bikes;
                        $bestScore = $score;
                        $bestKey = $key;
                        $ties = 1;
                    } elseif ($score === $bestScore) {
                        $ties++;
                        if ($bestKey === null || $key < $bestKey) {
                            $best = $bikes;
                            $bestKey = $key;
                        }
                    }
                }
            }
        }
        if ($best === null || ! is_finite($bestScore)) {
            throw new RuntimeException('BT-03E-04 coherent decoder had no valid ordered triple.');
        }

        return ['bikes' => $best, 'score' => $bestScore, 'tie_count' => $ties];
    }

    /** @param list<array<string,mixed>> $entries @return array{bikes:list<int>,scores:list<float>,tie:array{tie_count:int,technical_tiebreak_used:bool}} */
    private function rank(int $raceId, array $entries, string $decoder, string $field, int $take): array
    {
        $ranked = array_map(fn (array $entry): array => [
            'bike' => (int) $entry['bike'],
            'score' => (float) $entry[$field],
            'key' => $this->tieKey($decoder, $raceId, (string) $entry['bike']),
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

    private function tieKey(string $decoder, int $raceId, string $identity): string
    {
        return hash('sha256', Bt03e04Contract::TIE_RULE_VERSION.'|'.$decoder.'|'.$raceId.'|'.$identity);
    }
}
