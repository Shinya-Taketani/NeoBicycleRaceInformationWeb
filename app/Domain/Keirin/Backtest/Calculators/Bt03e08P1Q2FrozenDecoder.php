<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Calculators;

use App\Domain\Keirin\Backtest\Services\Bt03e08Contract;
use App\Domain\Keirin\Backtest\Support\CanonicalHasher;
use RuntimeException;

final class Bt03e08P1Q2FrozenDecoder
{
    public function __construct(private readonly Bt03e06WinnerConditionedDecoder $sourceDecoder, private readonly CanonicalHasher $hasher) {}

    /** @param array<string,mixed> $source @param array<string,mixed> $p3 @return array<string,mixed> */
    public function decode(array $source, array $p3): array
    {
        if (($source['year'] ?? null) !== ($p3['year'] ?? null) || ($source['race_id'] ?? null) !== ($p3['race_id'] ?? null)
            || ! is_array($source['entries'] ?? null) || ! is_array($p3['entries'] ?? null)) {
            throw new RuntimeException('BT-03E-08 source and P3 prediction identities differed.');
        }
        $frozen = $this->sourceDecoder->decode($source);
        $winnerBike = $frozen['primary_position_1_bike'];
        if (($p3['winner_bike'] ?? null) !== $winnerBike) {
            throw new RuntimeException('BT-03E-08 winner differed from frozen E06 P1.');
        }
        $q2 = [];
        foreach ($frozen['q2_given_winner'] as $entry) {
            $q2[(int) $entry['bike']] = (float) $entry['probability'];
        }
        $r3 = [];
        foreach ($p3['entries'] as $entry) {
            $bike = (int) ($entry['bike'] ?? 0);
            if (isset($r3[$bike]) || ! array_key_exists($bike, $q2)) {
                throw new RuntimeException('BT-03E-08 P3 entrant set differed from frozen Q2.');
            }
            $r3[$bike] = (float) ($entry['r3_probability'] ?? NAN);
        }
        if (array_keys($q2) !== array_keys($r3) || $q2[$winnerBike] !== 0.0 || $r3[$winnerBike] !== 0.0
            || abs(array_sum($q2) - 1.0) > Bt03e08Contract::PROBABILITY_TOLERANCE
            || abs(array_sum($r3) - 1.0) > Bt03e08Contract::PROBABILITY_TOLERANCE) {
            throw new RuntimeException('BT-03E-08 frozen Q2 or direct R3 invariant failed.');
        }
        $raceId = (int) $source['race_id'];
        $best = null;
        $bestScore = -INF;
        $bestKey = null;
        $ties = 0;
        foreach ($q2 as $second => $q2Probability) {
            if ($second === $winnerBike) {
                continue;
            }
            foreach ($r3 as $third => $r3Probability) {
                if ($third === $winnerBike || $third === $second) {
                    continue;
                }
                $score = $q2Probability + $r3Probability;
                $key = hash('sha256', Bt03e08Contract::PRIMARY_TIE_RULE_VERSION.'|PRIMARY_SECOND_THIRD|'.$raceId.'|'.$winnerBike.'-'.$second.'-'.$third);
                if ($score > $bestScore) {
                    $best = [$second, $third];
                    $bestScore = $score;
                    $bestKey = $key;
                    $ties = 1;
                } elseif ($score === $bestScore) {
                    $ties++;
                    if ($bestKey === null || $key < $bestKey) {
                        $best = [$second, $third];
                        $bestKey = $key;
                    }
                }
            }
        }
        if ($best === null || ! is_finite($bestScore)) {
            throw new RuntimeException('BT-03E-08 second/third pair was unavailable.');
        }
        $q2Semantic = array_map(static fn (int $bike, float $probability): array => ['bike' => $bike, 'probability' => $probability], array_keys($q2), array_values($q2));
        $r3Semantic = array_map(static fn (int $bike, float $probability): array => ['bike' => $bike, 'probability' => $probability], array_keys($r3), array_values($r3));

        return [
            'year' => $source['year'], 'race_id' => $raceId,
            'primary_position_1_bike' => $winnerBike, 'primary_position_2_bike' => $best[0], 'primary_position_3_bike' => $best[1],
            'source_p1' => $frozen['winner_p1'], 'selected_q2' => $q2[$best[0]], 'selected_r3' => $r3[$best[1]],
            'primary_second_third_objective_score' => $bestScore,
            'q2_distribution_sha256' => $this->hasher->hash($q2Semantic), 'r3_distribution_sha256' => $this->hasher->hash($r3Semantic),
            'map_ordered_top3' => $frozen['map_ordered_top3'], 'map_ordered_probability' => $frozen['map_ordered_probability'],
            'map_top3_set' => $frozen['map_top3_set'], 'map_top3_set_probability' => $frozen['map_top3_set_probability'],
            'top2_marginal_bikes' => $frozen['top2_marginal_bikes'], 'top3_marginal_bikes' => $frozen['top3_marginal_bikes'],
            'expected_ndcg_top3' => $frozen['expected_ndcg_top3'], 'winner_tie_count' => $frozen['winner_tie_count'],
            'second_third_tie_count' => $ties,
            'primary_decision_tied' => $frozen['winner_tie_count'] > 1 || $ties > 1,
            'primary_technical_tiebreak_used' => $frozen['winner_tie_count'] > 1 || $ties > 1,
            'decoder_tie_diagnostics' => [
                ...$frozen['decoder_tie_diagnostics'],
                'PRIMARY_SECOND_THIRD' => ['tie_count' => $ties, 'technical_tiebreak_used' => $ties > 1],
            ],
            'p1_freeze_verified' => true, 'q2_freeze_verified' => true,
        ];
    }
}
