<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\C1P23NondecreasingMarginal;

use App\Domain\Keirin\Backtest\Calculators\Bt03e05DecisionDecoder;
use App\Domain\Keirin\Backtest\Experiments\C1MarginalP23Decoder\Decoder as VerifiedDecoder;
use App\Domain\Keirin\Backtest\Experiments\C1MarginalP23Decoder\Reader;
use App\Domain\Keirin\Backtest\Experiments\C1P12FixedMarginalP3\Decoder as FixedP2Decoder;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use RuntimeException;

final class Decoder
{
    public function __construct(private readonly VerifiedDecoder $verified, private readonly FixedP2Decoder $fixedP2,
        private readonly Bt03e05DecisionDecoder $unconstrained) {}

    public function decode(array $input, array $saved): array
    {
        $old = $this->verified->baseline($input, $saved);
        [$a, $b, $c] = array_map(fn ($p) => $old['primary_position_'.$p.'_bike'], [1, 2, 3]);
        $entries = $saved['probabilities']['entries'];
        $policy = $this->select($input['year'], $input['race_id'], $entries, $a, $b, $c);
        $fixed = $this->fixedP2->select($input['year'], $input['race_id'], $entries, $a, $b, $c);
        $e05 = $this->unconstrained->decode($saved['probabilities']);
        $byBike = array_column($entries, null, 'bike');
        $fixedSum = $policy['original_p2'] + $fixed['selected_probability'];
        $unconstrainedSum = (float) $byBike[$e05['primary_position_2_bike']]['position_2_probability']
            + (float) $byBike[$e05['primary_position_3_bike']]['position_3_probability'];
        if ($e05['primary_position_1_bike'] !== $a || $policy['original_sum'] > $fixedSum
            || $fixedSum > $policy['selected_sum'] || $policy['selected_sum'] > $unconstrainedSum) {
            throw new RuntimeException('Outcome-free reference objective order failed.');
        }
        $policy['reference_sums'] = ['C1' => $policy['original_sum'], 'PR90' => $fixedSum,
            'candidate' => $policy['selected_sum'], 'E05' => $unconstrainedSum];
        $policy['same_as_PR90'] = [$policy['selected_p2_bike'], $policy['selected_p3_bike']] === [$b, $fixed['selected_bike']];
        $policy['same_as_E05'] = [$policy['selected_p2_bike'], $policy['selected_p3_bike']]
            === [$e05['primary_position_2_bike'], $e05['primary_position_3_bike']];
        $policy['baseline_origin'] = 'saved run-01 Outer C1 decision verified by unchanged E06';
        $policy['baseline_decision_sha256'] = hash('sha256', Files::canonical($old));
        $candidate = ['year' => $input['year'], 'race_id' => $input['race_id'], 'decoder_version' => Contract::DECODER,
            'primary_position_1_bike' => $a, 'primary_position_2_bike' => $policy['selected_p2_bike'],
            'primary_position_3_bike' => $policy['selected_p3_bike'], 'winner_tie_count' => $old['winner_tie_count'],
            'second_third_tie_count' => $policy['maximum_count'],
            'primary_decision_tied' => $old['winner_tie_count'] > 1 || $policy['maximum_count'] > 1,
            'primary_technical_tiebreak_used' => $old['winner_tie_count'] > 1 || $policy['hash_selection'], 'policy' => $policy];
        foreach (['map_ordered_top3', 'map_ordered_probability', 'map_top3_set', 'map_top3_set_probability',
            'top2_marginal_bikes', 'top3_marginal_bikes', 'expected_ndcg_top3'] as $key) {
            $candidate[$key] = $old[$key];
        }
        $candidate['decoder_tie_diagnostics'] = $old['decoder_tie_diagnostics'];
        $candidate['decoder_tie_diagnostics']['PRIMARY_SECOND_THIRD'] = ['tie_count' => $policy['maximum_count'],
            'technical_tiebreak_used' => $policy['hash_selection']];

        return ['year' => $input['year'], 'race_id' => $input['race_id'],
            'cohort' => array_map(fn ($e) => [$e['id'], $e['bike']], $input['entries']),
            'probabilities_semantic_sha256' => hash('sha256', Files::canonical($saved['probabilities'])),
            'baseline' => $old, 'candidate' => $candidate, 'model_expected_gain' => $policy['sum_delta']];
    }

    public function select(int $year, int $race, array $entries, int $a, int $b, int $c): array
    {
        Reader::year($year);
        $byBike = [];
        foreach ($entries as $e) {
            $bike = $e['bike'] ?? null;
            if (! is_int($bike) || $bike < 1 || $bike > 9 || isset($byBike[$bike])) {
                throw new RuntimeException('Invalid or duplicate bike.');
            }
            foreach (['position_2_probability', 'position_3_probability'] as $key) {
                $p = $e[$key] ?? null;
                if ((! is_float($p) && ! is_int($p)) || ! is_finite((float) $p) || $p < 0 || $p > 1) {
                    throw new RuntimeException('Invalid marginal probability.');
                }
            }
            $byBike[$bike] = [(float) $e['position_2_probability'], (float) $e['position_3_probability']];
        }
        if ($race < 1 || count($byBike) < 5 || count($byBike) > 9 || count(array_unique([$a, $b, $c])) !== 3
            || ! isset($byBike[$a], $byBike[$b], $byBike[$c])) {
            throw new RuntimeException('Invalid original pair/cohort.');
        }
        [$t2, $t3] = [$byBike[$b][0], $byBike[$c][1]];
        $originalSum = $t2 + $t3;
        $counts = ['all_pairs' => 0, 'feasible_pairs' => 0, 'excluded_p2_only' => 0, 'excluded_p3_only' => 0, 'excluded_both' => 0];
        $maximum = -INF;
        $choices = [];
        // Exhaust the small ordered pair universe; no greedy component optimization.
        foreach ($byBike as $second => $p) {
            foreach ($byBike as $third => $q) {
                if ($second === $a || $third === $a || $second === $third) {
                    continue;
                }
                $counts['all_pairs']++;
                $bad2 = $p[0] < $t2;
                $bad3 = $q[1] < $t3;
                if ($bad2 || $bad3) {
                    $counts[$bad2 && $bad3 ? 'excluded_both' : ($bad2 ? 'excluded_p2_only' : 'excluded_p3_only')]++;

                    continue;
                }
                $counts['feasible_pairs']++;
                $sum = $p[0] + $q[1];
                if ($sum > $maximum) {
                    $maximum = $sum;
                    $choices = [[$second, $third]];
                } elseif ($sum === $maximum) {
                    $choices[] = [$second, $third];
                }
            }
        }
        if ($choices === [] || $maximum < $originalSum) {
            throw new RuntimeException('Original pair missing from feasible universe.');
        }
        $retained = $maximum === $originalSum;
        $hash = ! $retained && count($choices) > 1;
        $keys = [];
        foreach ($choices as [$second, $third]) {
            $keys[] = ['b' => $second, 'c' => $third,
                'sha' => hash('sha256', Contract::TIE.'|'.$year.'|'.$race.'|'.$a.'|'.$b.'|'.$c.'|'.$second.'|'.$third)];
        }
        usort($keys, fn ($x, $y) => [$x['sha'], $x['b'], $x['c']] <=> [$y['sha'], $y['b'], $y['c']]);
        [$selectedB, $selectedC] = $retained ? [$b, $c] : [$keys[0]['b'], $keys[0]['c']];
        [$new2, $new3] = [$byBike[$selectedB][0], $byBike[$selectedC][1]];
        $changed = [$selectedB, $selectedC] !== [$b, $c];
        if ($new2 < $t2 || $new3 < $t3 || ($changed && $new2 + $new3 <= $originalSum)) {
            throw new RuntimeException('Component nondecrease/strict change invariant failed.');
        }

        return ['decoder_version' => Contract::DECODER, 'tie_version' => Contract::TIE,
            'original_p1_bike' => $a, 'original_p2_bike' => $b, 'original_p3_bike' => $c,
            'selected_p1_bike' => $a, 'selected_p2_bike' => $selectedB, 'selected_p3_bike' => $selectedC,
            'original_p2' => $t2, 'selected_p2' => $new2, 'original_p3' => $t3, 'selected_p3' => $new3,
            'p2_delta' => $new2 - $t2, 'p3_delta' => $new3 - $t3, 'original_sum' => $originalSum,
            'selected_sum' => $new2 + $new3, 'sum_delta' => $new2 + $new3 - $originalSum,
            ...$counts, 'maximum_count' => count($choices), 'original_pair_retained' => $retained,
            'equal_maximum_retained' => $retained && count($choices) > 1, 'changed' => $changed,
            'hash_selection' => $hash, 'selected_tie_sha256' => $hash ? $keys[0]['sha'] : null,
            'selected_tie_input' => $hash ? implode('|', [Contract::TIE, $year, $race, $a, $b, $c, $selectedB, $selectedC]) : null];
    }
}
