<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\BFixedWinnerJointP23;

use App\Domain\Keirin\Backtest\Calculators\Bt03e06WinnerConditionedDecoder;
use App\Domain\Keirin\Backtest\Experiments\C1Stat35P1Composition\Contract as ParentContract;
use App\Domain\Keirin\Backtest\Experiments\C1Stat35P1Composition\Reader as ParentReader;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Statistics\AgariC1Input\Validator;
use RuntimeException;

final class Decoder
{
    public function __construct(private readonly Bt03e06WinnerConditionedDecoder $conditioned, private readonly ParentReader $parent) {}

    public function decode(array $input, array $saved): array
    {
        Reader::year($input['year'] ?? 0);
        Validator::race($input, $input['year']);
        $race = $saved['probabilities'];
        Files::same([$input['year'], $input['race_id'], array_map(fn ($e) => [$e['id'], $e['bike']], $input['entries'])],
            [$race['year'], $race['race_id'], array_map(fn ($e) => [$e['id'], $e['bike']], $race['entries'])], 'ordered B identity');
        foreach ($race['entries'] as $i => $entry) {
            foreach (['id', 'bike', 'raw', 'stat01_rank', 'anchor'] as $key) {
                Files::same([$input['entries'][$i][$key]], [$entry[$key]], 'B fixed '.$key);
            }
        }
        // Forward is a validation control only; predictions/utility/model are never rewritten.
        Files::same($race, $this->parent->forward($race), 'B saved probability/utility control');
        $verified = $this->conditioned->decode($race);
        unset($verified['reconstruction_verified']);
        $verified['prediction_origin'] = 'FROZEN_POSITION_MODEL_COMPOSITION';
        $verified['utility_forward_controls_verified'] = true;
        $verified['calculation_version'] = ParentContract::CALCULATION;
        Files::same($verified, $saved['candidate'], 'B saved E06 decision');

        return $this->select($race, $verified);
    }

    public function select(array $race, array $baseline): array
    {
        Reader::year($race['year'] ?? 0);
        if (! is_int($race['race_id'] ?? null) || $race['race_id'] < 1
            || [$race['year'], $race['race_id']] !== [$baseline['year'] ?? null, $baseline['race_id'] ?? null]) {
            throw new RuntimeException('Invalid joint race identity.');
        }
        $entries = $race['entries'];
        if (! is_array($entries) || ! array_is_list($entries) || count($entries) < 5 || count($entries) > 9) {
            throw new RuntimeException('Invalid joint population.');
        }
        $ids = $bikes = [];
        foreach ($entries as $entry) {
            if (! is_int($entry['id'] ?? null) || $entry['id'] < 1 || in_array($entry['id'], $ids, true)
                || ! is_int($entry['bike'] ?? null) || $entry['bike'] < 1 || $entry['bike'] > 9 || in_array($entry['bike'], $bikes, true)) {
                throw new RuntimeException('Invalid or duplicate joint entrant.');
            }
            $ids[] = $entry['id'];
            $bikes[] = $entry['bike'];
        }
        $old = array_map(fn ($p) => $baseline['primary_position_'.$p.'_bike'], [1, 2, 3]);
        if (count(array_unique($old, SORT_REGULAR)) !== 3 || array_filter($old, fn ($v) => ! in_array($v, $bikes, true)) !== []) {
            throw new RuntimeException('Invalid B ordered selection.');
        }
        $winner = array_search($old[0], $bikes, true);
        $q = $this->conditioned->conditionals($entries, $winner);
        $oldScore = $q['q2'][array_search($old[1], $bikes, true)] * $q['q3_paths'][array_search($old[1], $bikes, true)][array_search($old[2], $bikes, true)];
        $best = -1.0;
        $pairs = [];
        $zeroScores = 0;
        foreach ($entries as $b => $entry) {
            if ($b === $winner) {
                continue;
            }
            foreach ($entries as $c => $third) {
                if ($c === $winner || $c === $b) {
                    continue;
                }
                $score = $q['q2'][$b] * $q['q3_paths'][$b][$c];
                if (! is_finite($score) || $score < 0.0 || $score > 1.0) {
                    throw new RuntimeException('Invalid joint probability.');
                }
                $zeroScores += (int) ($score === 0.0);
                if ($score > $best) {
                    $best = $score;
                    $pairs = [[$entry['bike'], $third['bike']]];
                } elseif ($score === $best) {
                    $pairs[] = [$entry['bike'], $third['bike']];
                }
            }
        }
        if ($pairs === [] || $best < $oldScore) {
            throw new RuntimeException('Joint maximum invariant failed.');
        }
        $retained = $oldScore === $best;
        $hashUsed = ! $retained && count($pairs) > 1;
        if ($retained) {
            $pair = array_slice($old, 1);
        } else {
            usort($pairs, fn ($left, $right) => [$this->tieKey($race['year'], $race['race_id'], $old[0], $left), ...$left]
                <=> [$this->tieKey($race['year'], $race['race_id'], $old[0], $right), ...$right]);
            $pair = $pairs[0];
        }
        $decision = $baseline;
        unset($decision['selected_q2_given_winner'], $decision['selected_q3_given_winner'], $decision['primary_second_third_objective_score'],
            $decision['utility_forward_controls_verified'], $decision['calculation_version']);
        $decision['primary_position_2_bike'] = $pair[0];
        $decision['primary_position_3_bike'] = $pair[1];
        $decision['second_third_tie_count'] = count($pairs);
        $decision['primary_decision_tied'] = $baseline['winner_tie_count'] > 1 || count($pairs) > 1;
        $decision['primary_technical_tiebreak_used'] = $baseline['winner_tie_count'] > 1 || $hashUsed;
        $decision['prediction_origin'] = Contract::VERSION;
        $decision['decoder_tie_diagnostics']['PRIMARY_SECOND_THIRD'] = ['exact_maximum_pairs' => count($pairs), 'B_retained' => $retained, 'sha_tiebreak_used' => $hashUsed];
        $decision['joint_score'] = $best;
        $decision['B_joint_score'] = $oldScore;
        $decision['joint_zero_score_pairs'] = $zeroScores;
        $decision['decoder_version'] = Contract::VERSION;

        return $decision;
    }

    public function tieKey(int $year, int $race, int $winner, array $pair): string
    {
        return hash('sha256', Contract::TIE.'|'.$year.'|'.$race.'|'.$winner.'|'.implode('|', $pair));
    }
}
