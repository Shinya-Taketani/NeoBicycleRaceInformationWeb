<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Calculators;

use App\Domain\Keirin\Backtest\Services\Bt03e07Contract;
use RuntimeException;

final class Bt03e07P1FrozenDecisionDecoder
{
    /** @param array<string,mixed> $source @param array<string,mixed> $direct @return array<string,mixed> */
    public function decode(array $source, array $direct): array
    {
        if (($source['year'] ?? null) !== ($direct['year'] ?? null)
            || ($source['race_id'] ?? null) !== ($direct['race_id'] ?? null)
            || ! is_array($source['entries'] ?? null) || ! is_array($direct['entries'] ?? null)) {
            throw new RuntimeException('BT-03E-07 source and direct prediction identities differed.');
        }
        $raceId = (int) $source['race_id'];
        $directByBike = [];
        foreach ($direct['entries'] as $entry) {
            $directByBike[(int) $entry['bike']] = $entry;
        }
        $entries = [];
        foreach ($source['entries'] as $entry) {
            $bike = (int) $entry['bike'];
            if (! isset($directByBike[$bike])) {
                throw new RuntimeException('BT-03E-07 direct prediction entrant set differed from source E03.');
            }
            $entries[] = [...$entry, ...$directByBike[$bike]];
            unset($directByBike[$bike]);
        }
        if ($directByBike !== []) {
            throw new RuntimeException('BT-03E-07 direct prediction contained an extra entrant.');
        }

        $maximumP1 = max(array_column($entries, 'position_1_probability'));
        $winnerCandidates = array_values(array_filter($entries, static fn (array $entry): bool => $entry['position_1_probability'] === $maximumP1));
        usort($winnerCandidates, fn (array $left, array $right): int => $this->primaryTieKey('PRIMARY_WINNER_P1', $raceId, (string) $left['bike']) <=> $this->primaryTieKey('PRIMARY_WINNER_P1', $raceId, (string) $right['bike']));
        $winner = $winnerCandidates[0];
        $winnerBike = (int) $winner['bike'];

        $bestPair = null;
        $bestScore = -INF;
        $bestKey = null;
        $pairTieCount = 0;
        foreach ($entries as $second) {
            if ((int) $second['bike'] === $winnerBike) {
                continue;
            }
            foreach ($entries as $third) {
                if ((int) $third['bike'] === $winnerBike || $third['bike'] === $second['bike']) {
                    continue;
                }
                $score = (float) $second['direct_position_2_probability'] + (float) $third['direct_position_3_probability'];
                $key = $this->primaryTieKey('PRIMARY_SECOND_THIRD', $raceId, $winnerBike.'-'.$second['bike'].'-'.$third['bike']);
                if ($score > $bestScore) {
                    $bestPair = [$second, $third];
                    $bestScore = $score;
                    $bestKey = $key;
                    $pairTieCount = 1;
                } elseif ($score === $bestScore) {
                    $pairTieCount++;
                    if ($bestKey === null || $key < $bestKey) {
                        $bestPair = [$second, $third];
                        $bestKey = $key;
                    }
                }
            }
        }
        if ($bestPair === null || ! is_finite($bestScore)) {
            throw new RuntimeException('BT-03E-07 direct P2/P3 pair was unavailable.');
        }
        $top2 = $this->rank($raceId, $entries, 'TOP2_MARGINAL', 'top2_probability', 2);
        $top3 = $this->rank($raceId, $entries, 'TOP3_MARGINAL', 'top3_probability', 3);
        foreach ($entries as &$entry) {
            $entry['expected_ndcg_gain'] = 7.0 * $entry['position_1_probability'] + 3.0 * $entry['position_2_probability'] + $entry['position_3_probability'];
        }
        unset($entry);
        $ndcg = $this->rank($raceId, $entries, 'EXPECTED_NDCG', 'expected_ndcg_gain', 3);

        return [
            'year' => $source['year'],
            'race_id' => $raceId,
            'primary_position_1_bike' => $winnerBike,
            'primary_position_2_bike' => (int) $bestPair[0]['bike'],
            'primary_position_3_bike' => (int) $bestPair[1]['bike'],
            'source_p1' => (float) $winner['position_1_probability'],
            'selected_d2' => (float) $bestPair[0]['direct_position_2_probability'],
            'selected_d3' => (float) $bestPair[1]['direct_position_3_probability'],
            'primary_second_third_objective_score' => $bestScore,
            'direct_p2_distribution_sha256' => $direct['direct_p2_distribution_sha256'],
            'direct_p3_distribution_sha256' => $direct['direct_p3_distribution_sha256'],
            'map_ordered_top3' => $source['map_ordered_top3'],
            'map_ordered_probability' => $source['map_ordered_probability'],
            'map_top3_set' => $source['map_top3_set'],
            'map_top3_set_probability' => $source['map_top3_set_probability'],
            'top2_marginal_bikes' => $top2['bikes'],
            'top3_marginal_bikes' => $top3['bikes'],
            'expected_ndcg_top3' => $ndcg['bikes'],
            'winner_tie_count' => count($winnerCandidates),
            'second_third_tie_count' => $pairTieCount,
            'primary_decision_tied' => count($winnerCandidates) > 1 || $pairTieCount > 1,
            'primary_technical_tiebreak_used' => count($winnerCandidates) > 1 || $pairTieCount > 1,
            'decoder_tie_diagnostics' => [
                'PRIMARY_WINNER_P1' => ['tie_count' => count($winnerCandidates), 'technical_tiebreak_used' => count($winnerCandidates) > 1],
                'PRIMARY_SECOND_THIRD' => ['tie_count' => $pairTieCount, 'technical_tiebreak_used' => $pairTieCount > 1],
                'MAP_ORDERED_TOP3' => ['tie_count' => null, 'technical_tiebreak_used' => null, 'source_diagnostic_unavailable' => true],
                'MAP_TOP3_SET' => ['tie_count' => null, 'technical_tiebreak_used' => null, 'source_diagnostic_unavailable' => true],
                'TOP2_MARGINAL' => $top2['tie'],
                'TOP3_MARGINAL' => $top3['tie'],
                'EXPECTED_NDCG' => $ndcg['tie'],
            ],
            'p1_freeze_verified' => true,
        ];
    }

    /** @param list<array<string,mixed>> $entries @return array{bikes:list<int>,tie:array{tie_count:int,technical_tiebreak_used:bool}} */
    private function rank(int $raceId, array $entries, string $decoder, string $field, int $take): array
    {
        $ranked = array_map(fn (array $entry): array => ['bike' => (int) $entry['bike'], 'score' => (float) $entry[$field], 'key' => $this->supportingTieKey($decoder, $raceId, (string) $entry['bike'])], $entries);
        usort($ranked, static fn (array $left, array $right): int => $left['score'] === $right['score'] ? $left['key'] <=> $right['key'] : ($left['score'] > $right['score'] ? -1 : 1));
        $selected = array_slice($ranked, 0, $take);
        $cutoff = $selected[$take - 1]['score'];
        $tieCount = count(array_filter($ranked, static fn (array $entry): bool => $entry['score'] === $cutoff));

        return ['bikes' => array_column($selected, 'bike'), 'tie' => ['tie_count' => $tieCount, 'technical_tiebreak_used' => $tieCount > 1]];
    }

    private function primaryTieKey(string $decoder, int $raceId, string $identity): string
    {
        return hash('sha256', Bt03e07Contract::PRIMARY_TIE_RULE_VERSION.'|'.$decoder.'|'.$raceId.'|'.$identity);
    }

    private function supportingTieKey(string $decoder, int $raceId, string $identity): string
    {
        return hash('sha256', Bt03e07Contract::SUPPORTING_TIE_RULE_VERSION.'|'.$decoder.'|'.$raceId.'|'.$identity);
    }
}
