<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\C1P23NondecreasingMarginal;

use App\Domain\Keirin\Backtest\Experiments\C1MarginalP23Decoder\Evaluation as Shared;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\JsonlArtifact;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use RuntimeException;

final class Evaluation
{
    public function __construct(private readonly Shared $evaluation) {}

    public function evaluate(array $source, array $paths, string $directory): array
    {
        $audit = array_fill_keys([2024, 2025], array_fill_keys(['races_checked', 'Hit3_eligible_races',
            'eligible_P2_hit_delta', 'eligible_P3_hit_delta', 'Hit3_position_delta'], 0));
        $result = $this->evaluation->evaluate($source, $paths, $directory,
            function (array $context, array $row, array $comparisons) use (&$audit): void {
                $this->check($row, $comparisons['CANDIDATE-C1'], $audit[$context['year']]);
            });
        foreach ([2024, 2025] as $year) {
            if ($audit[$year]['Hit3_position_delta'] !== $audit[$year]['eligible_P2_hit_delta'] + $audit[$year]['eligible_P3_hit_delta']) {
                throw new RuntimeException('Hit3 eligible component total disagreed.');
            }
            $outer = $result['outer']['CANDIDATE-C1'][$year];
            foreach (['WINNER_HIT_AT_1', 'POSITION_1_ACCURACY'] as $metric) {
                Files::same([$outer['baseline_numerators'][$metric], $outer['baseline'][$metric], 0.0],
                    [$outer['candidate_numerators'][$metric], $outer['candidate'][$metric], $outer['delta'][$metric]], 'annual fixed P1');
            }
        }
        foreach (['WINNER_HIT_AT_1', 'POSITION_1_ACCURACY'] as $metric) {
            Files::same(['ci_lower' => 0.0, 'ci_upper' => 0.0], $result['intervals']['CANDIDATE-C1'][$metric], 'computed fixed P1 CI');
        }
        JsonlArtifact::json($directory.'/fixed-position-evaluation.json', $audit);

        return $result + ['fixed_evaluation_invariants' => $audit];
    }

    public function check(array $row, array $comparison, array &$audit): void
    {
        Files::same([$row['baseline']['primary_position_1_bike']], [$row['candidate']['primary_position_1_bike']], 'fixed P1 prediction');
        foreach (['WINNER_HIT_AT_1', 'POSITION_1_ACCURACY'] as $metric) {
            Files::same($comparison['baseline'][$metric], $comparison['candidate'][$metric], 'fixed contribution P1');
        }
        $a = $comparison['baseline']['POSITION_HIT_RATE_AT_3'];
        $b = $comparison['candidate']['POSITION_HIT_RATE_AT_3'];
        Files::same([$a['denominator']], [$b['denominator']], 'Hit3 paired denominator');
        $audit['races_checked']++;
        if ($a['denominator'] === 0.0) {
            return;
        }
        if ($a['denominator'] !== 3.0) {
            throw new RuntimeException('Invalid Hit3 eligible denominator.');
        }
        $delta = 0;
        foreach ([2, 3] as $position) {
            $metric = 'POSITION_'.$position.'_ACCURACY';
            if ($comparison['baseline'][$metric]['denominator'] !== 1.0 || $comparison['candidate'][$metric]['denominator'] !== 1.0) {
                throw new RuntimeException('Invalid eligible position denominator.');
            }
            $part = (int) ($comparison['candidate'][$metric]['numerator'] - $comparison['baseline'][$metric]['numerator']);
            $audit['eligible_P'.$position.'_hit_delta'] += $part;
            $delta += $part;
        }
        if ((float) $delta !== $b['numerator'] - $a['numerator']) {
            throw new RuntimeException('Hit3 position delta disagreed with eligible P2/P3.');
        }
        $audit['Hit3_eligible_races']++;
        $audit['Hit3_position_delta'] += $delta;
    }

    public function gates(array $result, bool $integrity): array
    {
        return $this->evaluation->gates($result, $integrity);
    }
}
