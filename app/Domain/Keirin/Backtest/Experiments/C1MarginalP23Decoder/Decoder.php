<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\C1MarginalP23Decoder;

use App\Domain\Keirin\Backtest\Calculators\Bt03e03CompensatedSum;
use App\Domain\Keirin\Backtest\Calculators\Bt03e05DecisionDecoder;
use App\Domain\Keirin\Backtest\Calculators\Bt03e06WinnerConditionedDecoder;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Backtest\Services\Bt03e06Contract;
use App\Domain\Keirin\Statistics\AgariC1Input\Contract as InputContract;
use RuntimeException;

final class Decoder
{
    public function __construct(private readonly Bt03e05DecisionDecoder $marginal, private readonly Bt03e06WinnerConditionedDecoder $conditioned) {}

    public function decode(array $input, array $saved): array
    {
        $original = $this->baseline($input, $saved);
        $race = $saved['probabilities'];
        $candidate = $this->marginal->decode($race);
        if ($candidate['primary_position_1_bike'] !== $original['primary_position_1_bike']) {
            throw new RuntimeException('P1 invariant violated.');
        }
        foreach (['map_ordered_top3', 'map_ordered_probability', 'map_top3_set', 'map_top3_set_probability',
            'top2_marginal_bikes', 'top3_marginal_bikes', 'expected_ndcg_top3'] as $key) {
            Files::same([$original[$key]], [$candidate[$key]], 'Supporting '.$key);
        }
        foreach (['MAP_ORDERED_TOP3', 'MAP_TOP3_SET', 'TOP2_MARGINAL', 'TOP3_MARGINAL', 'EXPECTED_NDCG'] as $key) {
            Files::same($original['decoder_tie_diagnostics'][$key], $candidate['decoder_tie_diagnostics'][$key], 'Supporting tie '.$key);
        }
        $entries = array_column($race['entries'], null, 'bike');
        $oldScore = (float) $entries[$original['primary_position_2_bike']]['position_2_probability']
            + (float) $entries[$original['primary_position_3_bike']]['position_3_probability'];
        $newScore = (float) $entries[$candidate['primary_position_2_bike']]['position_2_probability']
            + (float) $entries[$candidate['primary_position_3_bike']]['position_3_probability'];
        if ($newScore < $oldScore || $newScore !== $candidate['primary_second_third_objective_score']) {
            throw new RuntimeException('Marginal expected score invariant violated.');
        }

        return ['year' => $input['year'], 'race_id' => $input['race_id'],
            'cohort' => array_map(fn ($e) => [$e['id'], $e['bike']], $input['entries']),
            'probabilities_semantic_sha256' => hash('sha256', Files::canonical($race)),
            'baseline' => $original, 'candidate' => $candidate,
            'marginal_score_old' => $oldScore, 'marginal_score_new' => $newScore,
            'model_expected_gain' => $newScore - $oldScore];
    }

    public function baseline(array $input, array $saved): array
    {
        InputContract::keys($saved, ['probabilities', 'decision']);
        $race = $saved['probabilities'];
        $this->probabilities($input, $race);
        $original = $saved['decision'];
        $verified = $this->conditioned->decode($race);
        // These are source-run audit fields, not decoder mathematics.
        $verified['reconstruction_verified'] = false;
        $verified['prediction_origin'] = 'EXPERIMENTAL_REFIT';
        InputContract::keys($original, array_keys($verified));
        Files::same($verified, $original, 'saved E06 decision');

        return $original;
    }

    private function probabilities(array $input, array $race): void
    {
        $shared = ['map_ordered_top3', 'map_ordered_probability', 'map_top3_set', 'map_top3_set_probability', 'map_tie_diagnostics'];
        InputContract::keys($race, ['year', 'race_id', 'entries', ...$shared, 'probability_invariants']);
        if ([$race['year'], $race['race_id']] !== [$input['year'], $input['race_id']]
            || ! is_array($race['entries']) || ! array_is_list($race['entries']) || count($race['entries']) !== count($input['entries'])) {
            throw new RuntimeException('Prediction cohort mismatch.');
        }
        $sums = array_map(fn () => new Bt03e03CompensatedSum, range(1, 3));
        $positions = [];
        $bikes = array_column($input['entries'], 'bike');
        foreach ($race['entries'] as $i => $entry) {
            InputContract::keys($entry, ['id', 'bike', 'raw', 'stat01_rank', 'anchor',
                'position_1_probability', 'position_2_probability', 'position_3_probability',
                'position_1_log_probability', 'position_2_log_probability', 'position_3_log_probability',
                'top2_probability', 'top3_probability', 'predicted_position', 'is_map_top3', ...$shared, 'utilities']);
            foreach (['id', 'bike', 'raw', 'stat01_rank', 'anchor'] as $key) {
                Files::same([$input['entries'][$i][$key]], [$entry[$key]], 'fixed entry '.$key);
            }
            foreach ([1, 2, 3] as $position) {
                $p = $this->number($entry['position_'.$position.'_probability']);
                if ($p < 0.0 || $p > 1.0 || abs(exp($this->number($entry['position_'.$position.'_log_probability'])) - $p) > Bt03e06Contract::PROBABILITY_TOLERANCE) {
                    throw new RuntimeException('Invalid saved probability.');
                }
                $sums[$position - 1]->add($p);
            }
            $top2 = (float) $entry['position_1_probability'] + (float) $entry['position_2_probability'];
            $top3 = $top2 + (float) $entry['position_3_probability'];
            if ($this->number($entry['top2_probability']) !== $top2 || $this->number($entry['top3_probability']) !== $top3
                || ! is_int($entry['predicted_position']) || ! in_array($entry['predicted_position'], range(1, count($bikes)), true)
                || in_array($entry['predicted_position'], $positions, true)
                || $entry['is_map_top3'] !== in_array($entry['bike'], $race['map_ordered_top3'], true)) {
                throw new RuntimeException('Invalid saved marginal/ranking values.');
            }
            $positions[] = $entry['predicted_position'];
            foreach ($shared as $key) {
                Files::same([$race[$key]], [$entry[$key]], 'repeated probability metadata');
            }
            InputContract::keys($entry['utilities'], ['POSITION_1', 'POSITION_2', 'POSITION_3']);
            foreach ($entry['utilities'] as $value) {
                $this->number($value);
            }
        }
        foreach (['map_ordered_top3', 'map_top3_set'] as $key) {
            $values = $race[$key];
            if (! is_array($values) || ! array_is_list($values) || count($values) !== 3 || count(array_unique($values, SORT_REGULAR)) !== 3
                || array_filter($values, fn ($v) => ! in_array($v, $bikes, true)) !== []) {
                throw new RuntimeException('Invalid saved MAP bikes.');
            }
        }
        foreach (['map_ordered_probability', 'map_top3_set_probability'] as $key) {
            $p = $this->number($race[$key]);
            if ($p < 0.0 || $p > 1.0) {
                throw new RuntimeException('Invalid MAP probability.');
            }
        }
        InputContract::keys($race['map_tie_diagnostics'], ['ordered_probability_tied_race', 'ordered_probability_tied_combinations', 'technical_tiebreak_used']);
        $ties = $race['map_tie_diagnostics'];
        if (! in_array($ties['ordered_probability_tied_race'], [0, 1], true) || ! is_int($ties['ordered_probability_tied_combinations'])
            || $ties['ordered_probability_tied_combinations'] < 0 || ! is_bool($ties['technical_tiebreak_used'])) {
            throw new RuntimeException('Invalid MAP tie metadata.');
        }
        InputContract::keys($race['probability_invariants'], ['position_1_sum', 'position_2_sum', 'position_3_sum', 'ordered_joint_sum']);
        foreach ($race['probability_invariants'] as $key => $value) {
            if (abs($this->number($value) - 1.0) > Bt03e06Contract::PROBABILITY_TOLERANCE) {
                throw new RuntimeException('Saved probability distribution invariant failed.');
            }
        }
        foreach ($sums as $i => $sum) {
            if (abs($sum->value() - 1.0) > Bt03e06Contract::PROBABILITY_TOLERANCE
                || abs($sum->value() - $race['probability_invariants']['position_'.($i + 1).'_sum']) > Bt03e06Contract::PROBABILITY_TOLERANCE) {
                throw new RuntimeException('Saved probability distribution sum failed.');
            }
        }
    }

    private function number(mixed $value): float
    {
        if ((! is_int($value) && ! is_float($value)) || ! is_finite((float) $value)) {
            throw new RuntimeException('Non-finite or non-numeric saved probability value.');
        }

        return (float) $value;
    }
}
