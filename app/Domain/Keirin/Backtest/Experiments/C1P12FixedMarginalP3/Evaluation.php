<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\C1P12FixedMarginalP3;

use App\Domain\Keirin\Backtest\Experiments\C1MarginalP23Decoder\Evaluation as Shared;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\JsonlArtifact;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use RuntimeException;

final class Evaluation
{
    public function __construct(private readonly Shared $shared) {}

    public function evaluate(array $source, array $paths, string $directory): array
    {
        $audit = [];
        foreach ([2024, 2025] as $year) {
            $audit[$year] = ['races_checked' => 0, 'P1_P2_contributions_identical' => true,
                'Hit3_eligible_races' => 0, 'Hit3_eligible_P3_changed_races' => 0,
                'eligible_P3_C1_hits' => 0, 'eligible_P3_candidate_hits' => 0,
                'Hit3_position_delta' => 0, 'eligible_P3_hit_delta' => 0];
        }
        $result = $this->shared->evaluate($source, $paths, $directory,
            function (array $context, array $row, array $comparisons) use (&$audit): void {
                $this->check($row, $comparisons['CANDIDATE-C1'], $audit[$context['year']]);
            });
        foreach ($audit as $year => $data) {
            if ($data['Hit3_position_delta'] !== $data['eligible_P3_hit_delta']) {
                throw new RuntimeException('Aggregate Hit3/P3 eligible delta mismatch.');
            }
            foreach (['WINNER_HIT_AT_1', 'POSITION_2_ACCURACY'] as $metric) {
                if ($result['outer']['CANDIDATE-C1'][$year]['delta'][$metric] !== 0.0
                    || $result['intervals']['CANDIDATE-C1'][$metric] !== ['ci_lower' => 0.0, 'ci_upper' => 0.0]) {
                    throw new RuntimeException('Measured fixed-position aggregate/CI mismatch.');
                }
            }
        }
        JsonlArtifact::json($directory.'/fixed-position-evaluation.json', $audit);

        return $result + ['fixed_evaluation_invariants' => $audit];
    }

    public function check(array $row, array $comparison, array &$audit): void
    {
        foreach ([1, 2] as $position) {
            Files::same([$row['baseline']['primary_position_'.$position.'_bike']],
                [$row['candidate']['primary_position_'.$position.'_bike']], 'fixed prediction P'.$position);
            $metric = $position === 1 ? 'WINNER_HIT_AT_1' : 'POSITION_2_ACCURACY';
            Files::same($comparison['baseline'][$metric], $comparison['candidate'][$metric], 'fixed contribution P'.$position);
        }
        $a = $comparison['baseline']['POSITION_HIT_RATE_AT_3'];
        $b = $comparison['candidate']['POSITION_HIT_RATE_AT_3'];
        if ($a['denominator'] !== $b['denominator']) {
            throw new RuntimeException('Hit3 paired denominator mismatch.');
        }
        $audit['races_checked']++;
        if ($a['denominator'] > 0.0) {
            $old = $comparison['baseline']['POSITION_3_ACCURACY'];
            $new = $comparison['candidate']['POSITION_3_ACCURACY'];
            if ($a['denominator'] !== 3.0 || $old['denominator'] !== 1.0 || $new['denominator'] !== 1.0
                || $b['numerator'] - $a['numerator'] !== $new['numerator'] - $old['numerator']) {
                throw new RuntimeException('Per-race Hit3/P3 eligible delta mismatch.');
            }
            $audit['Hit3_eligible_races']++;
            $audit['Hit3_eligible_P3_changed_races'] += (int) ($row['baseline']['primary_position_3_bike'] !== $row['candidate']['primary_position_3_bike']);
            $audit['eligible_P3_C1_hits'] += (int) $old['numerator'];
            $audit['eligible_P3_candidate_hits'] += (int) $new['numerator'];
            $audit['Hit3_position_delta'] += (int) ($b['numerator'] - $a['numerator']);
            $audit['eligible_P3_hit_delta'] += (int) ($new['numerator'] - $old['numerator']);
        }
    }

    public function gates(array $result, bool $integrity): array
    {
        return $this->shared->gates($result, $integrity);
    }
}
