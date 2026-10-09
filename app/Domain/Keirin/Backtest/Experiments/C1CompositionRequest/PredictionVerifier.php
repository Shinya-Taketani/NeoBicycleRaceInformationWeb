<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\C1CompositionRequest;

use App\Domain\Keirin\Backtest\Calculators\Bt03e03CompensatedSum;
use App\Domain\Keirin\Backtest\Calculators\Bt03e06WinnerConditionedDecoder;
use App\Domain\Keirin\Backtest\Experiments\C1Stat35P1Composition\ProbabilityCalculator;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Backtest\Services\Bt03e03Contract;
use RuntimeException;

final class PredictionVerifier
{
    public function __construct(private readonly ProbabilityCalculator $calculator, private readonly Bt03e06WinnerConditionedDecoder $decoder) {}

    public function verify(array $prediction): void
    {
        try {
            $this->keys($prediction, ['probabilities', 'decision']);
            $probabilities = $prediction['probabilities'];
            $this->keys($probabilities, ['year', 'race_id', 'entries', 'map_ordered_top3', 'map_ordered_probability',
                'map_top3_set', 'map_top3_set_probability', 'map_tie_diagnostics', 'probability_invariants']);
            if (! is_array($prediction['decision']) || array_is_list($prediction['decision'])
                || ! is_int($probabilities['year']) || ! in_array($probabilities['year'], [2024, 2025], true)
                || ! is_int($probabilities['race_id']) || $probabilities['race_id'] < 1
                || ! is_array($probabilities['entries']) || ! array_is_list($probabilities['entries'])
                || count($probabilities['entries']) < 5 || count($probabilities['entries']) > 9) {
                throw new RuntimeException('Invalid structure / race identity.');
            }
            $this->finite($prediction);
            $race = ['year' => $probabilities['year'], 'race_id' => $probabilities['race_id'], 'entries' => []];
            $sums = [new Bt03e03CompensatedSum, new Bt03e03CompensatedSum, new Bt03e03CompensatedSum];
            $ids = $bikes = [];
            foreach ($probabilities['entries'] as $entry) {
                $this->keys($entry, ['id', 'bike', 'raw', 'stat01_rank', 'anchor',
                    'position_1_probability', 'position_2_probability', 'position_3_probability',
                    'position_1_log_probability', 'position_2_log_probability', 'position_3_log_probability',
                    'top2_probability', 'top3_probability', 'predicted_position', 'is_map_top3',
                    'map_ordered_top3', 'map_ordered_probability', 'map_top3_set', 'map_top3_set_probability',
                    'map_tie_diagnostics', 'utilities']);
                if (! is_int($entry['id']) || $entry['id'] < 1 || in_array($entry['id'], $ids, true)
                    || ! is_int($entry['bike']) || $entry['bike'] < 1 || $entry['bike'] > 9 || in_array($entry['bike'], $bikes, true)) {
                    throw new RuntimeException('Invalid entrants / entry identity.');
                }
                $ids[] = $entry['id'];
                $bikes[] = $entry['bike'];
                foreach (['raw', 'anchor'] as $field) {
                    $this->number($entry[$field]);
                }
                if ($entry['stat01_rank'] !== null) {
                    $this->number($entry['stat01_rank']);
                }
                $this->keys($entry['utilities'], Bt03e03Contract::POSITIONS);
                foreach ($entry['utilities'] as $value) {
                    $this->number($value);
                }
                foreach ([1, 2, 3] as $position) {
                    $value = $entry['position_'.$position.'_probability'];
                    $this->number($value);
                    if ($value < 0 || $value > 1) {
                        throw new RuntimeException('Marginal outside [0,1].');
                    }
                    $sums[$position - 1]->add($value);
                }
                $top2 = $entry['position_1_probability'] + $entry['position_2_probability'];
                Files::same([$top2, $top2 + $entry['position_3_probability']],
                    [$entry['top2_probability'], $entry['top3_probability']], 'Top2 / Top3');
                // Project only the calculator input; the complete saved output is compared below.
                $race['entries'][] = ['id' => $entry['id'], 'bike' => $entry['bike'], 'raw' => $entry['raw'],
                    'stat01_rank' => $entry['stat01_rank'], 'anchor' => $entry['anchor'], 'utilities' => $entry['utilities']];
            }
            foreach ($sums as $sum) {
                if (abs($sum->value() - 1.0) > Bt03e03Contract::PROBABILITY_TOLERANCE) {
                    throw new RuntimeException('Actual marginal sum is not one.');
                }
            }
            $calculated = $this->calculator->predict($race);
            Files::same($calculated, $probabilities, 'probabilities / saved utilities');
            Files::same($this->decoder->decode($calculated), $prediction['decision'], 'decision / verified probabilities');
        } catch (RuntimeException $e) {
            throw new RuntimeException('Saved prediction validation failed: '.$e->getMessage(), 0, $e);
        }
    }

    private function keys(mixed $value, array $expected): void
    {
        if (! is_array($value) || array_diff(array_keys($value), $expected) !== [] || array_diff($expected, array_keys($value)) !== []) {
            throw new RuntimeException('Missing / unknown field or invalid object.');
        }
    }

    private function number(mixed $value): void
    {
        if ((! is_int($value) && ! is_float($value)) || ! is_finite($value)) {
            throw new RuntimeException('Non-numeric or non-finite value.');
        }
    }

    private function finite(array $row): void
    {
        foreach ($row as $value) {
            if (is_float($value) && ! is_finite($value)) {
                throw new RuntimeException('Non-finite saved value.');
            }
            if (is_array($value)) {
                $this->finite($value);
            }
        }
    }
}
