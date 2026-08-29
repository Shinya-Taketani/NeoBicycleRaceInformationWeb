<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Calculators;

use App\Domain\Keirin\Backtest\Services\Bt03e06Contract;
use App\Domain\Keirin\Backtest\Support\CanonicalHasher;
use RuntimeException;

final class Bt03e06WinnerConditionedDecoder
{
    public function __construct(
        private readonly Bt03e03ProbabilityScorer $scorer,
        private readonly CanonicalHasher $hasher,
    ) {}

    /** @param array<string,mixed> $race @return array<string,mixed> */
    public function decode(array $race): array
    {
        $entries = $race['entries'] ?? null;
        $raceId = $race['race_id'] ?? null;
        if (! is_array($entries) || count($entries) < 5 || count($entries) > 9
            || ! is_int($raceId) || $raceId < 1) {
            throw new RuntimeException('BT-03E-06 decoder race contract was invalid.');
        }
        $seenBikes = [];
        $winnerProbabilities = [];
        foreach ($entries as $entry) {
            $bike = $entry['bike'] ?? null;
            $p1 = $entry['position_1_probability'] ?? null;
            if (! is_int($bike) || $bike < 1 || $bike > 9 || isset($seenBikes[$bike])
                || (! is_int($p1) && ! is_float($p1)) || ! is_finite((float) $p1)
                || (float) $p1 < 0.0 || (float) $p1 > 1.0) {
                throw new RuntimeException('BT-03E-06 winner probabilities or bike identities were invalid.');
            }
            $seenBikes[$bike] = true;
            $winnerProbabilities[] = (float) $p1;
        }
        $maximumP1 = max($winnerProbabilities);
        $winnerCandidates = [];
        foreach ($entries as $offset => $entry) {
            if ((float) $entry['position_1_probability'] === $maximumP1) {
                $winnerCandidates[] = $offset;
            }
        }
        usort($winnerCandidates, fn (int $left, int $right): int => $this->primaryTieKey(
            'PRIMARY_WINNER_P1',
            $raceId,
            (string) $entries[$left]['bike'],
        ) <=> $this->primaryTieKey('PRIMARY_WINNER_P1', $raceId, (string) $entries[$right]['bike']));
        $winnerOffset = $winnerCandidates[0] ?? throw new RuntimeException('BT-03E-06 winner was unavailable.');
        $winnerBike = (int) $entries[$winnerOffset]['bike'];
        $conditionals = $this->conditionals($entries, $winnerOffset);

        $best = null;
        $bestScore = -INF;
        $bestKey = null;
        $pairTies = 0;
        foreach ($entries as $secondOffset => $second) {
            if ($secondOffset === $winnerOffset) {
                continue;
            }
            foreach ($entries as $thirdOffset => $third) {
                if ($thirdOffset === $winnerOffset || $thirdOffset === $secondOffset) {
                    continue;
                }
                $score = $conditionals['q2'][$secondOffset] + $conditionals['q3_marginal'][$thirdOffset];
                $identity = $winnerBike.'-'.$second['bike'].'-'.$third['bike'];
                $key = $this->primaryTieKey('PRIMARY_SECOND_THIRD', $raceId, $identity);
                if ($score > $bestScore) {
                    $best = [$secondOffset, $thirdOffset];
                    $bestScore = $score;
                    $bestKey = $key;
                    $pairTies = 1;
                } elseif ($score === $bestScore) {
                    $pairTies++;
                    if ($bestKey === null || $key < $bestKey) {
                        $best = [$secondOffset, $thirdOffset];
                        $bestKey = $key;
                    }
                }
            }
        }
        if ($best === null || ! is_finite($bestScore)) {
            throw new RuntimeException('BT-03E-06 second/third decision was unavailable.');
        }

        $top2 = $this->rank($raceId, $entries, 'TOP2_MARGINAL', 'top2_probability', 2);
        $top3 = $this->rank($raceId, $entries, 'TOP3_MARGINAL', 'top3_probability', 3);
        $ndcg = $this->rankExpectedNdcg($raceId, $entries);
        $q2Semantic = $this->distribution($entries, $conditionals['q2']);
        $q3Semantic = $this->distribution($entries, $conditionals['q3_marginal']);

        return [
            'year' => $race['year'],
            'race_id' => $raceId,
            'primary_position_1_bike' => $winnerBike,
            'primary_position_2_bike' => (int) $entries[$best[0]]['bike'],
            'primary_position_3_bike' => (int) $entries[$best[1]]['bike'],
            'winner_p1' => $maximumP1,
            'selected_q2_given_winner' => $conditionals['q2'][$best[0]],
            'selected_q3_given_winner' => $conditionals['q3_marginal'][$best[1]],
            'primary_second_third_objective_score' => $bestScore,
            'q2_given_winner' => $q2Semantic,
            'q3_given_winner' => $q3Semantic,
            'q2_distribution_semantic_sha256' => $this->hasher->hash($q2Semantic),
            'q3_winner_conditioned_marginal_semantic_sha256' => $this->hasher->hash($q3Semantic),
            'map_ordered_top3' => $race['map_ordered_top3'],
            'map_ordered_probability' => $race['map_ordered_probability'],
            'map_top3_set' => $race['map_top3_set'],
            'map_top3_set_probability' => $race['map_top3_set_probability'],
            'top2_marginal_bikes' => $top2['bikes'],
            'top3_marginal_bikes' => $top3['bikes'],
            'expected_ndcg_top3' => $ndcg['bikes'],
            'winner_tie_count' => count($winnerCandidates),
            'second_third_tie_count' => $pairTies,
            'primary_decision_tied' => count($winnerCandidates) > 1 || $pairTies > 1,
            'primary_technical_tiebreak_used' => count($winnerCandidates) > 1 || $pairTies > 1,
            'reconstruction_verified' => true,
            'decoder_tie_diagnostics' => [
                'PRIMARY_WINNER_P1' => ['tie_count' => count($winnerCandidates), 'technical_tiebreak_used' => count($winnerCandidates) > 1],
                'PRIMARY_SECOND_THIRD' => ['tie_count' => $pairTies, 'technical_tiebreak_used' => $pairTies > 1],
                'MAP_ORDERED_TOP3' => ['tie_count' => null, 'technical_tiebreak_used' => null, 'source_diagnostic_unavailable' => true],
                'MAP_TOP3_SET' => ['tie_count' => null, 'technical_tiebreak_used' => null, 'source_diagnostic_unavailable' => true],
                'TOP2_MARGINAL' => $top2['tie'],
                'TOP3_MARGINAL' => $top3['tie'],
                'EXPECTED_NDCG' => $ndcg['tie'],
            ],
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $entries
     * @return array{q2:list<float>,q3_paths:array<int,list<float>>,q3_marginal:list<float>}
     */
    public function conditionals(array $entries, int $winnerOffset): array
    {
        $count = count($entries);
        if ($count < 5 || $count > 9 || ! isset($entries[$winnerOffset])) {
            throw new RuntimeException('BT-03E-06 conditional input was invalid.');
        }
        $u2 = $u3 = [];
        foreach ($entries as $entry) {
            $utilities = $entry['utilities'] ?? null;
            if (! is_array($utilities)) {
                throw new RuntimeException('BT-03E-06 reconstructed utilities were unavailable.');
            }
            $u2[] = $this->finite($utilities['POSITION_2'] ?? null, 'U2');
            $u3[] = $this->finite($utilities['POSITION_3'] ?? null, 'U3');
        }
        $q2 = array_map('exp', $this->scorer->conditionalLogProbabilities($u2, [$winnerOffset]));
        $sums = [];
        foreach (range(0, $count - 1) as $offset) {
            $sums[$offset] = new Bt03e03CompensatedSum;
        }
        $paths = [];
        foreach (range(0, $count - 1) as $second) {
            if ($second === $winnerOffset) {
                continue;
            }
            $paths[$second] = array_map('exp', $this->scorer->conditionalLogProbabilities($u3, [$winnerOffset, $second]));
            foreach ($paths[$second] as $third => $probability) {
                $sums[$third]->add($q2[$second] * $probability);
            }
        }
        $q3 = array_map(static fn (Bt03e03CompensatedSum $sum): float => $sum->value(), $sums);
        $this->assertDistribution($q2, [$winnerOffset], 'Q2');
        foreach ($paths as $second => $path) {
            $this->assertDistribution($path, [$winnerOffset, $second], 'Q3 path');
        }
        $this->assertDistribution($q3, [$winnerOffset], 'Q3 marginal');

        return ['q2' => $q2, 'q3_paths' => $paths, 'q3_marginal' => $q3];
    }

    /** @param list<array<string,mixed>> $entries @param list<float> $values @return list<array{bike:int,probability:float}> */
    private function distribution(array $entries, array $values): array
    {
        return array_map(static fn (array $entry, float $probability): array => [
            'bike' => (int) $entry['bike'],
            'probability' => $probability,
        ], $entries, $values);
    }

    /** @param list<float> $values @param list<int> $excluded */
    private function assertDistribution(array $values, array $excluded, string $name): void
    {
        $sum = new Bt03e03CompensatedSum;
        foreach ($values as $offset => $value) {
            if (! is_finite($value) || $value < 0.0 || $value > 1.0
                || (in_array($offset, $excluded, true) && $value !== 0.0)) {
                throw new RuntimeException("BT-03E-06 {$name} probability invariant failed.");
            }
            $sum->add($value);
        }
        if (abs($sum->value() - 1.0) > Bt03e06Contract::PROBABILITY_TOLERANCE) {
            throw new RuntimeException("BT-03E-06 {$name} sum invariant failed.");
        }
    }

    /** @param list<array<string,mixed>> $entries @return array{bikes:list<int>,tie:array{tie_count:int,technical_tiebreak_used:bool}} */
    private function rank(int $raceId, array $entries, string $decoder, string $field, int $take): array
    {
        $ranked = array_map(fn (array $entry): array => [
            'bike' => (int) $entry['bike'],
            'score' => $this->finite($entry[$field] ?? null, $field),
            'key' => $this->supportingTieKey($decoder, $raceId, (string) $entry['bike']),
        ], $entries);
        usort($ranked, static fn (array $left, array $right): int => $left['score'] === $right['score']
            ? $left['key'] <=> $right['key']
            : ($left['score'] > $right['score'] ? -1 : 1));
        $selected = array_slice($ranked, 0, $take);
        $cutoff = $selected[$take - 1]['score'];
        $tieCount = count(array_filter($ranked, static fn (array $entry): bool => $entry['score'] === $cutoff));

        return [
            'bikes' => array_column($selected, 'bike'),
            'tie' => ['tie_count' => $tieCount, 'technical_tiebreak_used' => $tieCount > 1],
        ];
    }

    /** @param list<array<string,mixed>> $entries @return array{bikes:list<int>,tie:array{tie_count:int,technical_tiebreak_used:bool}} */
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
        return hash('sha256', Bt03e06Contract::PRIMARY_TIE_RULE_VERSION.'|'.$decoder.'|'.$raceId.'|'.$identity);
    }

    private function supportingTieKey(string $decoder, int $raceId, string $identity): string
    {
        return hash('sha256', Bt03e06Contract::SUPPORTING_TIE_RULE_VERSION.'|'.$decoder.'|'.$raceId.'|'.$identity);
    }

    private function finite(mixed $value, string $field): float
    {
        if ((! is_float($value) && ! is_int($value)) || ! is_finite((float) $value)) {
            throw new RuntimeException("BT-03E-06 {$field} was invalid.");
        }

        return (float) $value;
    }
}
